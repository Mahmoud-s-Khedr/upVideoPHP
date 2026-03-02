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
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
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
}
