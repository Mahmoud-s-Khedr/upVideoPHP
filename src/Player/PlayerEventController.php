<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Logging\AccessLogService;

final class PlayerEventController
{
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = json_decode((string) $request->getBody(), true);
        if (!is_array($body)) {
            return $this->json($response, 422, ['error' => 'VALIDATION', 'message' => 'Invalid JSON payload.']);
        }

        $videoUuid = trim((string) ($body['video_uuid'] ?? ''));
        if (!preg_match('/^[0-9a-f\-]{36}$/i', $videoUuid)) {
            return $this->json($response, 422, ['error' => 'VALIDATION', 'message' => 'video_uuid is required.']);
        }

        $action = trim((string) ($body['action'] ?? ''));
        if (!in_array($action, AccessLogService::EVENT_ACTIONS, true)) {
            return $this->json($response, 422, ['error' => 'VALIDATION', 'message' => 'Unsupported action.']);
        }

        $video = Connection::fetch(
            'SELECT id FROM videos WHERE uuid = :uuid',
            [':uuid' => $videoUuid]
        );

        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        $surface = trim((string) ($body['surface'] ?? 'watch'));
        if (!in_array($surface, ['watch', 'embed'], true)) {
            $surface = 'watch';
        }

        $sourceKind = trim((string) ($body['source_kind'] ?? 'none'));
        if (!in_array($sourceKind, ['none', 'pending', 'original', 'hls', 'error'], true)) {
            $sourceKind = 'none';
        }

        $sessionId = trim((string) ($body['session_id'] ?? ''));
        if ($sessionId === '') {
            $sessionId = null;
        }

        $details = is_array($body['details'] ?? null) ? $body['details'] : [];
        $details['surface'] = $surface;
        $details['source_kind'] = $sourceKind;

        (new AccessLogService())->log(
            (int) $video['id'],
            $request,
            $action,
            $sessionId,
            null,
            $details
        );

        return $this->json($response, 202, ['accepted' => true]);
    }

    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }
}
