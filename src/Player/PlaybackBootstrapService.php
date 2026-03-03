<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use VideoSystem\Auth\StreamToken;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * Determines the current playback state for a video and builds the bootstrap
 * payload consumed by the embed/watch player.
 *
 * Playback modes:
 *   - pending:  video still processing, no playable source yet
 *   - original: original file exists but HLS not ready (fallback)
 *   - hls:      video is ready, full HLS playback
 *   - error:    video is in error state
 */
final class PlaybackBootstrapService
{
    private const POLL_PENDING_MS  = 15000;
    private const POLL_ORIGINAL_MS = 30000;
    private const PRESIGN_TTL      = 3600; // 1 hour for poster/original presigned URLs

    /** @var list<array{track_index:int,language_code:string,label:string}>|null */
    private static ?array $testAudioTracks = null;

    /** @var list<array{track_index:int,language_code:string,label:string,is_forced:bool,b2_vtt_key?:string}>|null */
    private static ?array $testSubtitleTracks = null;

    public static function setTestTrackData(?array $audioTracks, ?array $subtitleTracks): void
    {
        self::$testAudioTracks = $audioTracks;
        self::$testSubtitleTracks = $subtitleTracks;
    }

    /**
     * Build the full bootstrap payload for a video.
     *
     * @param array  $video     Video row from DB (must include uuid, status, original_name,
     *                          poster_b2_key, sprite_b2_key, original_b2_key, original_deleted_at,
     *                          duration_sec)
     * @param array  $embedSettings  Merged embed settings (logo_url, accent_color, etc.)
     * @return array Bootstrap payload ready for JSON encoding
     */
    public function build(array $video, array $embedSettings = []): array
    {
        $uuid         = $video['uuid'];
        $playbackMode = $this->resolveMode($video);
        $streamToken  = StreamToken::sign($uuid, '', 0);

        $payload = [
            'video_uuid'          => $uuid,
            'title'               => $video['original_name'] ?? '',
            'duration_sec'        => isset($video['duration_sec']) && $video['duration_sec'] !== null ? (int) $video['duration_sec'] : null,
            'poster_url'          => $this->presignIfExists($video['poster_b2_key'] ?? null),
            'sprite_url'          => $this->presignIfExists($video['sprite_b2_key'] ?? null),
            'sprite_columns'      => isset($video['sprite_columns']) && $video['sprite_columns'] !== null ? (int) $video['sprite_columns'] : null,
            'sprite_rows'         => isset($video['sprite_rows']) && $video['sprite_rows'] !== null ? (int) $video['sprite_rows'] : null,
            'status'              => $video['status'],
            'playback_mode'       => $playbackMode,
            'master_playlist_url' => null,
            'original_url'        => null,
            'processing_hls_url'  => $this->processingMasterUrl($video, $streamToken),
            'audio_tracks'        => $this->loadAudioTracks((int) $video['id']),
            'subtitle_tracks'     => $this->loadSubtitleTracks((int) $video['id'], $uuid, $streamToken),
            'embed_settings'      => $embedSettings,
            'expires_at'          => (new \DateTimeImmutable('+' . Config::streamTokenTtlSeconds() . ' seconds'))
                                        ->format(\DateTimeInterface::ATOM),
            'poll_after_ms'       => $this->pollInterval($playbackMode),
        ];

        if ($playbackMode === 'hls') {
            $payload['master_playlist_url'] = $this->buildMasterUrl($uuid, $streamToken);
        } elseif ($playbackMode === 'original') {
            $payload['original_url'] = $this->presignOriginal($video['original_b2_key']);
        }

        return $payload;
    }

    /**
     * Determine the playback mode from video status and asset availability.
     */
    public function resolveMode(array $video): string
    {
        $status = $video['status'];

        if ($status === 'error') {
            return 'error';
        }

        if ($status === 'ready') {
            return 'hls';
        }

        // Not ready yet — check if original is available as fallback
        $hasOriginal = !empty($video['original_b2_key']) && empty($video['original_deleted_at']);

        if ($hasOriginal) {
            return 'original';
        }

        return 'pending';
    }

