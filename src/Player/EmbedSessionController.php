<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Auth\EmbedToken;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

/**
 * POST /api/videos/{uuid}/embed-sessions
 *
 * Called by the external website backend to create a short-lived embed URL.
 * Requires ApiKeyAuth with stream permission.
 *
 * Request body:
 *   { "parent_origin": "https://client-site.example", "viewer_ref": "optional", "ttl_seconds": 14400 }
 *
 * Response:
 *   { "video_uuid": "...", "embed_url": "https://video-service/embed/...", "expires_at": "..." }
 */
final class EmbedSessionController
{
    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = $request->getAttribute('uuid');

        $video = Connection::fetch(
            "SELECT id, uuid, status FROM videos WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        if ($video['status'] === 'error') {
            return $this->json($response, 422, ['error' => 'VIDEO_ERROR', 'message' => 'Video is in an error state.']);
        }

        $body = json_decode((string) $request->getBody(), true) ?? [];

        // Validate parent_origin
        $parentOrigin = trim($body['parent_origin'] ?? '');
        if ($parentOrigin === '') {
            return $this->json($response, 422, ['error' => 'VALIDATION', 'message' => 'parent_origin is required.']);
        }
        if (!preg_match('#^https?://[^/]+$#', $parentOrigin)) {
            return $this->json($response, 422, [
                'error'   => 'VALIDATION',
                'message' => 'parent_origin must be an absolute origin (e.g. https://example.com) with no path or query.',
            ]);
        }

        // Validate viewer_ref
        $viewerRef = trim($body['viewer_ref'] ?? '');
        if (strlen($viewerRef) > 128) {
            return $this->json($response, 422, ['error' => 'VALIDATION', 'message' => 'viewer_ref must be 128 characters or fewer.']);
        }

        // Validate TTL
        $ttl = (int) ($body['ttl_seconds'] ?? 0);
        if ($ttl <= 0) {
            $ttl = Config::embedTokenTtlSeconds();
        }
        $ttl = max(300, min(43200, $ttl)); // clamp 5 min to 12 hours

        $token    = EmbedToken::sign($uuid, $parentOrigin, $viewerRef, $ttl);
        $baseUrl  = Config::appBaseUrl();
        $embedUrl = "{$baseUrl}/embed/{$token}";

        $expiresAt = (new \DateTimeImmutable('+' . $ttl . ' seconds'))->format(\DateTimeInterface::ATOM);

        return $this->json($response, 200, [
            'video_uuid' => $uuid,
            'embed_url'  => $embedUrl,
            'expires_at' => $expiresAt,
        ]);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
