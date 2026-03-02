<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Streaming;

use VideoSystem\Auth\StreamToken;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Audio HLS route integration tests.
 *
 * GET /api/stream/{uuid}/audio_{index}/index.m3u8
 * GET /api/stream/{uuid}/audio_{index}/{segment}.ts
 */
final class AudioPlaylistControllerTest extends HttpIntegrationTestCase
{
    private string $uuid;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('access_log', 'audio_tracks', 'videos');

        $video       = $this->insertVideo(['status' => 'ready']);
        $this->uuid  = $video['uuid'];
        $this->token = StreamToken::sign($this->uuid, '', 3600);
    }

    protected function tearDown(): void
    {
        $this->truncateTables('access_log', 'audio_tracks', 'videos');
        parent::tearDown();
    }

    public function testPlaylistReturns200AndRewritesUris(): void
    {
        $this->b2->seed(
            "videos/{$this->uuid}/audio_0/index.m3u8",
            "#EXTM3U\n"
            . "#EXT-X-KEY:METHOD=AES-128,URI=\"../keys/0\",IV=0x0\n"
            . "#EXTINF:6.0,\n"
            . "seg00001.ts\n"
        );

        $response = $this->streamGet("/api/stream/{$this->uuid}/audio_0/index.m3u8", $this->token);

        $this->assertStatus(200, $response);
        $body = (string) $response->getBody();
        $this->assertStringContainsString("/api/stream/{$this->uuid}/audio_0/seg00001.ts", $body);
        $this->assertStringContainsString("URI=\"https://example.com/api/keys/{$this->uuid}/0?token=", $body);
    }

    public function testPlaylistPropagatesQueryToken(): void
    {
        $this->b2->seed(
            "videos/{$this->uuid}/audio_0/index.m3u8",
            "#EXTM3U\n#EXTINF:6.0,\nseg00001.ts\n"
        );

        $response = $this->streamGet("/api/stream/{$this->uuid}/audio_0/index.m3u8", $this->token);
        $body     = (string) $response->getBody();

        $this->assertStringContainsString('?token=' . $this->token, $body);
    }

    public function testPlaylistReturns404ForUnknownVideo(): void
    {
        $uuid     = $this->newUuid();
        $token    = StreamToken::sign($uuid, '', 3600);
        $response = $this->streamGet("/api/stream/{$uuid}/audio_0/index.m3u8", $token);

        $this->assertStatus(404, $response);
    }

    public function testPlaylistReturns404ForMissingObject(): void
    {
        $response = $this->streamGet("/api/stream/{$this->uuid}/audio_0/index.m3u8", $this->token);

        $this->assertStatus(404, $response);
    }

    public function testPlaylistReturns404ForBadAudioIndexPattern(): void
    {
        $response = $this->streamGet("/api/stream/{$this->uuid}/audio_bad/index.m3u8", $this->token);

        $this->assertStatus(404, $response);
    }

    public function testPlaylistReturns403WithoutToken(): void
    {
        $response = $this->get("/api/stream/{$this->uuid}/audio_0/index.m3u8");

        $this->assertStatus(403, $response);
    }

    public function testSegmentReturns302WithPresignedUrl(): void
    {
        $key = "videos/{$this->uuid}/audio_0/seg00001.ts";
        $this->b2->seed($key, 'segment-bytes');

        $response = $this->streamGet("/api/stream/{$this->uuid}/audio_0/seg00001.ts", $this->token);

        $this->assertStatus(302, $response);
        $this->assertStringContainsString($key, $response->getHeaderLine('Location'));
    }

    public function testSegmentReturns404WhenMissing(): void
    {
        $response = $this->streamGet("/api/stream/{$this->uuid}/audio_0/seg00001.ts", $this->token);

        $this->assertStatus(404, $response);
    }

    public function testSegmentReturns404ForInvalidPathPattern(): void
    {
        $response = $this->streamGet("/api/stream/{$this->uuid}/audio_0/not-a-segment.ts", $this->token);

        $this->assertStatus(404, $response);
    }

    public function testSegmentReturns403WithoutToken(): void
    {
        $response = $this->get("/api/stream/{$this->uuid}/audio_0/seg00001.ts");

        $this->assertStatus(403, $response);
    }
}
