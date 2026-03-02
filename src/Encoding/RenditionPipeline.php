<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Queue\JobQueue;
use VideoSystem\Storage\B2Client;
use VideoSystem\Worker\ShutdownFlag;

/**
 * Encodes all applicable renditions for a video, one at a time.
 *
 * Each rendition runs in its own proc_open() call so we can:
 *   - Capture stderr line-by-line for progress parsing
 *   - Terminate cleanly on cancellation
 *   - Retry individual renditions rather than the full job
 *
 * Skips renditions where the source height is below the target (no upscaling).
 * Checks cancel_requested between renditions (C3).
 * Deletes partial rendition output before any retry (C5).
 */
final class RenditionPipeline
{
    private const RENDITION_LADDER = [
        '1080p' => ['width' => 1920, 'height' => 1080, 'crf' => 24, 'vbitrate' => '4000k', 'abitrate' => '192k'],
        '720p'  => ['width' => 1280, 'height' => 720,  'crf' => 25, 'vbitrate' => '2500k', 'abitrate' => '128k'],
        '540p'  => ['width' => 960,  'height' => 540,  'crf' => 26, 'vbitrate' => '1800k', 'abitrate' => '128k'],
        '480p'  => ['width' => 854,  'height' => 480,  'crf' => 26, 'vbitrate' => '1200k', 'abitrate' => '128k'],
        '360p'  => ['width' => 640,  'height' => 360,  'crf' => 28, 'vbitrate' => '600k',  'abitrate' => '96k'],
    ];

    public function __construct(
        private readonly int    $jobId,
        private readonly int    $videoId,
        private readonly string $videoUuid,
        private readonly string $processingDir,
        private readonly string $keyInfoPath,
        private readonly float  $durationSec,
        private readonly int    $sourceHeight,
        private readonly int    $audioTrackCount,
        private readonly ProgressTracker $progress,
        private readonly array  $selectedLabels = [],
    ) {}

    /**
     * Override the FFmpeg encode step in tests.
     *
     * Callable signature: function(string $label, string $renditionDir, string $processingDir): void
     *
     * Should create fake playlist / segment files in $renditionDir when simulating
     * success, or throw EncodingException to simulate failure.
     */
    private static mixed $testEncodeRenditionFn = null;

    public static function setTestEncodeRenditionFn(?callable $fn): void
    {
        self::$testEncodeRenditionFn = $fn;
    }

    /**
     * Run all applicable renditions.
     *
     * @return list<string>  Labels of successfully encoded renditions
     * @throws \RuntimeException if a rendition fails and is non-retryable
     */
    public function encodeAll(): array
    {
        $applicableLabels = $this->getApplicableLabels();
        $completed        = [];

        foreach ($applicableLabels as $label) {
            // Cooperative cancellation check (C3)
            if (JobQueue::isCancelRequested($this->jobId)) {
                throw new CancelledException("Job {$this->jobId} cancelled by request.");
            }

            // Check if this rendition is already done in B2 (resume after graceful shutdown)
            $b2IndexKey = "videos/{$this->videoUuid}/{$label}/index.m3u8";
            if (B2Client::exists($b2IndexKey)) {
                echo "[worker] Skipping {$label} — already uploaded to B2.\n";
                $this->progress->renditionComplete($label);
                $completed[] = $label;
                continue;
            }

            // Clean up any partial local output from a previous crashed run (C5)
            $renditionDir = $this->processingDir . '/' . $label;
            $this->cleanPartialOutput($renditionDir);

            Connection::execute(
                'UPDATE encoding_jobs SET current_rendition = :label WHERE id = :id',
                [':label' => $label, ':id' => $this->jobId]
            );

            $this->encodeRendition($label, $renditionDir);

            // Upload this rendition's output to B2 immediately
            $this->uploadRenditionToB2($label, $renditionDir);
            $this->recordRenditionInDb($label);

            $this->progress->renditionComplete($label);
            $completed[] = $label;

            // Graceful shutdown: finish this rendition's upload then stop (Section 5.5)
            if (ShutdownFlag::isRequested()) {
                echo "[worker] Shutdown requested; stopping after {$label}.\n";
                break;
            }
        }

        return $completed;
    }

    /**
     * Returns the rendition labels applicable for this source.
     *
     * No upscaling: only labels where sourceHeight >= targetHeight are kept.
     * When $selectedLabels is non-empty, further intersects with that list
     * (admin-chosen quality selection per video).
     *
     * @return list<string>
     */
    public function getApplicableLabels(): array
    {
        $labels = [];
        foreach (self::RENDITION_LADDER as $label => $params) {
            if ($this->sourceHeight >= $params['height']) {
                $labels[] = $label;
            }
        }

        // Intersect with admin-chosen selection (preserves ladder order)
        if (!empty($this->selectedLabels)) {
            $labels = array_values(
                array_filter($labels, fn(string $l) => in_array($l, $this->selectedLabels, true))
            );
        }

        return $labels;
    }

    // -------------------------------------------------------------------------
    // Single-rendition encode
    // -------------------------------------------------------------------------

