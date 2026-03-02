<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;

/**
 * Admin playlist management.
 *
 * GET  /admin/playlists                               — paginated list
 * GET  /admin/playlists/create                        — create form
 * POST /admin/playlists/create                        — create action
 * GET  /admin/playlists/{id}                          — detail / edit
 * POST /admin/playlists/{id}/update                   — update title/description
 * POST /admin/playlists/{id}/delete                   — delete playlist
 * POST /admin/playlists/{id}/videos/add               — add a video
 * POST /admin/playlists/{id}/videos/{videoId}/remove  — remove a video
 * POST /admin/playlists/{id}/videos/reorder           — save positions
 */
final class PlaylistAdminController
{
    private const PAGE_SIZE = 25;

    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params = $request->getQueryParams();
        $page   = max(1, (int) ($params['page'] ?? 1));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $total = (int) (Connection::fetch(
            'SELECT COUNT(*) AS cnt FROM playlists'
        )['cnt'] ?? 0);

        $playlists = Connection::fetchAll(
            'SELECT p.id, p.uuid, p.title, p.description, p.created_at, p.updated_at,
                    COUNT(pv.id) AS video_count
             FROM playlists p
             LEFT JOIN playlist_videos pv ON pv.playlist_id = p.id
             GROUP BY p.id
             ORDER BY p.created_at DESC
             LIMIT :limit OFFSET :offset',
            ['limit' => self::PAGE_SIZE, 'offset' => $offset]
        );

        $twig = TwigFactory::create();
        $html = $twig->render('playlists.twig', [
            'playlists'   => $playlists,
            'page'        => $page,
            'total_pages' => (int) ceil($total / self::PAGE_SIZE),
            'total'       => $total,
            'active_page' => 'playlists',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------

    public function createForm(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $twig = TwigFactory::create();
        $html = $twig->render('playlist-create.twig', ['active_page' => 'playlists']);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body  = (array) ($request->getParsedBody() ?? []);
        $csrf  = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', '/admin/playlists/create');
        }

        $title       = trim((string) ($body['title'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));

        if ($title === '') {
            TwigFactory::flash('error', 'Title is required.');
            return $response->withStatus(302)->withHeader('Location', '/admin/playlists/create');
        }

        if (mb_strlen($title) > 255) {
            TwigFactory::flash('error', 'Title must not exceed 255 characters.');
            return $response->withStatus(302)->withHeader('Location', '/admin/playlists/create');
        }

        $uuid = $this->generateUuid();

        Connection::execute(
            'INSERT INTO playlists (uuid, title, description) VALUES (:uuid, :title, :desc)',
            [
                ':uuid'  => $uuid,
                ':title' => $title,
                ':desc'  => $description !== '' ? $description : null,
            ]
        );

        $id = Connection::lastInsertId();

        TwigFactory::flash('success', "Playlist \"{$title}\" created.");
        return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$id}");
    }

    // -------------------------------------------------------------------------
    // Detail
    // -------------------------------------------------------------------------

    public function detail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $playlist = $this->fetchPlaylistById((int) ($args['id'] ?? 0));

        if ($playlist === null) {
            $response->getBody()->write('<h1>404 — Playlist not found</h1>');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        // Videos in this playlist, ordered by position
        $videos = Connection::fetchAll(
            'SELECT pv.id AS pv_id, pv.position, v.id AS video_id, v.uuid, v.original_name,
                    v.status, v.duration_sec, v.size_bytes, v.poster_b2_key
             FROM playlist_videos pv
             JOIN videos v ON v.id = pv.video_id
             WHERE pv.playlist_id = :pid
             ORDER BY pv.position ASC, pv.added_at ASC',
            [':pid' => $playlist['id']]
        );

        // Ready videos NOT already in this playlist (for the "add video" dropdown)
        $addableVideos = Connection::fetchAll(
            "SELECT v.id, v.uuid, v.original_name, v.duration_sec
             FROM videos v
             WHERE v.status = 'ready'
               AND v.id NOT IN (
                   SELECT video_id FROM playlist_videos WHERE playlist_id = :pid
               )
             ORDER BY v.original_name ASC
             LIMIT 500",
            [':pid' => $playlist['id']]
        );

        $twig = TwigFactory::create();
        $html = $twig->render('playlist-detail.twig', [
            'playlist'      => $playlist,
            'videos'        => $videos,
            'addable_videos'=> $addableVideos,
            'active_page'   => 'playlists',
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------

    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id      = (int) ($args['id'] ?? 0);
        $body    = (array) ($request->getParsedBody() ?? []);
        $csrf    = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$id}");
        }

        $playlist = $this->fetchPlaylistById($id);
        if ($playlist === null) {
            TwigFactory::flash('error', 'Playlist not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/playlists');
        }

        $title       = trim((string) ($body['title'] ?? ''));
        $description = trim((string) ($body['description'] ?? ''));

        if ($title === '') {
            TwigFactory::flash('error', 'Title is required.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$id}");
        }

        if (mb_strlen($title) > 255) {
            TwigFactory::flash('error', 'Title must not exceed 255 characters.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$id}");
        }

        Connection::execute(
            'UPDATE playlists SET title = :title, description = :desc WHERE id = :id',
            [
                ':title' => $title,
                ':desc'  => $description !== '' ? $description : null,
                ':id'    => $id,
            ]
        );

        TwigFactory::flash('success', 'Playlist updated.');
        return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$id}");
    }

    // -------------------------------------------------------------------------
    // Delete
    // -------------------------------------------------------------------------

    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id      = (int) ($args['id'] ?? 0);
        $body    = (array) ($request->getParsedBody() ?? []);
        $csrf    = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$id}");
        }

