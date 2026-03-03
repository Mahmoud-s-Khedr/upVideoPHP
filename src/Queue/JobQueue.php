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
                    progress_pct = 100
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
                    last_error = :error
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
                    last_error  = CONCAT(IFNULL(last_error, \'\'), \'\n\', :error)
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
        Connection::execute(
            'UPDATE encoding_jobs
             SET    progress_pct      = :pct,
                    current_rendition = :label
             WHERE  id = :id',
            [':pct' => max(0, min(100, $pct)), ':label' => $renditionLabel, ':id' => $jobId]
        );
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
