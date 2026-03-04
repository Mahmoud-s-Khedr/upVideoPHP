#!/usr/bin/env php
<?php

/**
 * Stale Job Reaper — independent Supervisor process (C4).
 *
 * Runs continuously and resets encoding_jobs that have been stuck in
 * 'claimed' status beyond the configured timeout threshold.
 *
 * This process is intentionally separate from bin/worker.php so that
 * it keeps running even when all encode workers are down (e.g., after
 * repeated OOM kills from a corrupt source file).
 *
 * Supervisor config: see deploy/supervisor/job-reaper.conf
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

$sleepSeconds       = 300; // check every 5 minutes
$threshold          = Config::staleJobTimeoutMinutes();
$pendingUploadTtl   = Config::pendingUploadTtlMinutes();

echo sprintf(
    "[reaper] Starting. Stale job threshold: %d min. Pending upload TTL: %d min. Check interval: %ds\n",
    $threshold, $pendingUploadTtl, $sleepSeconds
);

while (true) {
    try {
        $stmt = Connection::execute(
            "UPDATE encoding_jobs
             SET    status     = 'queued',
                    worker_pid = NULL,
                    claimed_at = NULL,
                    last_error = CONCAT(
                        IFNULL(last_error, ''),
                        '\n[reaper] Reset stale claim at ',
                        NOW()
                    )
             WHERE  status     = 'claimed'
               AND  claimed_at < NOW() - INTERVAL :minutes MINUTE",
            [':minutes' => $threshold]
        );

        $resetCount = $stmt->rowCount();

        if ($resetCount > 0) {
            echo sprintf("[reaper] Reset %d stale job(s) at %s\n", $resetCount, date('Y-m-d H:i:s'));
        }

        // ── Expire abandoned pending uploads ─────────────────────────────────
        // If /upload/init was called but the client never PUT the file to
        // storage (network error, wrong MinIO host, closed tab, etc.) the
        // video row stays 'pending' indefinitely. Once the presigned URL TTL
        // has elapsed the upload can never complete, so mark it as an error.
        // Guard: only touch rows that have no encoding_jobs entry — a row
        // with a job is either legitimately queued or already processing.
        $expiredStmt = Connection::execute(
            "UPDATE videos
             SET    status        = 'error',
                    error_message = '[reaper] Upload never completed — presigned URL expired'
             WHERE  status        = 'pending'
               AND  created_at   < NOW() - INTERVAL :minutes MINUTE
               AND  NOT EXISTS (
                       SELECT 1 FROM encoding_jobs WHERE video_id = videos.id
                    )",
            [':minutes' => $pendingUploadTtl]
        );

        $expiredCount = $expiredStmt->rowCount();

        if ($expiredCount > 0) {
            echo sprintf(
                "[reaper] Expired %d abandoned pending upload(s) at %s\n",
                $expiredCount,
                date('Y-m-d H:i:s')
            );
        }
    } catch (\Throwable $e) {
        // Log but don't crash — next iteration will retry
        echo sprintf("[reaper] ERROR: %s\n", $e->getMessage());
        // Reset PDO connection on DB errors
        Connection::reset();
    }

    sleep($sleepSeconds);
}
