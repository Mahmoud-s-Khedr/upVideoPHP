#!/usr/bin/env php
<?php

/**
 * Video Encoding Worker — long-lived CLI process managed by Supervisor.
 *
 * Implements:
 *   - Disk space check before claiming jobs
 *   - Atomic job claiming via SELECT FOR UPDATE SKIP LOCKED
 *   - Graceful shutdown on SIGTERM/SIGINT (C3 / Section 5.5)
 *   - Cooperative cancellation via cancel_requested flag
 *   - Crash recovery startup scan (C5)
 *   - Exponential retry with proper delay (Section 5.4)
 *
 * Supervisor config: see deploy/supervisor/video-worker.conf
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Encoding\CancelledException;
use VideoSystem\Encoding\EncodingException;
use VideoSystem\Queue\JobQueue;
use VideoSystem\Worker\CrashRecovery;
use VideoSystem\Worker\ShutdownFlag;
use VideoSystem\Worker\VideoProcessor;

// ---------------------------------------------------------------------------
// Signal handling (C3 / Section 5.5)
// ShutdownFlag is a static class so signal handlers and all code paths
// (including RenditionPipeline) see the flag change immediately.
// ---------------------------------------------------------------------------
if (!function_exists('pcntl_async_signals')) {
    echo "[worker] WARNING: pcntl extension not loaded. Graceful shutdown unavailable.\n";
}

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () {
        echo "[worker] SIGTERM received — will stop after current rendition.\n";
        ShutdownFlag::request();
    });
    pcntl_signal(SIGINT, function () {
        echo "[worker] SIGINT received — will stop after current rendition.\n";
        ShutdownFlag::request();
    });
}

// ---------------------------------------------------------------------------
// Startup: scan for stale key files from previous crashes (C5)
// ---------------------------------------------------------------------------
$workDir = Config::workDir();
$processingBase = $workDir . '/processing';

if (is_dir($processingBase)) {
    foreach (glob($processingBase . '/*/') ?: [] as $jobDir) {
        CrashRecovery::scanForStaleKeyFiles(rtrim($jobDir, '/'));
    }
}

// ---------------------------------------------------------------------------
// Main worker loop
// ---------------------------------------------------------------------------
$pid       = getmypid();
$processor = new VideoProcessor();

echo "[worker:{$pid}] Started.\n";

while (!ShutdownFlag::isRequested()) {
    // ------------------------------------------------------------------
    // Disk space check
    // ------------------------------------------------------------------
    $freeBytes = disk_free_space($workDir);
    if ($freeBytes !== false && $freeBytes < Config::minDiskFreeBytes()) {
        echo sprintf(
            "[worker:%d] Low disk space: %s free, need %s. Sleeping 60s.\n",
            $pid,
            number_format($freeBytes),
            number_format(Config::minDiskFreeBytes())
        );
        sleep(60);
        continue;
    }

    // ------------------------------------------------------------------
    // Claim a job
    // ------------------------------------------------------------------
    try {
        $job = JobQueue::claim($pid);
    } catch (\Throwable $e) {
        echo "[worker:{$pid}] DB error during claim: {$e->getMessage()}\n";
        Connection::reset();
        sleep(Config::workerPollInterval());
        continue;
    }

    if ($job === null) {
        // No work available — idle poll
        if (ShutdownFlag::isRequested()) {
            break;
        }
        sleep(Config::workerPollInterval());
        continue;
    }

    $jobId   = $job['id'];
    $videoId = $job['video_id'];

    echo "[worker:{$pid}] Claimed job {$jobId} (video_id={$videoId}).\n";

    // Crash recovery: clean B2 partial uploads before retry
    $video = Connection::fetch('SELECT uuid FROM videos WHERE id = :id', [':id' => $videoId]);
    if ($video !== null) {
        CrashRecovery::precleanB2($video['uuid'], (string) $jobId);
    }

    // ------------------------------------------------------------------
    // Process the job
    // ------------------------------------------------------------------
    // ShutdownFlag is read directly via static method — no need to pass it

    try {
        $processor->process($job);
    } catch (CancelledException $e) {
        echo "[worker:{$pid}] Job {$jobId} was cancelled: {$e->getMessage()}\n";
        // Clean local processing dir — B2 cleanup is handled by the DELETE endpoint
        CrashRecovery::deleteDirectory($workDir . '/processing/' . $jobId);
        // Mark as failed (cancelled is treated as failed; DELETE endpoint has already cleaned B2)
        JobQueue::markFailed($jobId, 'Cancelled by request.');
        Connection::execute("UPDATE videos SET status = 'error', error_message = 'Cancelled.' WHERE id = :id", [':id' => $videoId]);
    } catch (EncodingException $e) {
        $error = $e->getMessage();
        echo "[worker:{$pid}] Encoding error for job {$jobId}: {$error}\n";

        if ($e->isNonRetryable()) {
            // Corrupt file: fail immediately, don't waste retry slots
            JobQueue::markFailed($jobId, $error);
            Connection::execute(
                "UPDATE videos SET status = 'error', error_message = :msg WHERE id = :id",
                [':msg' => mb_substr($error, 0, 65535), ':id' => $videoId]
            );
        } else {
            JobQueue::requeueForRetry($jobId, $error);
            Connection::execute("UPDATE videos SET status = 'queued' WHERE id = :id", [':id' => $videoId]);
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
        echo "[worker:{$pid}] Unexpected error for job {$jobId}: {$error}\n";
        JobQueue::requeueForRetry($jobId, $error);
        Connection::execute("UPDATE videos SET status = 'queued' WHERE id = :id", [':id' => $videoId]);
        Connection::reset(); // Reset PDO on unexpected errors
    }

    // If shutdown was requested and we finished (or stopped mid-job), exit cleanly.
    // The reaper will reset any jobs still in 'claimed' status.
    if (ShutdownFlag::isRequested()) {
        echo "[worker:{$pid}] Shutdown complete.\n";
        break;
    }
}

echo "[worker:{$pid}] Exiting.\n";
