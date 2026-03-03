<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Player;

use VideoSystem\Auth\EmbedToken;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Embed session API integration tests.
 *
 * POST /api/videos/{uuid}/embed-sessions
 */
final class EmbedSessionControllerTest extends HttpIntegrationTestCase
{
    private string $apiKey = 'test-api-key-embed-session';

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('videos', 'api_keys');
        $this->insertApiKey('embed-session', $this->apiKey);
        EmbedToken::setTestNow(null);
    }

    protected function tearDown(): void
    {
        EmbedToken::setTestNow(null);
        $this->truncateTables('videos', 'api_keys');
        parent::tearDown();
    }

    public function testReturns200WithEmbedUrlAndExpiry(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'allowed_embed_origins' => json_encode(['https://client.example'], JSON_THROW_ON_ERROR),
        ]);
        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode(['parent_origin' => 'https://client.example'], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(200, $response);
        $this->assertJsonResponse($response);

        $data = $this->json($response);
        $this->assertSame($video['uuid'], $data['video_uuid']);
        $this->assertStringContainsString('/embed/', $data['embed_url']);
        $this->assertArrayHasKey('expires_at', $data);

        $token  = basename((string) parse_url($data['embed_url'], PHP_URL_PATH));
        $claims = EmbedToken::verify($token);
        $this->assertSame('https://client.example', $claims->parentOrigin);
    }

    public function testReturns404ForUnknownVideo(): void
    {
        $response = $this->apiPost(
            '/api/videos/' . $this->newUuid() . '/embed-sessions',
            $this->apiKey,
            json_encode(['parent_origin' => 'https://client.example'], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(404, $response);
    }

    public function testReturns422ForVideoInErrorState(): void
    {
        $video    = $this->insertVideo(['status' => 'error']);
        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode(['parent_origin' => 'https://client.example'], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(422, $response);
    }

    public function testReturns422WhenParentOriginIsMissing(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode([], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(422, $response);
    }

    public function testReturns422WhenParentOriginIsInvalid(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode(['parent_origin' => 'https://client.example/path'], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(422, $response);
    }

    public function testReturns422WhenViewerRefIsTooLong(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode([
                'parent_origin' => 'https://client.example',
                'viewer_ref'    => str_repeat('a', 129),
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(422, $response);
    }

    public function testDefaultTtlIsUsedWhenOmitted(): void
    {
        $now = time();
        EmbedToken::setTestNow($now);
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'allowed_embed_origins' => json_encode(['https://client.example'], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode(['parent_origin' => 'https://client.example'], JSON_THROW_ON_ERROR)
        );

        $data   = $this->json($response);
        $token  = basename((string) parse_url($data['embed_url'], PHP_URL_PATH));
        $claims = EmbedToken::verify($token);

        $this->assertSame($now + 3600, $claims->expiresAt);
    }

    public function testMinimumTtlIsClampedTo300Seconds(): void
    {
        $now = time();
        EmbedToken::setTestNow($now);
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'allowed_embed_origins' => json_encode(['https://client.example'], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode([
                'parent_origin' => 'https://client.example',
                'ttl_seconds'   => 1,
            ], JSON_THROW_ON_ERROR)
        );

        $data   = $this->json($response);
        $token  = basename((string) parse_url($data['embed_url'], PHP_URL_PATH));
        $claims = EmbedToken::verify($token);

        $this->assertSame($now + 300, $claims->expiresAt);
    }

    public function testMaximumTtlIsClampedTo43200Seconds(): void
    {
        $now = time();
        EmbedToken::setTestNow($now);
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'allowed_embed_origins' => json_encode(['https://client.example'], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode([
                'parent_origin' => 'https://client.example',
                'ttl_seconds'   => 999999,
            ], JSON_THROW_ON_ERROR)
        );

        $data   = $this->json($response);
        $token  = basename((string) parse_url($data['embed_url'], PHP_URL_PATH));
        $claims = EmbedToken::verify($token);

        $this->assertSame($now + 43200, $claims->expiresAt);
    }

    public function testReturns403WhenParentOriginIsNotAllowed(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertEmbedSettings([
            'allowed_embed_origins' => json_encode(['https://allowed.example'], JSON_THROW_ON_ERROR),
        ]);

        $response = $this->apiPost(
            "/api/videos/{$video['uuid']}/embed-sessions",
            $this->apiKey,
            json_encode(['parent_origin' => 'https://blocked.example'], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(403, $response);
    }

    public function testReturns401WithoutApiKey(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->post(
            "/api/videos/{$video['uuid']}/embed-sessions",
            json_encode(['parent_origin' => 'https://client.example'], JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json']
        );

        $this->assertStatus(401, $response);
    }
}
