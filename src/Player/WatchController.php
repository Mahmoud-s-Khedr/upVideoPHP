<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

/**
 * GET /watch/{uuid}
 *
 * Standalone public watch page — no iframe, no API key, no domain restriction.
 * Issues a stream token server-side during page render.
 * Shares the same player component as the embed page.
 */
final class WatchController
{
    public function page(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = $request->getAttribute('uuid');

        if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid)) {
            return $this->notFound($response);
        }

        $video = Connection::fetch(
            "SELECT id, uuid, original_name, duration_sec, status,
                    poster_b2_key, sprite_b2_key, sprite_columns, sprite_rows,
                    original_b2_key, original_deleted_at
             FROM videos WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->notFound($response);
        }

        $embedSettings = $this->loadEmbedSettings((int) $video['id']);
        $bootstrap     = new PlaybackBootstrapService();
        $payload       = $bootstrap->build($video, $embedSettings);

        $twig = PlayerTwigFactory::create();
        $html = $twig->render('watch.twig', [
            'video'          => $video,
            'embed_settings' => $embedSettings,
            'bootstrap_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'base_url'       => Config::appBaseUrl(),
        ]);

        $response->getBody()->write($html);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function loadEmbedSettings(int $videoId): array
    {
        return (new EmbedSettingsLoader())->loadForVideo($videoId);
    }

    private function notFound(ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'NOT_FOUND', 'message' => 'Video not found.'], JSON_THROW_ON_ERROR));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
