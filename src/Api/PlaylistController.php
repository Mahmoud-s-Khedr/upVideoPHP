<?php

declare(strict_types=1);

namespace VideoSystem\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * Public playlist API.
 *
 * GET /api/playlists/{uuid}
 *
 * Returns the playlist metadata and ordered list of ready videos.
 * Videos not in 'ready' status are silently excluded so partially-processed
 * playlists do not cause player errors.
 *
 * Requires a valid API key (any key — no can_upload / can_stream restriction).
 */
final class PlaylistController
{
    /** TTL for poster presigned URLs (15 min — same as original video presign). */
    private const POSTER_TTL = 900;

    public function get(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';

        $playlist = Connection::fetch(
            'SELECT id, uuid, title, description, created_at, updated_at FROM playlists WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($playlist === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Playlist not found.']);
        }

        // Only include videos in 'ready' status
        $rows = Connection::fetchAll(
            "SELECT pv.position, v.uuid AS video_uuid, v.original_name, v.status,
                    v.duration_sec, v.size_bytes, v.poster_b2_key
             FROM playlist_videos pv
             JOIN videos v ON v.id = pv.video_id
             WHERE pv.playlist_id = :pid
               AND v.status = 'ready'
             ORDER BY pv.position ASC, pv.added_at ASC",
            [':pid' => $playlist['id']]
        );

        $videos = [];
        foreach ($rows as $row) {
            $posterUrl = null;
            if (!empty($row['poster_b2_key'])) {
                try {
                    if (B2Client::exists((string) $row['poster_b2_key'])) {
                        $posterUrl = B2Client::presignUrl((string) $row['poster_b2_key'], self::POSTER_TTL);
                    }
                } catch (\Throwable) {
                    // Non-fatal — poster presign failure does not abort the response
                }
            }

            $videos[] = [
                'position'      => (int) $row['position'],
                'uuid'          => $row['video_uuid'],
                'title'         => $row['original_name'],
                'status'        => $row['status'],
                'duration_sec'  => $row['duration_sec'] !== null ? (int) $row['duration_sec'] : null,
                'size_bytes'    => (int) $row['size_bytes'],
                'poster_url'    => $posterUrl,
            ];
        }

        $payload = [
            'uuid'        => $playlist['uuid'],
            'title'       => $playlist['title'],
            'description' => $playlist['description'],
            'video_count' => count($videos),
            'created_at'  => $playlist['created_at'],
            'updated_at'  => $playlist['updated_at'],
            'videos'      => $videos,
        ];

        return $this->json($response, 200, $payload);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $body = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response->getBody()->write($body);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
