<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * GET /api/videos/{uuid}/original
 *
 * Returns a JSON payload containing:
 *   - video_url     — 15-minute presigned B2 URL for the original file
 *   - expires_at    — ISO 8601 expiry of the presigned URL
 *   - audio_tracks  — metadata for all audio streams embedded in the original
 *                     (language, label; tracks are native to the file, no extra URLs needed)
 *   - subtitle_tracks — presigned URLs for each .vtt file already in B2
 *
 * This endpoint is the "watchable" fallback: once Step 6 of the encoding
 * pipeline completes (original uploaded), the user can play the video at full
 * original quality with correct audio tracks and subtitles — even if the HLS
 * encoding pipeline never finishes.
 *
 * Once HLS encoding completes the original is deleted from B2 and this
 * endpoint returns 410 Gone (use /api/stream/{uuid}/master.m3u8 instead).
 */
final class OriginalController
{
    private const VIDEO_PRESIGN_TTL_SECONDS    = 900;  // 15 min — matches player buffer time
    private const SUBTITLE_PRESIGN_TTL_SECONDS = 3600; // 1 hour — subtitles load upfront

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = $request->getAttribute('uuid');

        $video = Connection::fetch(
            'SELECT id, original_b2_key, original_deleted_at FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        // Encoding is complete — original has been deleted from B2
        if ($video['original_deleted_at'] !== null) {
            return $this->json($response, 410, [
                'error'   => 'GONE',
                'message' => 'The original file has been deleted. Use the HLS stream endpoint instead.',
            ]);
        }

        if ($video['original_b2_key'] === null) {
            return $this->json($response, 425, [
                'error'   => 'NOT_READY',
                'message' => 'Original file is not yet available for streaming.',
            ]);
        }

        try {
            if (!B2Client::exists($video['original_b2_key'])) {
                return $this->json($response, 425, [
                    'error'   => 'NOT_READY',
                    'message' => 'Original file is not yet available for streaming.',
                ]);
            }
            $videoUrl  = B2Client::presignUrl($video['original_b2_key'], self::VIDEO_PRESIGN_TTL_SECONDS);
            $expiresAt = (new \DateTimeImmutable())
                ->modify('+' . self::VIDEO_PRESIGN_TTL_SECONDS . ' seconds')
                ->format(\DateTimeInterface::ATOM);
        } catch (\RuntimeException) {
            return $this->json($response, 500, ['error' => 'INTERNAL_ERROR', 'message' => 'Could not generate streaming URL.']);
        }

        // Fetch audio track metadata (no extra URLs — tracks are embedded in the original file)
        $audioTracks = Connection::fetchAll(
            'SELECT track_index, language_code, label FROM audio_tracks WHERE video_id = :vid ORDER BY track_index ASC',
            [':vid' => $video['id']]
        );

        // Fetch subtitles and presign each .vtt file
        $subtitleRows = Connection::fetchAll(
            'SELECT language_code, label, is_forced, b2_vtt_key FROM subtitles WHERE video_id = :vid ORDER BY language_code ASC',
            [':vid' => $video['id']]
        );

        $subtitleTracks = [];
        foreach ($subtitleRows as $sub) {
            try {
                if (!B2Client::exists($sub['b2_vtt_key'])) {
                    continue;
                }
                $vttUrl = B2Client::presignUrl($sub['b2_vtt_key'], self::SUBTITLE_PRESIGN_TTL_SECONDS);
            } catch (\RuntimeException) {
                continue; // best-effort; skip unavailable subtitle
            }
            $subtitleTracks[] = [
                'language_code' => $sub['language_code'],
                'label'         => $sub['label'],
                'is_forced'     => (bool) $sub['is_forced'],
                'vtt_url'       => $vttUrl,
            ];
        }

        return $this->json($response, 200, [
            'video_url'       => $videoUrl,
            'expires_at'      => $expiresAt,
            'audio_tracks'    => array_map(static fn($t) => [
                'track_index'   => (int) $t['track_index'],
                'language_code' => $t['language_code'],
                'label'         => $t['label'],
            ], $audioTracks),
            'subtitle_tracks' => $subtitleTracks,
        ]);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
