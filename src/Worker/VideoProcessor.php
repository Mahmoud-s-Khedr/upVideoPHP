<?php

declare(strict_types=1);

namespace VideoSystem\Worker;

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Encoding\AudioTrackExtractor;
use VideoSystem\Encoding\CancelledException;
use VideoSystem\Encoding\EncodingException;
use VideoSystem\Encoding\FfprobeAnalyzer;
use VideoSystem\Encoding\KeyInfoFile;
use VideoSystem\Encoding\MasterPlaylistBuilder;
use VideoSystem\Encoding\ProgressTracker;
use VideoSystem\Encoding\RenditionPipeline;
use VideoSystem\Encoding\SubtitleExtractor;
use VideoSystem\Encoding\ThumbnailGenerator;
use VideoSystem\Queue\JobQueue;
use VideoSystem\Storage\B2Client;
use VideoSystem\Worker\CrashRecovery;
use VideoSystem\Worker\ShutdownFlag;

/**
 * Orchestrates the full encoding pipeline for a single video job.
 *
 * Steps (matching the architecture spec):
 *   1.  Copy incoming file to processing dir
 *   2.  ffprobe analysis
 *   3.  Subtitle extraction
 *   4.  Thumbnail generation
 *   5.  Audio track extraction (HLS stream copy — fast, no re-encode)
 *   6.  Upload original to B2  ← EARLY WATCHABILITY MILESTONE
 *          (subtitles, thumbnails, audio all in B2 before this point)
 *   7.  AES-128 key generation
 *   8.  Per-rendition HLS encoding + B2 upload
 *   9.  Key cleanup
 *  10.  Master playlist generation + B2 upload
 *  11.  Delete B2 original (original_deleted_at set)
 *  12.  Delete local processing + incoming dirs + mark video 'ready'
 */
