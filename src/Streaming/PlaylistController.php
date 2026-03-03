<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;
use VideoSystem\Upload\UploadController;

/**
 * Serves HLS playlists with URL rewriting.
 *
 * GET /api/stream/{uuid}/master.m3u8
 * GET /api/stream/{uuid}/{label}/index.m3u8
 *
 * Steps:
 *   1. Token is already validated by StreamTokenAuth middleware
 *   2. Fetch playlist from B2 (authenticated server-side request)
 *   3. Rewrite all internal URIs to route through the delivery endpoint
 *   4. Return rewritten playlist to the player
 *
 * Players never receive a direct B2 URL — the bucket stays private at all times.
 */
final class PlaylistController
{
    public function master(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid  = $request->getAttribute('uuid');
        $video = Connection::fetch(
            "SELECT id, uuid, status FROM videos WHERE uuid = :uuid AND status != 'error'",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->notFound($response);
        }

        $b2Key = "videos/{$uuid}/master.m3u8";

        try {
            $content = B2Client::getContent($b2Key);
        } catch (\RuntimeException) {
            return $this->notFound($response, 'Playlist not yet available.');
        }

        $tokenParam = $this->extractTokenParam($request);
        $rewriter   = new PlaylistRewriter($uuid, Config::appBaseUrl());
        $rewritten  = $rewriter->rewriteMaster($content, $tokenParam);

        $this->logAccess($video['id'], $request, 'playlist');

        $response->getBody()->write($rewritten);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/x-mpegURL')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function rendition(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid  = $request->getAttribute('uuid');
        $label = $request->getAttribute('label');

        $video = Connection::fetch(
            "SELECT id FROM videos WHERE uuid = :uuid AND status IN ('processing', 'uploading', 'ready')",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->notFound($response);
        }

        // Validate label against known renditions
        if (!in_array($label, UploadController::QUALITY_LABELS, true)) {
            return $this->notFound($response, 'Unknown rendition label.');
        }

        $b2Key = "videos/{$uuid}/{$label}/index.m3u8";

        try {
            $content = B2Client::getContent($b2Key);
        } catch (\RuntimeException) {
            return $this->notFound($response, 'Rendition playlist not found.');
        }

        $tokenParam = $this->extractTokenParam($request);
        $rewriter   = new PlaylistRewriter($uuid, Config::appBaseUrl());
        $rewritten  = $rewriter->rewriteRendition($content, $label, $tokenParam);

        $this->logAccess($video['id'], $request, 'playlist');

        $response->getBody()->write($rewritten);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/x-mpegURL')
            ->withHeader('Cache-Control', 'no-store');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract the raw token from the query string (non-browser mode) for URL re-embedding.
     * Returns null in cookie mode (token not in URL).
     */
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
            // Access logging is best-effort — never fail the request
        }
    }

    private function notFound(ResponseInterface $response, string $message = 'Resource not found.'): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'NOT_FOUND', 'message' => $message], JSON_THROW_ON_ERROR));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
