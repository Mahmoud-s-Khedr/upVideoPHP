<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;

/**
 * GET /admin/access-log — paginated stream access log with optional UUID filter.
 */
final class AccessLogController
{
    private const PAGE_SIZE = 50;
    private const VALID_ACTIONS = [
        'watch_open',
        'embed_open',
        'embed_denied',
        'playback_start',
        'playback_error',
        'original_fallback',
        'ad_view',
        'ad_click',
    ];

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params     = $request->getQueryParams();
        $page       = max(1, (int) ($params['page'] ?? 1));
        $uuidFilter = trim((string) ($params['uuid'] ?? ''));
        $action     = $params['action'] ?? '';
        $offset     = ($page - 1) * self::PAGE_SIZE;

        $conditions = [];
        $bind       = [];

        if ($uuidFilter !== '') {
            $conditions[] = 'v.uuid = :uuid';
            $bind['uuid'] = $uuidFilter;
        }

        if ($action !== '' && in_array($action, self::VALID_ACTIONS, true)) {
            $conditions[] = 'al.action = :action';
            $bind['action'] = $action;
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $total = (int) (Connection::fetch(
            "SELECT COUNT(*) AS cnt
             FROM access_log al
             JOIN videos v ON v.id = al.video_id
             {$where}",
            $bind
        )['cnt'] ?? 0);

        $logs = Connection::fetchAll(
            "SELECT al.id, al.action, al.ip_address, al.key_index, al.created_at,
                    al.session_id, al.details_json,
                    v.uuid AS video_uuid, v.original_name
             FROM access_log al
             JOIN videos v ON v.id = al.video_id
             {$where}
             ORDER BY al.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($bind, ['limit' => self::PAGE_SIZE, 'offset' => $offset])
        );

        $totalPages = (int) ceil($total / self::PAGE_SIZE);

        $twig = TwigFactory::create();
        $html = $twig->render('access-log.twig', [
            'logs'          => $logs,
            'page'          => $page,
            'total_pages'   => $totalPages,
            'total'         => $total,
            'uuid_filter'   => $uuidFilter,
            'action_filter' => $action,
            'valid_actions' => self::VALID_ACTIONS,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
