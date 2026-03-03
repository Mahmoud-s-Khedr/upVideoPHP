<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Error;

use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

final class NotFoundControllerTest extends HttpIntegrationTestCase
{
    public function testRootReturnsHtml404(): void
    {
        $response = $this->get('/');

        $this->assertStatus(404, $response);
        $this->assertHtmlResponse($response);
        $response->getBody()->rewind();
        self::assertStringContainsString('This page does not exist.', (string) $response->getBody());
    }

    public function testUnknownBrowserRouteReturnsHtml404(): void
    {
        $response = $this->get('/does-not-exist');

        $this->assertStatus(404, $response);
        $this->assertHtmlResponse($response);
        $response->getBody()->rewind();
        self::assertStringContainsString('/does-not-exist', (string) $response->getBody());
    }

    public function testUnknownApiRouteReturnsJson404(): void
    {
        $response = $this->get('/api/does-not-exist');

        $this->assertStatus(404, $response);
        $this->assertJsonResponse($response);
        self::assertSame(
            ['error' => 'NOT_FOUND', 'message' => 'Route not found.'],
            $this->json($response)
        );
    }

    public function testFaviconRouteReturnsIconContent(): void
    {
        $response = $this->get('/favicon.ico');

        $this->assertStatus(200, $response);
        self::assertStringStartsWith('image/svg+xml', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        self::assertStringContainsString('<svg', (string) $response->getBody());
    }
}
