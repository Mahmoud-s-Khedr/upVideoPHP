<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * GET /api/stream/{uuid}/{label}/{segment}.ts
 *
 * Issues a short-lived pre-signed B2 URL and redirects the client directly to B2 (S1).
 * PHP never proxies the video bytes — this keeps FPM workers free.
 *
 * Pre-signed URL TTL: 5 minutes (300 seconds).
 */
final class SegmentController
{
    private const PRESIGN_TTL_SECONDS = 300;

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid    = $request->getAttribute('uuid');
        $label   = $request->getAttribute('label');
        $segment = $request->getAttribute('segment');

        // Validate route attributes to prevent path traversal
        if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid)
            || !preg_match('/^\d+p$/', $label)
            || !preg_match('/^seg\d+$/', $segment)) {
            return $this->notFound($response);
        }

        $video = Connection::fetch(
            "SELECT id FROM videos WHERE uuid = :uuid AND status IN ('processing', 'uploading', 'ready')",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->notFound($response);
        }

        $b2Key = "videos/{$uuid}/{$label}/{$segment}.ts";

        try {
            if (!B2Client::exists($b2Key)) {
                return $this->notFound($response, 'Segment not found.');
            }
            $presignedUrl = B2Client::presignUrl($b2Key, self::PRESIGN_TTL_SECONDS);
        } catch (\RuntimeException) {
            return $this->notFound($response, 'Segment not found.');
        }

        return $response->withStatus(302)->withHeader('Location', $presignedUrl);
    }

    private function notFound(ResponseInterface $response, string $message = 'Segment not found.'): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'NOT_FOUND', 'message' => $message], JSON_THROW_ON_ERROR));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
