<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;

/**
 * GET /admin/health — system health overview for the admin dashboard.
 */
final class HealthAdminController
{
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // --- Database check ---
        $dbOk = Connection::ping();

        // --- Disk check ---
        $workDir     = $_ENV['WORK_DIR'] ?? '/var/video-work';
        $minFreeBytes = (int) ($_ENV['MIN_DISK_FREE_BYTES'] ?? 21_474_836_480);
        $diskOk       = false;
        $diskFree     = null;
        $diskTotal    = null;

        if (is_dir($workDir)) {
            $diskFree  = disk_free_space($workDir);
            $diskTotal = disk_total_space($workDir);
            $diskOk    = $diskFree !== false && $diskTotal !== false && $diskFree >= $minFreeBytes;
            if ($diskFree === false)  $diskFree  = null;
            if ($diskTotal === false) $diskTotal = null;
        }

        // --- Active workers (claimed jobs) ---
        $activeJobs = Connection::fetchAll(
            'SELECT ej.id, ej.worker_pid, ej.progress_pct, ej.current_rendition,
                    ej.claimed_at, v.uuid AS video_uuid, v.original_name
             FROM encoding_jobs ej
             JOIN videos v ON v.id = ej.video_id
             WHERE ej.status = \'claimed\'
             ORDER BY ej.claimed_at ASC',
            []
        );

        // --- Stale jobs (claimed > 30 min with no update) ---
        $staleMinutes = (int) ($_ENV['STALE_JOB_TIMEOUT_MINUTES'] ?? 30);
        $staleJobs    = Connection::fetchAll(
            "SELECT ej.id, ej.worker_pid, ej.claimed_at, v.uuid AS video_uuid
             FROM encoding_jobs ej
             JOIN videos v ON v.id = ej.video_id
             WHERE ej.status = 'claimed'
               AND ej.claimed_at < NOW() - INTERVAL :minutes MINUTE",
            ['minutes' => $staleMinutes]
        );

        // --- Recent failures (last 24 h) ---
        $recentFailures = Connection::fetchAll(
            'SELECT ej.id, ej.attempts, ej.last_error, ej.updated_at,
                    v.uuid AS video_uuid, v.original_name
             FROM encoding_jobs ej
             JOIN videos v ON v.id = ej.video_id
             WHERE ej.status = \'failed\'
               AND ej.updated_at >= NOW() - INTERVAL 24 HOUR
             ORDER BY ej.updated_at DESC
             LIMIT 20',
            []
        );

        // --- Queue depths ---
        $queuedCount  = (int) (Connection::fetch(
            'SELECT COUNT(*) AS cnt FROM encoding_jobs WHERE status = \'queued\'',
            []
        )['cnt'] ?? 0);

        $twig = TwigFactory::create();
        $html = $twig->render('health.twig', [
            'db_ok'            => $dbOk,
            'disk_ok'          => $diskOk,
            'disk_free'        => $diskFree,
            'disk_total'       => $diskTotal,
            'min_free_bytes'   => $minFreeBytes,
            'work_dir'         => $workDir,
            'active_jobs'      => $activeJobs,
            'stale_jobs'       => $staleJobs,
            'stale_minutes'    => $staleMinutes,
            'recent_failures'  => $recentFailures,
            'queued_count'     => $queuedCount,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