        $playlist = $this->fetchPlaylistById($id);
        if ($playlist === null) {
            TwigFactory::flash('error', 'Playlist not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/playlists');
        }

        // FK ON DELETE CASCADE removes playlist_videos rows automatically
        Connection::execute('DELETE FROM playlists WHERE id = :id', [':id' => $id]);

        TwigFactory::flash('success', "Playlist \"{$playlist['title']}\" deleted.");
        return $response->withStatus(302)->withHeader('Location', '/admin/playlists');
    }

    // -------------------------------------------------------------------------
    // Add video
    // -------------------------------------------------------------------------

    public function addVideo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $playlistId = (int) ($args['id'] ?? 0);
        $body       = (array) ($request->getParsedBody() ?? []);
        $csrf       = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
        }

        $playlist = $this->fetchPlaylistById($playlistId);
        if ($playlist === null) {
            TwigFactory::flash('error', 'Playlist not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/playlists');
        }

        $videoId = (int) ($body['video_id'] ?? 0);
        if ($videoId <= 0) {
            TwigFactory::flash('error', 'No video selected.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
        }

        $video = Connection::fetch('SELECT id FROM videos WHERE id = :id', [':id' => $videoId]);
        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
        }

        // Calculate next position
        $maxPos = Connection::fetch(
            'SELECT COALESCE(MAX(position), -1) AS max_pos FROM playlist_videos WHERE playlist_id = :pid',
            [':pid' => $playlistId]
        );
        $nextPosition = (int) ($maxPos['max_pos'] ?? -1) + 1;

        try {
            Connection::execute(
                'INSERT INTO playlist_videos (playlist_id, video_id, position)
                 VALUES (:pid, :vid, :pos)',
                [':pid' => $playlistId, ':vid' => $videoId, ':pos' => $nextPosition]
            );
            TwigFactory::flash('success', 'Video added to playlist.');
        } catch (\Throwable $e) {
            // Duplicate entry (video already in playlist) — graceful ignore
            if (str_contains($e->getMessage(), 'Duplicate') || str_contains($e->getMessage(), '1062')) {
                TwigFactory::flash('error', 'Video is already in this playlist.');
            } else {
                TwigFactory::flash('error', 'Could not add video: ' . $e->getMessage());
            }
        }

        return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
    }

    // -------------------------------------------------------------------------
    // Remove video
    // -------------------------------------------------------------------------

    public function removeVideo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $playlistId = (int) ($args['id'] ?? 0);
        $videoId    = (int) ($args['videoId'] ?? 0);
        $body       = (array) ($request->getParsedBody() ?? []);
        $csrf       = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
        }

        Connection::execute(
            'DELETE FROM playlist_videos WHERE playlist_id = :pid AND video_id = :vid',
            [':pid' => $playlistId, ':vid' => $videoId]
        );

        TwigFactory::flash('success', 'Video removed from playlist.');
        return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
    }

    // -------------------------------------------------------------------------
    // Reorder videos
    // -------------------------------------------------------------------------

    public function reorderVideos(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $playlistId = (int) ($args['id'] ?? 0);
        $body       = (array) ($request->getParsedBody() ?? []);
        $csrf       = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
        }

        $playlist = $this->fetchPlaylistById($playlistId);
        if ($playlist === null) {
            TwigFactory::flash('error', 'Playlist not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/playlists');
        }

        // $body['positions'] is an array: video_id => position
        $positions = isset($body['positions']) && is_array($body['positions'])
            ? $body['positions']
            : [];

        if (empty($positions)) {
            TwigFactory::flash('error', 'No positions submitted.');
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
        }

        $db = Connection::get();
        $db->beginTransaction();

        try {
            foreach ($positions as $videoId => $position) {
                Connection::execute(
                    'UPDATE playlist_videos SET position = :pos
                     WHERE playlist_id = :pid AND video_id = :vid',
                    [
                        ':pos' => (int) $position,
                        ':pid' => $playlistId,
                        ':vid' => (int) $videoId,
                    ]
                );
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            TwigFactory::flash('error', 'Could not save order: ' . $e->getMessage());
            return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
        }

        TwigFactory::flash('success', 'Video order saved.');
        return $response->withStatus(302)->withHeader('Location', "/admin/playlists/{$playlistId}");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function fetchPlaylistById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        return Connection::fetch(
            'SELECT * FROM playlists WHERE id = :id',
            [':id' => $id]
        );
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
