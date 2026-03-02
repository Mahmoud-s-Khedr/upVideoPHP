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

$sleepSeconds = 300; // check every 5 minutes
$threshold    = Config::staleJobTimeoutMinutes();

echo sprintf("[reaper] Starting. Stale threshold: %d minutes. Check interval: %ds\n", $threshold, $sleepSeconds);

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
    } catch (\Throwable $e) {
        // Log but don't crash — next iteration will retry
        echo sprintf("[reaper] ERROR: %s\n", $e->getMessage());
        // Reset PDO connection on DB errors
        Connection::reset();
    }

    sleep($sleepSeconds);
}
