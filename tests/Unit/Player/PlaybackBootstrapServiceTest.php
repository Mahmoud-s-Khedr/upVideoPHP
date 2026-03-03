<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Player;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Player\PlaybackBootstrapService;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Support\FakeB2Client;

#[CoversClass(PlaybackBootstrapService::class)]
final class PlaybackBootstrapServiceTest extends TestCase
{
    private PlaybackBootstrapService $service;
    private FakeB2Client $b2;

    protected function setUp(): void
    {
        $this->service = new PlaybackBootstrapService();
        $this->b2      = new FakeB2Client();
        B2Client::setTestOverride($this->b2);
        PlaybackBootstrapService::setTestTrackData([], []);
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
        PlaybackBootstrapService::setTestTrackData(null, null);
    }

    public function testResolveModeReturnsErrorForErrorStatus(): void
    {
        self::assertSame('error', $this->service->resolveMode(['status' => 'error']));
    }

    public function testResolveModeReturnsHlsForReadyStatus(): void
    {
        self::assertSame('hls', $this->service->resolveMode(['status' => 'ready']));
    }

    public function testResolveModeReturnsOriginalWhenOriginalExistsAndIsNotDeleted(): void
    {
        self::assertSame('original', $this->service->resolveMode([
            'status'              => 'processing',
            'original_b2_key'     => 'videos/example/original.mp4',
            'original_deleted_at' => null,
        ]));
    }

    public function testResolveModeFallsBackToPending(): void
    {
        self::assertSame('pending', $this->service->resolveMode([
            'status'              => 'processing',
            'original_b2_key'     => null,
            'original_deleted_at' => null,
        ]));
    }

    public function testBuildPendingPayloadIncludesPollingInterval(): void
    {
        $payload = $this->service->build([
            'id'                  => 1,
            'uuid'                => '550e8400-e29b-41d4-a716-446655440000',
            'status'              => 'processing',
            'original_name'       => 'Pending Video',
            'duration_sec'        => null,
            'poster_b2_key'       => null,
            'sprite_b2_key'       => null,
            'sprite_columns'      => null,
            'sprite_rows'         => null,
            'original_b2_key'     => null,
            'original_deleted_at' => null,
        ]);

        self::assertSame('pending', $payload['playback_mode']);
        self::assertSame(15000, $payload['poll_after_ms']);
        self::assertNull($payload['master_playlist_url']);
        self::assertNull($payload['original_url']);
        self::assertNull($payload['processing_hls_url']);
        self::assertSame([], $payload['audio_tracks']);
        self::assertSame([], $payload['subtitle_tracks']);
    }

    public function testBuildOriginalPayloadIncludesPresignedUrl(): void
    {
        $key = 'videos/example/original.mp4';
        $this->b2->seed($key, 'video-bytes');

        $payload = $this->service->build([
            'id'                  => 1,
            'uuid'                => '550e8400-e29b-41d4-a716-446655440000',
            'status'              => 'processing',
            'original_name'       => 'Original Video',
            'duration_sec'        => 120,
            'poster_b2_key'       => null,
            'sprite_b2_key'       => null,
            'sprite_columns'      => null,
            'sprite_rows'         => null,
            'original_b2_key'     => $key,
            'original_deleted_at' => null,
        ]);

        self::assertSame('original', $payload['playback_mode']);
        self::assertStringContainsString($key, (string) $payload['original_url']);
        self::assertSame(30000, $payload['poll_after_ms']);
    }

    public function testBuildProcessingPayloadExposesPartialMasterUrlWhenAvailable(): void
    {
        $masterKey = 'videos/550e8400-e29b-41d4-a716-446655440000/master.m3u8';
        $this->b2->seed($masterKey, "#EXTM3U\n");

        $payload = $this->service->build([
            'id'                  => 1,
            'uuid'                => '550e8400-e29b-41d4-a716-446655440000',
            'status'              => 'processing',
            'original_name'       => 'Processing Video',
            'duration_sec'        => 120,
            'poster_b2_key'       => null,
            'sprite_b2_key'       => null,
            'sprite_columns'      => null,
            'sprite_rows'         => null,
            'original_b2_key'     => null,
            'original_deleted_at' => null,
        ]);

        self::assertSame('pending', $payload['playback_mode']);
        self::assertStringContainsString('/api/stream/550e8400-e29b-41d4-a716-446655440000/master.m3u8?token=', (string) $payload['processing_hls_url']);
    }

    public function testBuildOriginalPayloadIncludesAudioTracksAndSubtitleProxyUrls(): void
    {
        PlaybackBootstrapService::setTestTrackData(
            [
                ['track_index' => 0, 'language_code' => 'eng', 'label' => 'English'],
                ['track_index' => 1, 'language_code' => 'jpn', 'label' => 'Japanese'],
            ],
            [
                ['track_index' => 2, 'language_code' => 'eng', 'label' => 'English CC', 'is_forced' => false],
            ]
        );

        $key = 'videos/example/original.mp4';
        $this->b2->seed($key, 'video-bytes');

        $payload = $this->service->build([
            'id'                  => 1,
            'uuid'                => '550e8400-e29b-41d4-a716-446655440000',
            'status'              => 'processing',
            'original_name'       => 'Original Video',
            'duration_sec'        => 120,
            'poster_b2_key'       => null,
            'sprite_b2_key'       => null,
            'sprite_columns'      => null,
            'sprite_rows'         => null,
            'original_b2_key'     => $key,
            'original_deleted_at' => null,
        ]);

        self::assertCount(2, $payload['audio_tracks']);
        self::assertSame(1, $payload['audio_tracks'][1]['track_index']);
        self::assertCount(1, $payload['subtitle_tracks']);
        self::assertSame(2, $payload['subtitle_tracks'][0]['track_index']);
        self::assertStringContainsString('/api/stream/550e8400-e29b-41d4-a716-446655440000/subtitles/2.vtt?token=', $payload['subtitle_tracks'][0]['src']);
    }
}
