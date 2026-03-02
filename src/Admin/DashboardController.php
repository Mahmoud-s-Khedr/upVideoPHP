<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;

/**
 * GET /admin — main dashboard with summary statistics.
 */
final class DashboardController
{
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // Videos by status
        $videoRows = Connection::fetchAll(
            'SELECT status, COUNT(*) AS cnt FROM videos GROUP BY status',
            []
        );
        $videoStats = ['pending' => 0, 'queued' => 0, 'processing' => 0,
                       'uploading' => 0, 'ready' => 0, 'error' => 0];
        foreach ($videoRows as $r) {
            $videoStats[$r['status']] = (int) $r['cnt'];
        }
        $videoTotal = array_sum($videoStats);

        // Encoding jobs by status
        $jobRows = Connection::fetchAll(
            'SELECT status, COUNT(*) AS cnt FROM encoding_jobs GROUP BY status',
            []
        );
        $jobStats = ['queued' => 0, 'claimed' => 0, 'done' => 0, 'failed' => 0];
        foreach ($jobRows as $r) {
            $jobStats[$r['status']] = (int) $r['cnt'];
        }

        // Active workers (jobs currently claimed)
        $activeWorkers = Connection::fetchAll(
            'SELECT worker_pid, current_rendition, progress_pct, claimed_at,
                    v.uuid AS video_uuid, v.original_name
             FROM encoding_jobs ej
             JOIN videos v ON v.id = ej.video_id
             WHERE ej.status = \'claimed\'
             ORDER BY ej.claimed_at ASC',
            []
        );

        // Recent errors
        $recentErrors = Connection::fetchAll(
            'SELECT v.uuid, v.original_name, ej.last_error, ej.updated_at
             FROM encoding_jobs ej
             JOIN videos v ON v.id = ej.video_id
             WHERE ej.status = \'failed\'
             ORDER BY ej.updated_at DESC
             LIMIT 5',
            []
        );

        // Disk usage hint
        $workDir   = $_ENV['WORK_DIR'] ?? '/var/video-work';
        $diskFreeRaw  = is_dir($workDir) ? disk_free_space($workDir) : false;
        $diskTotalRaw = is_dir($workDir) ? disk_total_space($workDir) : false;
        $diskFree  = $diskFreeRaw  !== false ? $diskFreeRaw  : null;
        $diskTotal = $diskTotalRaw !== false ? $diskTotalRaw : null;

        $twig = TwigFactory::create();
        $html = $twig->render('dashboard.twig', [
            'video_stats'    => $videoStats,
            'video_total'    => $videoTotal,
            'job_stats'      => $jobStats,
            'active_workers' => $activeWorkers,
            'recent_errors'  => $recentErrors,
            'disk_free'      => $diskFree,
            'disk_total'     => $diskTotal,
            'work_dir'       => $workDir,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
