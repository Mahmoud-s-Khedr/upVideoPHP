<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Streaming;

use PHPUnit\Framework\Attributes\DataProvider;
use VideoSystem\Auth\StreamToken;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * TokenController integration tests.
 *
 * POST /api/videos/{uuid}/token
 *
 * Authenticated by API key; returns a stream token or sets a cookie.
 */
final class TokenControllerTest extends HttpIntegrationTestCase
{
    private string $apiKey = 'test-api-key-token';

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('encoding_jobs', 'videos', 'api_keys');
        $this->insertApiKey('test', $this->apiKey);
    }

    protected function tearDown(): void
    {
        $this->truncateTables('encoding_jobs', 'videos', 'api_keys');
        parent::tearDown();
    }

    // =========================================================================
    // Happy paths
    // =========================================================================

    public function testIssueTokenReturns200InBrowserMode(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->apiPost("/api/videos/{$video['uuid']}/token", $this->apiKey);

        $this->assertStatus(200, $response);
        $data = $this->json($response);

        $this->assertArrayHasKey('expires_at',          $data);
        $this->assertArrayHasKey('master_playlist_url', $data);
        // In browser mode, the cookie is set and token is NOT in the body
        $this->assertArrayNotHasKey('token', $data);
        // The playlist URL must NOT contain ?token= in browser mode
        $this->assertStringNotContainsString('?token=', $data['master_playlist_url']);
    }

    public function testIssueTokenSetsCookieInBrowserMode(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->apiPost("/api/videos/{$video['uuid']}/token", $this->apiKey);

        $setCookie = $response->getHeaderLine('Set-Cookie');
        $this->assertStringContainsString('stream_token=', $setCookie);
        $this->assertStringContainsString('HttpOnly',      $setCookie);
        $this->assertStringContainsString('Secure',        $setCookie);
        $this->assertStringContainsString('SameSite=Strict', $setCookie);
    }

    public function testIssueTokenInQueryParamModeReturnsTokenInBody(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/token",
            $this->apiKey,
            '',
            [],
            ['format' => 'token'],
        );

        $this->assertStatus(200, $response);
        $data = $this->json($response);

        $this->assertArrayHasKey('token',               $data);
        $this->assertArrayHasKey('expires_at',          $data);
        $this->assertArrayHasKey('master_playlist_url', $data);

        // The token should appear in the master_playlist_url
        $this->assertStringContainsString('?token=', $data['master_playlist_url']);
    }

    public function testIssuedTokenIsVerifiable(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/token",
            $this->apiKey,
            '',
            [],
            ['format' => 'token'],
        );

        $data  = $this->json($response);
        $token = $data['token'];

        // The token should verify as the video's UUID (no IP binding in non-browser mode)
        $verified = StreamToken::verify($token, '');
        $this->assertSame($video['uuid'], $verified);
    }

    // =========================================================================
    // Not found / error status
    // =========================================================================

    public function testIssueTokenReturns404ForUnknownVideo(): void
    {
        $response = $this->apiPost('/api/videos/' . $this->newUuid() . '/token', $this->apiKey);

        $this->assertStatus(404, $response);
    }

    public function testIssueTokenReturns422ForVideoInErrorStatus(): void
    {
        $video    = $this->insertVideo(['status' => 'error']);
        $response = $this->apiPost("/api/videos/{$video['uuid']}/token", $this->apiKey);

        $this->assertStatus(422, $response);
        $data = $this->json($response);
        $this->assertSame('VIDEO_ERROR', $data['error']);
    }

    // =========================================================================
    // Non-queued but non-error statuses (should still issue a token)
    // =========================================================================

    #[DataProvider('provideIssuableStatuses')]
    public function testIssueTokenAllowsNonErrorStatuses(string $status): void
    {
        $video    = $this->insertVideo(['status' => $status]);
        $response = $this->apiPost("/api/videos/{$video['uuid']}/token", $this->apiKey);

        $this->assertStatus(200, $response);
    }

    public static function provideIssuableStatuses(): array
    {
        return [
            'pending'    => ['pending'],
            'queued'     => ['queued'],
            'processing' => ['processing'],
            'uploading'  => ['uploading'],
            'ready'      => ['ready'],
        ];
    }

    // =========================================================================
    // Auth
    // =========================================================================

    public function testIssueTokenReturns401WithoutApiKey(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->request('POST', "/api/videos/{$video['uuid']}/token");

        $this->assertStatus(401, $response);
    }
}