    private function encodeRendition(string $label, string $renditionDir): void
    {
        if (!@mkdir($renditionDir, 0750, recursive: true) && !is_dir($renditionDir)) {
            throw new \RuntimeException("Cannot create rendition dir: {$renditionDir}");
        }

        // --- test seam ---
        if (self::$testEncodeRenditionFn !== null) {
            (self::$testEncodeRenditionFn)($label, $renditionDir, $this->processingDir);
            return;
        }

        $params  = self::RENDITION_LADDER[$label];
        $cmd     = $this->buildFfmpegCommand($label, $params, $renditionDir);
        $durationSec = $this->durationSec > 0 ? $this->durationSec : 1.0;

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if ($process === false) {
            throw new \RuntimeException("proc_open failed for rendition {$label}.");
        }

        fclose($pipes[0]);
        fclose($pipes[1]);

        // Read stderr line-by-line for progress
        stream_set_blocking($pipes[2], false);
        $stderr = '';

        while (true) {
            $line = fgets($pipes[2]);
            if ($line !== false) {
                $stderr .= $line;
                // Parse "time=HH:MM:SS.ms" from FFmpeg output
                if (preg_match('/time=(\d+):(\d+):([\d.]+)/', $line, $m)) {
                    $elapsed = (int)$m[1] * 3600 + (int)$m[2] * 60 + (float)$m[3];
                    $pct     = min(99.0, ($elapsed / $durationSec) * 100.0);
                    $this->progress->update($label, $pct);
                }
            }

            if (feof($pipes[2]) && $line === false) {
                break;
            }
        }

        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            $this->cleanPartialOutput($renditionDir);
            $truncatedStderr = mb_substr($stderr, -4096);
            throw new EncodingException(
                "FFmpeg exited with code {$exitCode} for rendition {$label}. Last stderr: {$truncatedStderr}",
                nonRetryable: str_contains($stderr, 'Invalid data') || str_contains($stderr, 'moov atom not found')
            );
        }
    }

    private function buildFfmpegCommand(string $label, array $params, string $renditionDir): string
    {
        $inputPath    = $this->processingDir . '/original.' . $this->detectExtension();
        $segmentFile  = $renditionDir . '/seg%05d.ts';
        $playlistFile = $renditionDir . '/index.m3u8';

        $audioMapArgs = '';
        if ($this->audioTrackCount > 0) {
            for ($i = 0; $i < $this->audioTrackCount; $i++) {
                $audioMapArgs .= ' -map 0:a:' . $i;
            }
        }

        $audioCodecArgs = $this->audioTrackCount > 0
            ? ['-c:a aac', '-b:a', $params['abitrate'], '-ar 48000', '-ac 2']
            : [];

        return implode(' ', array_filter([
            escapeshellarg(Config::ffmpegBin()),
            '-y',
            '-i', escapeshellarg($inputPath),
            '-vf', escapeshellarg(sprintf(
                'scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2',
                $params['width'], $params['height'],
                $params['width'], $params['height']
            )),
            '-c:v libx264',
            '-crf', $params['crf'],
            '-preset veryfast',
            '-map 0:v:0',
            $audioMapArgs,
            implode(' ', $audioCodecArgs),
            '-hls_time 6',
            '-hls_playlist_type vod',
            '-hls_flags independent_segments',
            '-hls_segment_filename', escapeshellarg($segmentFile),
            '-hls_key_info_file', escapeshellarg($this->keyInfoPath),
            escapeshellarg($playlistFile),
            '2>&1',
        ]));
    }

    private function detectExtension(): string
    {
        foreach (['mp4', 'mkv', 'ts', 'avi', 'mov', 'webm'] as $ext) {
            if (file_exists($this->processingDir . '/original.' . $ext)) {
                return $ext;
            }
        }
        throw new \RuntimeException('No original file found in: ' . $this->processingDir);
    }

    private function cleanPartialOutput(string $renditionDir): void
    {
        if (!is_dir($renditionDir)) {
            return;
        }
        foreach (glob($renditionDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($renditionDir);
    }

    private function uploadRenditionToB2(string $label, string $renditionDir): void
    {
        $prefix = "videos/{$this->videoUuid}/{$label}/";

        // Upload all .ts segments
        foreach (glob($renditionDir . '/seg*.ts') ?: [] as $segFile) {
            $key = $prefix . basename($segFile);
            B2Client::put($key, $segFile, 'video/MP2T');
        }

        // Upload rendition playlist
        $playlistFile = $renditionDir . '/index.m3u8';
        if (file_exists($playlistFile)) {
            B2Client::put($prefix . 'index.m3u8', $playlistFile, 'application/x-mpegURL');
        }
    }

    private function recordRenditionInDb(string $label): void
    {
        $params = self::RENDITION_LADDER[$label];
        Connection::execute(
            'INSERT INTO renditions (video_id, label, width, height, bitrate_kbps, b2_key_prefix)
             VALUES (:vid, :label, :w, :h, :bps, :prefix)',
            [
                ':vid'    => $this->videoId,
                ':label'  => $label,
                ':w'      => $params['width'],
                ':h'      => $params['height'],
                ':bps'    => (int) rtrim($params['vbitrate'], 'k'),
                ':prefix' => "videos/{$this->videoUuid}/{$label}/",
            ]
        );
    }
}
