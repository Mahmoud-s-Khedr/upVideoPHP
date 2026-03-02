<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Auth;

use VideoSystem\Auth\StreamToken;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * StreamTokenAuth middleware integration tests.
 *
 * Uses the master playlist route as a proxy test target since it requires
 * a stream token (any route protected by StreamTokenAuth works).
 */
final class StreamTokenAuthTest extends HttpIntegrationTestCase
{
    private string $uuid;

    protected function setUp(): void
    {
        parent::setUp();

        // Insert a video in 'ready' status so the route handler proceeds
        $video      = $this->insertVideo(['status' => 'ready']);
        $this->uuid = $video['uuid'];

        // Pre-load a fake playlist in B2 so the controller doesn't throw
        $this->b2->seed("videos/{$this->uuid}/master.m3u8", "#EXTM3U\n#EXT-X-VERSION:6\n");
    }

    protected function tearDown(): void
    {
        StreamToken::setTestNow(null);
        $this->truncateTables('videos');
        parent::tearDown();
    }

    // =========================================================================
    // Missing token
    // =========================================================================

    public function testMissingTokenReturns403(): void
    {
        $response = $this->get("/api/stream/{$this->uuid}/master.m3u8");

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // Query-parameter token (non-browser mode)
    // =========================================================================

    public function testValidQueryParamTokenAllowsAccess(): void
    {
        $token    = StreamToken::sign($this->uuid, '', 60);
        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $token);

        $this->assertStatus(200, $response);
    }

    // =========================================================================
    // Cookie token (browser mode)
    // =========================================================================

    public function testValidCookieTokenAllowsAccess(): void
    {
        $token    = StreamToken::sign($this->uuid, '', 60);
        $response = $this->streamGetCookie("/api/stream/{$this->uuid}/master.m3u8", $token);

        $this->assertStatus(200, $response);
    }

    // =========================================================================
    // Expired token
    // =========================================================================

    public function testExpiredTokenReturns403(): void
    {
        $now   = time();
        StreamToken::setTestNow($now);
        $token = StreamToken::sign($this->uuid, '', 5);

        // Advance the clock past expiry
        StreamToken::setTestNow($now + 30);

        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $token);

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // UUID mismatch
    // =========================================================================

    public function testTokenUuidMismatchReturns403(): void
    {
        // Token is signed for a DIFFERENT UUID than the route
        $otherUuid = $this->newUuid();
        $token     = StreamToken::sign($otherUuid, '', 60);

        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $token);

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // Tampered token
    // =========================================================================

    public function testTamperedTokenReturns403(): void
    {
        $token    = StreamToken::sign($this->uuid, '', 60);
        $bad      = substr($token, 0, -2) . 'ZZ'; // corrupt last 2 chars

        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $bad);

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // IP binding
    // =========================================================================

    /**
     * A token signed for IP 1.2.3.4 must be rejected when the request comes
     * from a different IP (5.6.7.8 via X-Forwarded-For).
     */
    public function testIpBoundTokenRejectedFromDifferentIp(): void
    {
        // Sign the token bound to a specific IP
        $token = StreamToken::sign($this->uuid, '1.2.3.4', 60);

        // Present the token as if the request originates from a different IP
        $response = $this->request(
            'GET',
            "/api/stream/{$this->uuid}/master.m3u8",
            ['X-Forwarded-For' => '5.6.7.8'],
            '',
            [],
            ['token' => $token],
        );

        $this->assertStatus(403, $response);
    }

    /**
     * A token signed for IP 1.2.3.4 must be accepted when the request comes
     * from that exact IP.
     */
    public function testIpBoundTokenAcceptedFromMatchingIp(): void
    {
        $token = StreamToken::sign($this->uuid, '1.2.3.4', 60);

        $response = $this->request(
            'GET',
            "/api/stream/{$this->uuid}/master.m3u8",
            ['X-Forwarded-For' => '1.2.3.4'],
            '',
            [],
            ['token' => $token],
        );

        $this->assertStatus(200, $response);
    }
}