final class VideoProcessor
{
    /**
     * @param array{id: int, video_id: int} $job
     * @throws EncodingException
     * @throws CancelledException
     * @throws \RuntimeException
     */
    public function process(array $job): void
    {
        $jobId   = $job['id'];
        $videoId = $job['video_id'];

        $video = Connection::fetch('SELECT * FROM videos WHERE id = :id', [':id' => $videoId]);
        if ($video === null) {
            throw new \RuntimeException("Video {$videoId} not found.");
        }

        $uuid          = $video['uuid'];
        $processingDir = Config::workDir() . '/processing/' . $jobId;
        $incomingDir   = Config::workDir() . '/incoming/' . $uuid;

        // Mark video as processing
        Connection::execute(
            "UPDATE videos SET status = 'processing' WHERE id = :id",
            [':id' => $videoId]
        );
        JobQueue::setStage($jobId, 'probing');

        // -------------------------------------------------------------------
        // Step 1: Copy from incoming to processing
        // -------------------------------------------------------------------
        @mkdir($processingDir, 0750, recursive: true);

        $originalFile = $this->findOriginalFile($incomingDir);
        if ($originalFile === null) {
            throw new \RuntimeException("No original file found in: {$incomingDir}");
        }

        $ext            = pathinfo($originalFile, PATHINFO_EXTENSION);
        $processingFile = $processingDir . '/original.' . $ext;

        if (!copy($originalFile, $processingFile)) {
            throw new \RuntimeException("Failed to copy original file to processing dir.");
        }

        // -------------------------------------------------------------------
        // Step 2: ffprobe analysis (required before all extraction steps)
        // -------------------------------------------------------------------
        $probe = FfprobeAnalyzer::analyze($processingFile);

        Connection::execute(
            'UPDATE videos SET duration_sec = :dur WHERE id = :id',
            [':dur' => (int) ceil($probe['duration']), ':id' => $videoId]
        );
        JobQueue::setStage($jobId, 'extracting_subtitles');

        // -------------------------------------------------------------------
        // Step 3: Subtitle extraction
        // -------------------------------------------------------------------
        $subtitleExtractor = new SubtitleExtractor($videoId, $uuid, $processingFile, $processingDir);
        $subtitleWarnings  = $subtitleExtractor->extractAll($probe['subtitle_tracks']);

        if (!empty($subtitleWarnings)) {
            $warningText = implode("\n", $subtitleWarnings);
            Connection::execute(
                'UPDATE encoding_jobs SET last_error = CONCAT(IFNULL(last_error, \'\'), \'\n\', :warn) WHERE id = :id',
                [':warn' => $warningText, ':id' => $jobId]
            );
        }
        JobQueue::setStage($jobId, 'generating_thumbnails');

        // -------------------------------------------------------------------
        // Step 4: Thumbnail generation
        // -------------------------------------------------------------------
        $thumbGen = new ThumbnailGenerator($videoId, $uuid, $processingFile, $processingDir, $probe['duration']);
        $thumbGen->generate();
        JobQueue::setStage($jobId, 'extracting_audio');

        // -------------------------------------------------------------------
        // Step 5: Audio track extraction (HLS stream copy — fast, no re-encode)
        // -------------------------------------------------------------------
        $audioExtractor = new AudioTrackExtractor($videoId, $uuid, $processingFile, $processingDir);
        $audioWarnings  = $audioExtractor->extractAll($probe['audio_tracks']);

        if (!empty($audioWarnings)) {
            $warningText = implode("\n", $audioWarnings);
            Connection::execute(
                'UPDATE encoding_jobs SET last_error = CONCAT(IFNULL(last_error, \'\'), \'\n\', :warn) WHERE id = :id',
                [':warn' => $warningText, ':id' => $jobId]
            );
        }
        JobQueue::setStage($jobId, 'uploading_original');

        // -------------------------------------------------------------------
        // Step 6: Upload original to B2 — EARLY WATCHABILITY MILESTONE
        //
        // Subtitles (.vtt), thumbnails (poster/sprite), and audio-only HLS
        // playlists are already in B2. The moment original_b2_key is set,
        // GET /api/videos/{uuid}/original returns a presigned video URL plus
        // all track metadata — the user can watch at full original quality
        // with correct audio and subtitles even if HLS encoding never completes.
        // -------------------------------------------------------------------
        if ($video['original_b2_key'] === null) {
            $b2OriginalKey = "videos/{$uuid}/original.{$ext}";

            Connection::execute(
                "UPDATE videos SET status = 'uploading' WHERE id = :id",
                [':id' => $videoId]
            );

            $mimeTypes = [
                'mp4'  => 'video/mp4',
                'mkv'  => 'video/x-matroska',
                'ts'   => 'video/mp2t',
                'avi'  => 'video/x-msvideo',
                'mov'  => 'video/quicktime',
                'webm' => 'video/webm',
            ];
            $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';

            B2Client::put($b2OriginalKey, $processingFile, $contentType);

            Connection::execute(
                "UPDATE videos SET original_b2_key = :key, status = 'processing' WHERE id = :id",
                [':key' => $b2OriginalKey, ':id' => $videoId]
            );
        }

        // -------------------------------------------------------------------
        // Step 7: AES-128 key generation
        // -------------------------------------------------------------------
        $keyInfo     = new KeyInfoFile($videoId, $processingDir);
        $keyInfoPath = $keyInfo->create();

        // -------------------------------------------------------------------
        // Step 8: Per-rendition HLS encoding
        // -------------------------------------------------------------------
        // Decode admin-selected quality labels (null = all applicable rungs)
        $selectedLabels = [];
        if (!empty($video['target_qualities'])) {
            $decoded = json_decode((string) $video['target_qualities'], true);
            if (is_array($decoded)) {
                $selectedLabels = array_values(array_filter($decoded, 'is_string'));
            }
        }

        // Determine applicable renditions for progress weighting
        $tempPipeline     = new RenditionPipeline(
            jobId:           $jobId,
            videoId:         $videoId,
            videoUuid:       $uuid,
            processingDir:   $processingDir,
            keyInfoPath:     $keyInfoPath,
            durationSec:     $probe['duration'],
            sourceHeight:    $probe['height'],
            audioTrackCount: count($probe['audio_tracks']),
            progress:        new ProgressTracker($jobId, []), // dummy for label discovery
            selectedLabels:  $selectedLabels,
        );
        $applicableLabels = $tempPipeline->getApplicableLabels();

        $progress = new ProgressTracker($jobId, $applicableLabels);

        $pipeline = new RenditionPipeline(
            jobId:           $jobId,
            videoId:         $videoId,
            videoUuid:       $uuid,
            processingDir:   $processingDir,
            keyInfoPath:     $keyInfoPath,
            durationSec:     $probe['duration'],
            sourceHeight:    $probe['height'],
            audioTrackCount: count($probe['audio_tracks']),
            progress:        $progress,
            selectedLabels:  $selectedLabels,
        );

        JobQueue::setStage($jobId, 'encoding');
        $completedLabels = $pipeline->encodeAll();

        // If shutdown was requested, we stop here — reaper will reset the job
        if (ShutdownFlag::isRequested()) {
            return;
        }

        // -------------------------------------------------------------------
        // Step 9/10: Final master publication + cleanup
        // -------------------------------------------------------------------
        JobQueue::setStage($jobId, 'publishing_master');
        $masterBuilder = new MasterPlaylistBuilder($videoId, $uuid);
        $masterBuilder->build($processingDir, $completedLabels);

        JobQueue::setStage($jobId, 'cleaning_up');
        $keyInfo->cleanup();

        // -------------------------------------------------------------------
        // Step 11: Delete B2 original (HLS renditions are now ready)
        // -------------------------------------------------------------------
        $videoRefreshed = Connection::fetch('SELECT original_b2_key FROM videos WHERE id = :id', [':id' => $videoId]);
        if ($videoRefreshed !== null && $videoRefreshed['original_b2_key'] !== null) {
            B2Client::delete($videoRefreshed['original_b2_key']);
            Connection::execute(
                'UPDATE videos SET original_deleted_at = NOW() WHERE id = :id',
                [':id' => $videoId]
            );
        }

        // -------------------------------------------------------------------
        // Step 12: Delete local files + mark ready
        // -------------------------------------------------------------------
        CrashRecovery::deleteDirectory($processingDir);
        CrashRecovery::deleteDirectory($incomingDir);

        JobQueue::markDone($jobId);
        Connection::execute(
            "UPDATE videos SET status = 'ready' WHERE id = :id",
            [':id' => $videoId]
        );

        echo "[worker] Job {$jobId} (video {$uuid}) completed successfully.\n";
    }

    private function findOriginalFile(string $dir): ?string
    {
        foreach (['mp4', 'mkv', 'ts', 'avi', 'mov', 'webm'] as $ext) {
            $path = $dir . '/original.' . $ext;
            if (file_exists($path)) {
                return $path;
            }
        }
        return null;
    }
}
