<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * Serves HLS audio playlists and audio segments.
 *
 * GET /api/stream/{uuid}/audio_{index}/index.m3u8
 * GET /api/stream/{uuid}/audio_{index}/{segment}.ts
 *
 * The master playlist advertises alternate audio tracks as audio_{index}/index.m3u8.
 * These routes serve those playlists and redirect segments to B2 presigned URLs.
 */
final class AudioPlaylistController
{
    private const PRESIGN_TTL_SECONDS = 300;

    public function playlist(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid  = $request->getAttribute('uuid');
        $index = $request->getAttribute('audioIndex');

        if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid) || !preg_match('/^\d+$/', $index)) {
            return $this->notFound($response);
        }

        $video = Connection::fetch(
            "SELECT id FROM videos WHERE uuid = :uuid AND status = 'ready'",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->notFound($response);
        }

        $b2Key = "videos/{$uuid}/audio_{$index}/index.m3u8";

        try {
            $content = B2Client::getContent($b2Key);
        } catch (\RuntimeException) {
            return $this->notFound($response, 'Audio playlist not found.');
        }

        $tokenParam = $this->extractTokenParam($request);
        $rewriter   = new PlaylistRewriter($uuid, Config::appBaseUrl());
        $rewritten  = $rewriter->rewriteAudio($content, (int) $index, $tokenParam);

        $this->logAccess($video['id'], $request, 'playlist');

        $response->getBody()->write($rewritten);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/x-mpegURL')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function segment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid    = $request->getAttribute('uuid');
        $index   = $request->getAttribute('audioIndex');
        $segment = $request->getAttribute('segment');

        if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid)
            || !preg_match('/^\d+$/', $index)
            || !preg_match('/^seg\d+$/', $segment)) {
            return $this->notFound($response);
        }

        $video = Connection::fetch(
            "SELECT id FROM videos WHERE uuid = :uuid AND status = 'ready'",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->notFound($response);
        }

        $b2Key = "videos/{$uuid}/audio_{$index}/{$segment}.ts";

        try {
            if (!B2Client::exists($b2Key)) {
                return $this->notFound($response, 'Audio segment not found.');
            }
            $presignedUrl = B2Client::presignUrl($b2Key, self::PRESIGN_TTL_SECONDS);
        } catch (\RuntimeException) {
            return $this->notFound($response, 'Audio segment not found.');
        }

        $this->logAccess($video['id'], $request, 'segment');

        return $response->withStatus(302)->withHeader('Location', $presignedUrl);
    }

    private function extractTokenParam(ServerRequestInterface $request): ?string
    {
        $params = $request->getQueryParams();
        return isset($params['token']) && $params['token'] !== '' ? $params['token'] : null;
    }

    private function logAccess(int $videoId, ServerRequestInterface $request, string $action): void
    {
        try {
            $serverParams = $request->getServerParams();
            $xff          = $request->getHeaderLine('X-Forwarded-For');
            $ip           = $xff !== '' ? trim(explode(',', $xff)[0]) : ($serverParams['REMOTE_ADDR'] ?? '');

            Connection::execute(
                'INSERT INTO access_log (video_id, ip_address, action) VALUES (:vid, :ip, :action)',
                [':vid' => $videoId, ':ip' => $ip, ':action' => $action]
            );
        } catch (\Throwable) {
            // Best-effort
        }
    }

    private function notFound(ResponseInterface $response, string $message = 'Resource not found.'): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'NOT_FOUND', 'message' => $message], JSON_THROW_ON_ERROR));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