    private function pollInterval(string $mode): ?int
    {
        return match ($mode) {
            'pending'  => self::POLL_PENDING_MS,
            'original' => self::POLL_ORIGINAL_MS,
            default    => null,
        };
    }

    private function presignIfExists(?string $b2Key): ?string
    {
        if ($b2Key === null || $b2Key === '') {
            return null;
        }

        try {
            if (!B2Client::exists($b2Key)) {
                return null;
            }
            return B2Client::presignUrl($b2Key, self::PRESIGN_TTL);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function presignOriginal(?string $b2Key): ?string
    {
        if ($b2Key === null || $b2Key === '') {
            return null;
        }

        try {
            if (!B2Client::exists($b2Key)) {
                return null;
            }
            return B2Client::presignUrl($b2Key, self::PRESIGN_TTL);
        } catch (\RuntimeException) {
            return null;
        }
    }

    /**
     * Load subtitle tracks as direct WebVTT URLs (presigned B2).
     * V1 delivers subtitles via bootstrap JSON, not HLS subtitle playlists.
     */
    private function loadAudioTracks(int $videoId): array
    {
        $rows = self::$testAudioTracks ?? Connection::fetchAll(
            'SELECT track_index, language_code, label
             FROM audio_tracks
             WHERE video_id = :vid
             ORDER BY track_index ASC',
            [':vid' => $videoId]
        );

        return array_map(
            static fn(array $row): array => [
                'track_index'   => (int) $row['track_index'],
                'language_code' => $row['language_code'],
                'label'         => $row['label'] ?? $row['language_code'],
            ],
            $rows
        );
    }

    private function loadSubtitleTracks(int $videoId, string $uuid, string $streamToken): array
    {
        $rows = self::$testSubtitleTracks ?? Connection::fetchAll(
            "SELECT track_index, language_code, label, is_forced, b2_vtt_key
             FROM subtitles
             WHERE video_id = :vid
             ORDER BY track_index ASC",
            [':vid' => $videoId]
        );

        $tracks = [];
        foreach ($rows as $row) {
            if (self::$testSubtitleTracks !== null) {
                $tracks[] = [
                    'track_index'   => (int) $row['track_index'],
                    'language_code' => $row['language_code'],
                    'label'         => $row['label'] ?? $row['language_code'],
                    'is_forced'     => (bool) $row['is_forced'],
                    'src'           => $this->buildSubtitleUrl($uuid, (int) $row['track_index'], $streamToken),
                ];
                continue;
            }

            $b2Key = $row['b2_vtt_key'] ?? null;
            if ($b2Key === null || $b2Key === '') {
                continue;
            }

            try {
                if (!B2Client::exists($b2Key)) {
                    continue;
                }
            } catch (\RuntimeException) {
                continue;
            }

            $tracks[] = [
                'track_index'   => (int) $row['track_index'],
                'language_code' => $row['language_code'],
                'label'         => $row['label'] ?? $row['language_code'],
                'is_forced'     => (bool) $row['is_forced'],
                'src'           => $this->buildSubtitleUrl($uuid, (int) $row['track_index'], $streamToken),
            ];
        }

        return $tracks;
    }

    private function processingMasterUrl(array $video, string $streamToken): ?string
    {
        if (($video['status'] ?? '') === 'ready') {
            return null;
        }

        $b2Key = "videos/{$video['uuid']}/master.m3u8";

        try {
            if (!B2Client::exists($b2Key)) {
                return null;
            }
        } catch (\RuntimeException) {
            return null;
        }

        return $this->buildMasterUrl($video['uuid'], $streamToken);
    }

    private function buildMasterUrl(string $uuid, string $streamToken): string
    {
        return Config::appBaseUrl() . "/api/stream/{$uuid}/master.m3u8?token=" . urlencode($streamToken);
    }

    private function buildSubtitleUrl(string $uuid, int $trackIndex, string $streamToken): string
    {
        return Config::appBaseUrl() . "/api/stream/{$uuid}/subtitles/{$trackIndex}.vtt?token=" . urlencode($streamToken);
    }
}
