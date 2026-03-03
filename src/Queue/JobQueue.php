<?php

declare(strict_types=1);

namespace VideoSystem\Queue;

use VideoSystem\Database\Connection;

/**
 * Database-backed poll queue for encoding jobs.
 *
 * Uses SELECT ... FOR UPDATE SKIP LOCKED for atomic multi-worker claim.
 * Requires MySQL 8.0+ or MariaDB 10.6+.
 *
 * Retry delay schedule:
 *   attempt 1 → immediate
 *   attempt 2 → +60 seconds
 *   attempt 3 → +300 seconds
 *   attempt 4+ → terminal 'failed'
 */
final class JobQueue
{
    private const RETRY_DELAYS = [
        1 => 0,
        2 => 60,
        3 => 300,
    ];

    private const STAGE_PROGRESS = [
        'queued'               => 0,
        'probing'              => 5,
        'extracting_subtitles' => 10,
        'generating_thumbnails'=> 15,
        'extracting_audio'     => 20,
        'uploading_original'   => 30,
        'publishing_master'    => 97,
        'cleaning_up'          => 99,
        'done'                 => 100,
    ];

    private const ENCODING_MIN_PROGRESS = 30;
    private const ENCODING_MAX_PROGRESS = 95;

    /**
     * Atomically claim the next available job.
     *
     * @param int $workerPid PID of the claiming worker process
     * @return array{id: int, video_id: int}|null  The claimed job row, or null if none available
     */
    public static function claim(int $workerPid): ?array
    {
        $pdo = Connection::get();

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'SELECT id, video_id
                 FROM   encoding_jobs
                 WHERE  status       = \'queued\'
                   AND  attempts     < max_attempts
                   AND  (retry_after IS NULL OR retry_after <= NOW())
                 ORDER  BY id ASC
                 LIMIT  1
                 FOR UPDATE SKIP LOCKED'
            );
            $stmt->execute();
            $row = $stmt->fetch();

            if ($row === false) {
                $pdo->commit();
                return null;
            }

            $pdo->prepare(
                'UPDATE encoding_jobs
                 SET    status     = \'claimed\',
                        worker_pid = :pid,
                        claimed_at = NOW(),
                        attempts   = attempts + 1
                 WHERE  id = :id'
            )->execute([':pid' => $workerPid, ':id' => $row['id']]);

            $pdo->commit();
            return ['id' => (int) $row['id'], 'video_id' => (int) $row['video_id']];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mark a job as successfully completed.
     */
    public static function markDone(int $jobId): void
    {
        Connection::execute(
            'UPDATE encoding_jobs
             SET    status     = \'done\',
                    worker_pid = NULL,
                    progress_pct = 100,
                    current_stage = \'done\',
                    current_rendition = NULL
             WHERE  id = :id',
            [':id' => $jobId]
        );
    }

    /**
     * Mark a job as permanently failed (no more retries).
     */
    public static function markFailed(int $jobId, string $error): void
    {
        Connection::execute(
            'UPDATE encoding_jobs
             SET    status     = \'failed\',
                    worker_pid = NULL,
                    last_error = :error,
                    current_stage = \'failed\'
             WHERE  id = :id',
            [':error' => mb_substr($error, 0, 65535), ':id' => $jobId]
        );
    }

    /**
     * Re-queue a job for retry after the appropriate delay.
     * If max_attempts is already reached, marks it failed instead.
     *
     * @param int    $jobId
     * @param string $error  Error message to append to last_error
     */
    public static function requeueForRetry(int $jobId, string $error): void
    {
        $job = Connection::fetch(
            'SELECT attempts, max_attempts FROM encoding_jobs WHERE id = :id',
            [':id' => $jobId]
        );

        if ($job === null) {
            return;
        }

        $attempts    = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];

        if ($attempts >= $maxAttempts) {
            self::markFailed($jobId, $error);
            return;
        }

