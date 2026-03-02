<?php

declare(strict_types=1);

namespace VideoSystem\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;

/**
 * POST /api/ad-event — fire-and-forget ad impression recorder.
 *
 * Body (JSON): { video_uuid, position, event, session_id?, cue_index? }
 * Returns 204 No Content on success.
 */
final class AdImpressionController
{
    private const VALID_POSITIONS = ['preroll', 'midroll', 'postroll'];
    private const VALID_EVENTS    = ['start', 'skip', 'complete', 'click'];

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true) ?? [];

        $position = (string) ($body['position'] ?? '');
        $event    = (string) ($body['event']    ?? '');
        $uuid     = (string) ($body['video_uuid'] ?? '');

        if (
            !in_array($position, self::VALID_POSITIONS, true) ||
            !in_array($event, self::VALID_EVENTS, true) ||
            $uuid === ''
        ) {
            return $response->withStatus(400);
        }

        $video = Connection::fetch(
            'SELECT id FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $response->withStatus(404);
        }

        // Sanitize optional fields
        $rawSession = (string) ($body['session_id'] ?? '');
        $cleaned    = preg_replace('/[^a-zA-Z0-9]/', '', $rawSession);
        if ($cleaned === null) {
            $cleaned = '';
        }
        $sessionId  = substr($cleaned, 0, 64) ?: null;

        $cueIndex = null;
        if (isset($body['cue_index']) && is_int($body['cue_index'])) {
            $cueIndex = $body['cue_index'];
        }

        $serverParams = $request->getServerParams();
        $ip     = (string) ($serverParams['REMOTE_ADDR'] ?? '');
        $ipHash = $ip !== '' ? hash('sha256', $ip) : null;

        Connection::execute(
            'INSERT INTO ad_impressions (video_id, position, event, cue_index, session_id, ip_hash)
             VALUES (:vid, :pos, :evt, :cue, :sid, :ip)',
            [
                ':vid' => (int) $video['id'],
                ':pos' => $position,
                ':evt' => $event,
                ':cue' => $cueIndex,
                ':sid' => $sessionId,
                ':ip'  => $ipHash,
            ]
        );

        return $response->withStatus(204);
    }
}
