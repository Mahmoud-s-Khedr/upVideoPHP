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
use VideoSystem\Upload\FileValidator;
use VideoSystem\Upload\ValidationException;
use VideoSystem\Worker\CrashRecovery;
use VideoSystem\Worker\ShutdownFlag;

/**
 * Orchestrates the full encoding pipeline for a single video job.
 *
 * Steps (matching the architecture spec):
 *   1.  Download original from B2 to processing dir
 *   1b. Validate downloaded file (magic bytes + ffprobe)
 *   2.  (original already in B2 — no upload needed)
 *   3.  ffprobe analysis
 *   4.  Subtitle extraction
 *   5.  Thumbnail generation
 *   6.  Audio track extraction
 *   7.  AES-128 key generation
 *   8.  Per-rendition HLS encoding + B2 upload
 *   9.  Key cleanup
 *  10.  Master playlist generation + B2 upload
 *  11.  Delete B2 original (original_deleted_at set)
 *  12.  Delete local processing dir + mark video 'ready'
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

        // Mark video as processing
        Connection::execute(
            "UPDATE videos SET status = 'processing' WHERE id = :id",
            [':id' => $videoId]
        );

        // -------------------------------------------------------------------
        // Step 1: Download original from B2 to processing dir
        // -------------------------------------------------------------------
        @mkdir($processingDir, 0750, recursive: true);

        $b2Key = (string) ($video['original_b2_key'] ?? '');
        if ($b2Key === '') {
            throw new \RuntimeException("Video {$videoId} has no original_b2_key — cannot download.");
        }

        $ext            = strtolower(pathinfo($b2Key, PATHINFO_EXTENSION)) ?: 'mp4';
        $processingFile = $processingDir . '/original.' . $ext;

        JobQueue::setStage($jobId, 'downloading');
        B2Client::download($b2Key, $processingFile);

        // Guard: ensure the download produced a non-empty file.  If the
        // presigned PUT to storage failed silently the file either won't
        // exist or will be 0 bytes.  Fail fast and non-retryably so the
        // operator knows to re-upload rather than burning 3 retry attempts.
        $downloadedSize = file_exists($processingFile) ? filesize($processingFile) : false;
        if ($downloadedSize === false || $downloadedSize === 0) {
            throw new EncodingException(
                sprintf(
                    'Downloaded file is empty (0 bytes) for key "%s" — the original was not uploaded to storage correctly.',
                    $b2Key
                ),
                nonRetryable: true
            );
        }

        // -------------------------------------------------------------------
        // Step 1b: Validate the downloaded file (magic bytes + ffprobe)
        // -------------------------------------------------------------------
        $validator = new FileValidator(Config::ffprobeBin());
        // Passing type='' triggers the extension-based MIME allowlist path in
        // FileValidator (the $genericMime && $extensionAllowed branch). All
        // five validation stages operate correctly on the local file.
        $fileEntry = [
            'name'     => basename($processingFile),
            'type'     => '',
            'tmp_name' => $processingFile,
            'error'    => UPLOAD_ERR_OK,
            'size'     => (int) (filesize($processingFile) ?: 0),
        ];
        try {
            $validator->validate($fileEntry);
        } catch (ValidationException $e) {
            @unlink($processingFile);
            B2Client::delete($b2Key);
            Connection::execute(
                "UPDATE videos SET status = 'error', error_message = :msg WHERE id = :id",
                [':msg' => $e->getMessage(), ':id' => $videoId]
            );
            throw new EncodingException(
                'File validation failed: ' . $e->getMessage(),
                nonRetryable: true
            );
        }

        // -------------------------------------------------------------------
        // Step 2: Original already in B2 (uploaded by client via presigned URL).
        // original_b2_key was set during POST /api/upload/init — nothing to do.
        // -------------------------------------------------------------------
        JobQueue::setStage($jobId, 'probing');

        // -------------------------------------------------------------------
        // Step 3: ffprobe analysis (required before extraction and encoding)
        // -------------------------------------------------------------------
        $probe = FfprobeAnalyzer::analyze($processingFile);

        Connection::execute(
            'UPDATE videos SET duration_sec = :dur, source_height = :sh WHERE id = :id',
            [':dur' => (int) ceil($probe['duration']), ':sh' => $probe['height'], ':id' => $videoId]
        );
        JobQueue::setStage($jobId, 'extracting_subtitles');

        // -------------------------------------------------------------------
        // Step 4: Subtitle extraction
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
        // Step 5: Thumbnail generation
        // -------------------------------------------------------------------
        $thumbGen = new ThumbnailGenerator($videoId, $uuid, $processingFile, $processingDir, $probe['duration']);
        $thumbGen->generate();
        JobQueue::setStage($jobId, 'extracting_audio');

        // -------------------------------------------------------------------
        // Step 6: Audio track extraction
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
            sourceFps:       $probe['fps'],
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
            sourceFps:       $probe['fps'],
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
        // Step 12: Delete local processing dir + mark ready
        // -------------------------------------------------------------------
        CrashRecovery::deleteDirectory($processingDir);

        JobQueue::markDone($jobId);
        Connection::execute(
            "UPDATE videos SET status = 'ready' WHERE id = :id",
            [':id' => $videoId]
        );

        echo "[worker] Job {$jobId} (video {$uuid}) completed successfully.\n";
    }

}
