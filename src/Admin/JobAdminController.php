<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Queue\JobQueue;

/**
 * Admin encoding‑job management.
 *
 * GET  /admin/jobs         — paginated list of all jobs
 * POST /admin/jobs/{id}/cancel — request cancellation of an in-flight job
 */
final class JobAdminController
{
    private const PAGE_SIZE = 25;

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $status = $params['status'] ?? '';
        $offset = ($page - 1) * self::PAGE_SIZE;

        $where = '';
        $bind  = [];
        $validStatuses = ['queued','claimed','done','failed'];
        if ($status !== '' && in_array($status, $validStatuses, true)) {
            $where = 'WHERE ej.status = :status';
            $bind  = ['status' => $status];
        }

        $total = (int) (Connection::fetch(
            "SELECT COUNT(*) AS cnt FROM encoding_jobs ej {$where}",
            $bind
        )['cnt'] ?? 0);

        $jobs = Connection::fetchAll(
            "SELECT ej.id, ej.status, ej.attempts, ej.max_attempts,
                    ej.worker_pid, ej.claimed_at, ej.progress_pct,
                    ej.current_rendition, ej.cancel_requested,
                    LEFT(ej.last_error, 200) AS last_error_excerpt,
                    ej.created_at, ej.updated_at,
                    v.uuid AS video_uuid, v.original_name
             FROM encoding_jobs ej
             JOIN videos v ON v.id = ej.video_id
             {$where}
             ORDER BY ej.updated_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($bind, ['limit' => self::PAGE_SIZE, 'offset' => $offset])
        );

        $totalPages = (int) ceil($total / self::PAGE_SIZE);

        $twig = TwigFactory::create();
        $html = $twig->render('jobs.twig', [
            'jobs'          => $jobs,
            'page'          => $page,
            'total_pages'   => $totalPages,
            'total'         => $total,
            'status_filter' => $status,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function cancel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $jobId = (int) ($args['id'] ?? 0);
        $body  = (array) ($request->getParsedBody() ?? []);
        $csrf  = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', '/admin/jobs');
        }

        $job = Connection::fetch(
            'SELECT id, status FROM encoding_jobs WHERE id = :id',
            ['id' => $jobId]
        );

        if ($job === null) {
            TwigFactory::flash('error', "Job #{$jobId} not found.");
            return $response->withStatus(302)->withHeader('Location', '/admin/jobs');
        }

        if (!in_array($job['status'], ['queued', 'claimed'], true)) {
            TwigFactory::flash('error', "Job #{$jobId} cannot be cancelled (status: {$job['status']}).");
            return $response->withStatus(302)->withHeader('Location', '/admin/jobs');
        }

        JobQueue::requestCancel($jobId);

        TwigFactory::flash('success', "Cancellation requested for job #{$jobId}.");
        return $response->withStatus(302)->withHeader('Location', '/admin/jobs');
    }
}
