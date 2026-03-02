<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Streaming;

use VideoSystem\Auth\StreamToken;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * SegmentController integration tests.
 *
 * GET /api/stream/{uuid}/{label}/{segment}.ts
 *
 * Issues a 302 redirect to a pre-signed B2 URL.
 * Requires a stream token and a video in 'ready' status.
 */
final class SegmentControllerTest extends HttpIntegrationTestCase
{
    private string $uuid;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('videos');

        $video       = $this->insertVideo(['status' => 'ready']);
        $this->uuid  = $video['uuid'];
        $this->token = StreamToken::sign($this->uuid, '', 3600);
    }

    protected function tearDown(): void
    {
        $this->truncateTables('videos');
        parent::tearDown();
    }

    // =========================================================================
    // Happy path — 302 redirect
    // =========================================================================

    public function testSegmentRedirectsToPresignedUrl(): void
    {
        $b2Key = "videos/{$this->uuid}/720p/seg00001.ts";
        $this->b2->seed($b2Key, 'fake_ts_data');

        $response = $this->streamGet(
            "/api/stream/{$this->uuid}/720p/seg00001.ts",
            $this->token
        );

        $this->assertStatus(302, $response);

        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString($b2Key, $location);
        $this->assertStringContainsString('300', $location); // TTL 300 in fake URL
    }

    public function testSegmentLocationContainsSegmentKey(): void
    {
        $this->b2->seed("videos/{$this->uuid}/1080p/seg00001.ts", 'data');

        $response = $this->streamGet(
            "/api/stream/{$this->uuid}/1080p/seg00001.ts",
            $this->token
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('1080p/seg00001.ts', $response->getHeaderLine('Location'));
    }

    // =========================================================================
    // Path traversal / invalid patterns
    // =========================================================================

    public function testInvalidUuidPatternReturns403(): void
    {
        $response = $this->streamGet(
            '/api/stream/not-a-valid-uuid/720p/seg00001.ts',
            StreamToken::sign($this->uuid, '', 3600)
        );

        $this->assertStatus(403, $response); // token UUID mismatch
    }

    public function testInvalidLabelPatternReturns404(): void
    {
        // Label must match /^\d+p$/ (e.g. 720p). 'hd720' is not a valid label.
        $response = $this->streamGet(
            "/api/stream/{$this->uuid}/hd720/seg00001.ts",
            $this->token
        );

        $this->assertStatus(404, $response);
    }

    // =========================================================================
    // Video not ready
    // =========================================================================

    public function testSegmentReturns404WhenVideoNotReady(): void
    {
        $this->truncateTables('videos');
        $video    = $this->insertVideo(['status' => 'processing']);
        $uuid     = $video['uuid'];
        $token    = StreamToken::sign($uuid, '', 3600);

        $response = $this->streamGet("/api/stream/{$uuid}/720p/seg00001.ts", $token);

        $this->assertStatus(404, $response);
    }

    // =========================================================================
    // No token
    // =========================================================================

    public function testSegmentReturns403WithoutToken(): void
    {
        $response = $this->get("/api/stream/{$this->uuid}/720p/seg00001.ts");

        $this->assertStatus(403, $response);
    }
}
