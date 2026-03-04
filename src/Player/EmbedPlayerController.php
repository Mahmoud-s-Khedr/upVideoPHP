<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Auth\EmbedToken;
use VideoSystem\Auth\TokenException;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Logging\AccessLogService;

/**
 * Public embed endpoints — no API key required; auth is via signed embed token.
 *
 * GET /embed/{embedToken}              → HTML player page
 * GET /embed/{embedToken}/bootstrap.json → Playback config JSON
 */
final class EmbedPlayerController
{
    /**
     * Serve the embed HTML page.
     */
    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tokenStr = $request->getAttribute('embedToken');

        try {
            $claims = EmbedToken::verify($tokenStr);
        } catch (TokenException $e) {
            return $this->forbidden($response, $e->getMessage());
        }

        $video = $this->loadVideo($claims->videoUuid);
        if ($video === null) {
            return $this->notFound($response);
        }

        $embedSettings = $this->loadEmbedSettings((int) $video['id']);
        if (!$this->isOriginAllowed($embedSettings, $claims->parentOrigin)) {
            return $this->denyEmbed($request, $response, (int) $video['id'], $claims->parentOrigin, 'signed_origin_not_allowed');
        }

        $sessionId = PlayerSession::generateId();
        $this->logEmbedOpen($request, (int) $video['id'], $sessionId, 'signed', $claims->parentOrigin);

        $twig = PlayerTwigFactory::create();
        $html = $twig->render('embed.twig', [
            'video_uuid'    => $claims->videoUuid,
            'embed_token'   => $tokenStr,
            'embed_settings' => $embedSettings,
            'parent_origin' => $claims->parentOrigin,
            'base_url'      => Config::appBaseUrl(),
            'bootstrap_url' => Config::appBaseUrl() . '/embed/' . $tokenStr . '/bootstrap.json?session_id=' . urlencode($sessionId),
            'embed_mode'    => 'signed',
            'session_id'    => $sessionId,
        ]);

        $response->getBody()->write($html);

        // Build frame-ancestors directive
        $frameAncestors = $claims->parentOrigin !== '' ? $claims->parentOrigin : "'none'";

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Content-Security-Policy', "frame-ancestors {$frameAncestors}")
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Serve the bootstrap JSON (playback config, stream token, subtitles, settings).
     */
    public function bootstrap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $tokenStr = $request->getAttribute('embedToken');

        try {
            $claims = EmbedToken::verify($tokenStr);
        } catch (TokenException $e) {
            return $this->json($response, 403, ['error' => 'FORBIDDEN', 'message' => $e->getMessage()]);
        }

        $video = $this->loadVideo($claims->videoUuid);
        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        $embedSettings = $this->loadEmbedSettings((int) $video['id']);
        if (!$this->isOriginAllowed($embedSettings, $claims->parentOrigin)) {
            return $this->denyEmbed($request, $response, (int) $video['id'], $claims->parentOrigin, 'signed_origin_not_allowed', true);
        }
        $bootstrap     = new PlaybackBootstrapService();
        $payload       = $bootstrap->build($video, $embedSettings);

