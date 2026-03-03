<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Logging\AccessLogService;

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
        $video = $this->loadVideo((string) $request->getAttribute('uuid'));
        if ($video === null) {
            return $this->notFound($response);
        }

        $embedSettings = $this->loadEmbedSettings((int) $video['id']);
        $bootstrap     = new PlaybackBootstrapService();
        $payload       = $bootstrap->build($video, $embedSettings);
        $sessionId     = PlayerSession::generateId();

        (new AccessLogService())->log(
            (int) $video['id'],
            $request,
            'watch_open',
            $sessionId,
            null,
            [
                'surface' => 'watch',
                'source_kind' => 'none',
            ]
        );

        $twig = PlayerTwigFactory::create();
        $html = $twig->render('watch.twig', [
            'video'          => $video,
            'embed_settings' => $embedSettings,
            'bootstrap_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'bootstrap_url'  => Config::appBaseUrl() . '/watch/' . $video['uuid'] . '/bootstrap.json',
            'base_url'       => Config::appBaseUrl(),
            'session_id'     => $sessionId,
        ]);

        $response->getBody()->write($html);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    public function bootstrap(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $video = $this->loadVideo((string) $request->getAttribute('uuid'));
        if ($video === null) {
            $response->getBody()->write(json_encode(['error' => 'NOT_FOUND', 'message' => 'Video not found.'], JSON_THROW_ON_ERROR));
            return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $payload = (new PlaybackBootstrapService())->build(
            $video,
            $this->loadEmbedSettings((int) $video['id'])
        );

        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function loadVideo(string $uuid): ?array
    {
        if (!preg_match('/^[0-9a-f\-]{36}$/i', $uuid)) {
            return null;
        }

        return Connection::fetch(
            "SELECT id, uuid, original_name, duration_sec, status,
                    poster_b2_key, sprite_b2_key, sprite_columns, sprite_rows,
                    original_b2_key, original_deleted_at
             FROM videos WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );
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
