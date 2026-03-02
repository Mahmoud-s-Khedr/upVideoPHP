<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Auth\EmbedToken;
use VideoSystem\Auth\TokenException;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

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

        $twig = PlayerTwigFactory::create();
        $html = $twig->render('embed.twig', [
            'video_uuid'    => $claims->videoUuid,
            'embed_token'   => $tokenStr,
            'embed_settings' => $embedSettings,
            'parent_origin' => $claims->parentOrigin,
            'base_url'      => Config::appBaseUrl(),
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
        $bootstrap     = new PlaybackBootstrapService();
        $payload       = $bootstrap->build($video, $embedSettings);

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