        return $this->json($response, 200, $payload);
    }

    public function stablePage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = (string) $request->getAttribute('uuid');
        $video = $this->loadVideo($uuid);
        if ($video === null) {
            return $this->notFound($response);
        }

        $embedSettings = $this->loadEmbedSettings((int) $video['id']);
        $allowedOrigins = $this->allowedOrigins($embedSettings);
        if ($allowedOrigins === []) {
            return $this->forbidden($response, 'Embed access is not configured for this video.');
        }

        $sessionId = PlayerSession::generateId();

        // Best-effort: try to resolve the embedding page's origin from the page-load
        // request (Referer header, Origin header, or explicit ?parent_origin= query param).
        // This is injected into window.__VP_CONFIG so player.js can use it directly
        // without having to parse document.referrer client-side.
        $resolvedOrigin = (new EmbedOriginService())->resolveRequestOrigin($request) ?? '';

        $this->logEmbedOpen($request, (int) $video['id'], $sessionId, 'stable', $resolvedOrigin ?: null);

        $bootstrapUrl = Config::appBaseUrl() . '/embed/video/' . $uuid . '/bootstrap.json?session_id=' . urlencode($sessionId);
        if ($resolvedOrigin !== '') {
            $bootstrapUrl .= '&parent_origin=' . urlencode($resolvedOrigin);
        }

        $twig = PlayerTwigFactory::create();
        $html = $twig->render('embed.twig', [
            'video_uuid'     => $uuid,
            'embed_token'    => null,
            'embed_settings' => $embedSettings,
            'parent_origin'  => $resolvedOrigin,
            'base_url'       => Config::appBaseUrl(),
            'bootstrap_url'  => $bootstrapUrl,
            'embed_mode'     => 'stable',
            'session_id'     => $sessionId,
        ]);

        $response->getBody()->write($html);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Content-Security-Policy', 'frame-ancestors ' . implode(' ', $allowedOrigins))
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function stableBootstrap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = (string) $request->getAttribute('uuid');
        $video = $this->loadVideo($uuid);
        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        $embedSettings = $this->loadEmbedSettings((int) $video['id']);
        $allowedOrigins = $this->allowedOrigins($embedSettings);
        if ($allowedOrigins === []) {
            return $this->json($response, 403, ['error' => 'FORBIDDEN', 'message' => 'Embed access is not configured for this video.']);
        }

        $origin = (new EmbedOriginService())->resolveRequestOrigin($request);
        if (!(new EmbedOriginService())->isAllowed($allowedOrigins, $origin)) {
            return $this->denyEmbed($request, $response, (int) $video['id'], $origin, 'stable_origin_not_allowed', true);
        }

        $payload = (new PlaybackBootstrapService())->build($video, $embedSettings);
        return $this->json($response, 200, $payload);
    }

    /**
     * Load video with all fields needed by PlaybackBootstrapService.
     */
    private function loadVideo(string $uuid): ?array
    {
        return Connection::fetch(
            "SELECT id, uuid, original_name, duration_sec, status,
                    poster_b2_key, sprite_b2_key, sprite_columns, sprite_rows,
                    original_b2_key, original_deleted_at
             FROM videos WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );
    }

    /**
     * Load embed settings with per-video override falling back to global default.
     */
    private function loadEmbedSettings(int $videoId): array
    {
        return (new EmbedSettingsLoader())->loadForVideo($videoId);
    }

    private function allowedOrigins(array $embedSettings): array
    {
        $allowedOrigins = $embedSettings['allowed_embed_origins'] ?? [];
        return is_array($allowedOrigins) ? array_values(array_filter($allowedOrigins, 'is_string')) : [];
    }

    private function isOriginAllowed(array $embedSettings, ?string $origin): bool
    {
        return (new EmbedOriginService())->isAllowed($this->allowedOrigins($embedSettings), $origin);
    }

    private function logEmbedOpen(
        ServerRequestInterface $request,
        int $videoId,
        string $sessionId,
        string $mode,
        ?string $parentOrigin = null,
    ): void {
        (new AccessLogService())->log(
            $videoId,
            $request,
            'embed_open',
            $sessionId,
            null,
            [
                'surface' => 'embed',
                'source_kind' => 'none',
                'mode' => $mode,
                'parent_origin' => $parentOrigin,
            ]
        );
    }

    private function denyEmbed(
        ServerRequestInterface $request,
        ResponseInterface $response,
        int $videoId,
        ?string $origin,
        string $reason,
        bool $json = false,
    ): ResponseInterface {
        (new AccessLogService())->log(
            $videoId,
            $request,
            'embed_denied',
            null,
            null,
            [
                'surface' => 'embed',
                'source_kind' => 'none',
                'parent_origin' => $origin,
                'reason' => $reason,
            ]
        );

        if ($json) {
            return $this->json($response, 403, ['error' => 'FORBIDDEN', 'message' => 'The embed origin is not allowed.']);
        }

        return $this->forbidden($response, 'The embed origin is not allowed.');
    }

    private function forbidden(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'FORBIDDEN', 'message' => $message], JSON_THROW_ON_ERROR));
        return $response
            ->withStatus(403)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'NOT_FOUND', 'message' => 'Video not found.'], JSON_THROW_ON_ERROR));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
