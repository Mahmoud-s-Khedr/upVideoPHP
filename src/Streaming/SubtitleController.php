<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

final class SubtitleController
{
    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid       = $request->getAttribute('uuid');
        $trackIndex = (int) $request->getAttribute('trackIndex');

        $video = Connection::fetch(
            "SELECT id FROM videos WHERE uuid = :uuid AND status != 'error'",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->notFound($response, 'Video not found.');
        }

        $subtitle = Connection::fetch(
            'SELECT b2_vtt_key
             FROM subtitles
             WHERE video_id = :vid AND track_index = :track
             LIMIT 1',
            [':vid' => $video['id'], ':track' => $trackIndex]
        );

        if ($subtitle === null) {
            return $this->notFound($response, 'Subtitle track not found.');
        }

        try {
            $content = B2Client::getContent((string) $subtitle['b2_vtt_key']);
        } catch (\RuntimeException) {
            return $this->notFound($response, 'Subtitle track not found.');
        }

        $response->getBody()->write($content);

        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/vtt; charset=UTF-8')
            ->withHeader('Cache-Control', 'no-store');
    }

    private function notFound(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'NOT_FOUND', 'message' => $message], JSON_THROW_ON_ERROR));
        return $response->withStatus(404)->withHeader('Content-Type', 'application/json');
    }
}