        $delaySec = self::RETRY_DELAYS[$attempts] ?? 300;

        Connection::execute(
            'UPDATE encoding_jobs
             SET    status     = \'queued\',
                    worker_pid = NULL,
                    claimed_at = NULL,
                    retry_after = IF(:delay_cmp > 0, NOW() + INTERVAL :delay_sec SECOND, NULL),
                    last_error  = CONCAT(IFNULL(last_error, \'\'), \'\n\', :error),
                    progress_pct = 0,
                    current_rendition = NULL,
                    current_stage = \'queued\'
             WHERE  id = :id',
            [
                ':delay_cmp' => $delaySec,
                ':delay_sec' => $delaySec,
                ':error'     => mb_substr($error, 0, 4096),
                ':id'        => $jobId,
            ]
        );
    }

    /**
     * Check whether cancel has been requested for a job.
     * Must be called between renditions (C3 — cooperative cancellation).
     */
    public static function isCancelRequested(int $jobId): bool
    {
        $row = Connection::fetch(
            'SELECT cancel_requested FROM encoding_jobs WHERE id = :id',
            [':id' => $jobId]
        );

        return $row !== null && (bool) $row['cancel_requested'];
    }

    /**
     * Request cancellation of a job (called by DELETE endpoint).
     * The worker checks this flag between renditions.
     */
    public static function requestCancel(int $jobId): void
    {
        Connection::execute(
            'UPDATE encoding_jobs SET cancel_requested = 1 WHERE id = :id',
            [':id' => $jobId]
        );
    }

    /**
     * Update progress columns (called by the worker every ~2 seconds during encoding).
     */
    public static function updateProgress(int $jobId, int $pct, string $renditionLabel): void
    {
        $normalizedPct = self::normalizeEncodingProgress($pct);

        Connection::execute(
            'UPDATE encoding_jobs
             SET    progress_pct      = :pct,
                    current_rendition = :label,
                    current_stage     = \'encoding\'
             WHERE  id = :id',
            [
                ':pct'   => $normalizedPct,
                ':label' => $renditionLabel !== '' ? $renditionLabel : null,
                ':id'    => $jobId,
            ]
        );
    }

    public static function setStage(int $jobId, string $stage, ?string $renditionLabel = null): void
    {
        if ($stage === 'encoding') {
            self::updateProgress($jobId, 0, $renditionLabel ?? '');
            return;
        }

        Connection::execute(
            'UPDATE encoding_jobs
             SET    current_stage = :stage,
                    progress_pct = :pct,
                    current_rendition = :label
             WHERE  id = :id',
            [
                ':stage' => $stage,
                ':pct'   => self::stageProgress($stage),
                ':label' => $renditionLabel,
                ':id'    => $jobId,
            ]
        );
    }

    public static function stageProgress(string $stage): int
    {
        if ($stage === 'encoding') {
            return self::ENCODING_MIN_PROGRESS;
        }

        return self::STAGE_PROGRESS[$stage] ?? 0;
    }

    public static function normalizeEncodingProgress(int $pct): int
    {
        $clamped = max(0, min(100, $pct));
        $range   = self::ENCODING_MAX_PROGRESS - self::ENCODING_MIN_PROGRESS;

        return self::ENCODING_MIN_PROGRESS + (int) round(($clamped / 100) * $range);
    }

    public static function fallbackStageForVideoStatus(string $status): string
    {
        return match ($status) {
            'ready'     => 'done',
            'error'     => 'failed',
            'uploading' => 'uploading_original',
            'processing'=> 'encoding',
            default     => 'queued',
        };
    }

    /**
     * Find the encoding_jobs row for a given video_id.
     *
     * @return array<string, mixed>|null
     */
    public static function findByVideoId(int $videoId): ?array
    {
        return Connection::fetch(
            'SELECT * FROM encoding_jobs WHERE video_id = :vid ORDER BY id DESC LIMIT 1',
            [':vid' => $videoId]
        );
    }
}
