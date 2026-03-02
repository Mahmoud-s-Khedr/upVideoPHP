<?php

declare(strict_types=1);

namespace VideoSystem\Worker;

use VideoSystem\Config\Config;
use VideoSystem\Encoding\KeyInfoFile;
use VideoSystem\Storage\B2Client;

/**
 * Handles crash recovery tasks that must run at worker startup and before each retry (C5).
 *
 * C5 recovery scenarios:
 *   1. FFmpeg killed mid-segment — partial .ts file remains; delete before retry
 *   2. Partial B2 multipart upload — orphaned parts accumulate cost; delete before retry
 *   3. Key files left on disk after crash — enc.keyinfo / enc_N.key still present
 */
final class CrashRecovery
{
    /**
     * Scan for and remove stale AES key files from a previous crashed run.
     * Call at worker startup before claiming a new job (C5).
     *
     * @param string $processingDir  The processing/{job_id}/ directory
     */
    public static function scanForStaleKeyFiles(string $processingDir): void
    {
        if (!is_dir($processingDir)) {
            return;
        }

        KeyInfoFile::cleanupStaleFiles($processingDir);
    }

    /**
     * Clean up orphaned partial B2 uploads before retrying a job (C5).
     *
     * Handles two kinds of partial uploads:
     *   - Video renditions:  videos/{uuid}/{label}/seg*.ts without index.m3u8
     *   - Audio tracks:      videos/{uuid}/audio_{n}/seg*.ts without index.m3u8
     *
     * The index.m3u8 file is always uploaded last and acts as the completion
     * marker; its absence signals an incomplete upload from a crashed run.
     *
     * @param string $videoUuid  The video UUID
     * @param string $jobId      The encoding job ID (for log messages)
     */
    public static function precleanB2(string $videoUuid, string $jobId): void
    {
        $prefix = "videos/{$videoUuid}/";
        $keys   = B2Client::listObjects($prefix);

        // Track which prefixes (renditions + audio dirs) have a completed playlist
        $completedPlaylists = [];
        // Segments grouped by their parent prefix (without trailing slash)
        $segmentsByPrefix   = [];

        foreach ($keys as $key) {
            // video rendition playlist: videos/{uuid}/720p/index.m3u8
            // audio track playlist:     videos/{uuid}/audio_0/index.m3u8
            if (preg_match('#^(videos/[^/]+/[^/]+)/index\.m3u8$#', $key, $m)) {
                $completedPlaylists[$m[1]] = true;
            }
            // video rendition segment: videos/{uuid}/720p/seg00001.ts
            // audio track segment:     videos/{uuid}/audio_0/seg00001.ts
            if (preg_match('#^(videos/[^/]+/[^/]+)/seg\d+\.ts$#', $key, $m)) {
                $segmentsByPrefix[$m[1]][] = $key;
            }
        }

        // Delete segments from any directory that has no completed playlist
        foreach ($segmentsByPrefix as $dirPrefix => $segKeys) {
            if (!isset($completedPlaylists[$dirPrefix])) {
                echo sprintf(
                    "[worker:%s] Pre-cleaning %d orphaned segments under %s/\n",
                    $jobId, count($segKeys), $dirPrefix
                );
                B2Client::deleteObjects($segKeys);
            }
        }
    }

    /**
     * Recursively delete a local directory.
     */
    public static function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }
}
