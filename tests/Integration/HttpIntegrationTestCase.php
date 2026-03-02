<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration;

use Slim\App;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Support\FakeB2Client;
use VideoSystem\Tests\Support\SlimAppFactory;

/**
 * Base class for HTTP-level controller integration tests.
 *
 * Boots a real Slim app (same routes / middlewares as production), wires in a
 * FakeB2Client, and provides helper methods for building requests:
 *
 *   $response = $this->get('/health');
 *   $response = $this->apiGet('/api/videos/uuid', $apiKey);
 *   $data     = $this->json($response);
 */
abstract class HttpIntegrationTestCase extends IntegrationTestCase
{
    protected App $app;
    protected FakeB2Client $b2;
    private ServerRequestFactory $requestFactory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->b2 = new FakeB2Client();
        B2Client::setTestOverride($this->b2);

        $this->app            = SlimAppFactory::create();
        $this->requestFactory = new ServerRequestFactory();
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
        $this->b2->clear();
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Request helpers
    // -------------------------------------------------------------------------

    protected function request(
        string $method,
        string $uri,
        array  $headers = [],
        string $body = '',
        array  $cookies = [],
        array  $queryParams = [],
    ): ResponseInterface {
        if (!empty($queryParams)) {
            $uri .= '?' . http_build_query($queryParams);
        }

        $request = $this->requestFactory->createServerRequest($method, $uri);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if (!empty($cookies)) {
            $request = $request->withCookieParams($cookies);
        }

        if ($body !== '') {
            $request->getBody()->write($body);
            $request = $request->withBody($request->getBody());
        }

        return $this->app->handle($request);
    }

    /** GET without auth. */
    protected function get(string $uri, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $uri, $headers);
    }

    /** GET with API key auth. */
    protected function apiGet(string $uri, string $apiKey, array $extra = []): ResponseInterface
    {
        return $this->request('GET', $uri, array_merge(['Authorization' => 'Bearer ' . $apiKey], $extra));
    }

    /** POST with API key auth. */
    protected function apiPost(
        string $uri,
        string $apiKey,
        string $body = '',
        array  $extra = [],
        array  $queryParams = [],
    ): ResponseInterface {
        return $this->request(
            'POST',
            $uri,
            array_merge(['Authorization' => 'Bearer ' . $apiKey, 'Content-Type' => 'application/json'], $extra),
            $body,
            [],
            $queryParams,
        );
    }

    /** DELETE with API key auth. */
    protected function apiDelete(string $uri, string $apiKey): ResponseInterface
    {
        return $this->request('DELETE', $uri, ['Authorization' => 'Bearer ' . $apiKey]);
    }

    protected function post(
        string $uri,
        string $body = '',
        array $headers = [],
        array $cookies = [],
        array $queryParams = [],
    ): ResponseInterface {
        return $this->request('POST', $uri, $headers, $body, $cookies, $queryParams);
    }

    /** GET with a stream token via query-param (non-browser mode). */
    protected function streamGet(string $uri, string $token, array $extra = []): ResponseInterface
    {
        return $this->request('GET', $uri, $extra, '', [], ['token' => $token]);
    }

    /** GET with a stream token via cookie (browser mode). */
    protected function streamGetCookie(string $uri, string $token, array $extra = []): ResponseInterface
    {
        return $this->request('GET', $uri, $extra, '', ['stream_token' => $token]);
    }

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    /** Decode response body as JSON assoc array. */
    protected function json(ResponseInterface $response): array
    {
        $response->getBody()->rewind();
        return json_decode((string) $response->getBody(), associative: true, flags: JSON_THROW_ON_ERROR);
    }

    /** Assert a response has the expected HTTP status. */
    protected function assertStatus(int $expected, ResponseInterface $response, string $msg = ''): void
    {
        $actual = $response->getStatusCode();
        if ($actual !== $expected) {
            $response->getBody()->rewind();
            $body = (string) $response->getBody();
            $this->fail(
                ($msg !== '' ? $msg . "\n" : '') .
                "Expected status {$expected}, got {$actual}.\nBody: {$body}"
            );
        }
        $this->assertSame($expected, $actual);
    }

    /** Assert response Content-Type is application/json. */
    protected function assertJsonResponse(ResponseInterface $response): void
    {
        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    protected function assertHtmlResponse(ResponseInterface $response): void
    {
        $this->assertStringStartsWith('text/html', $response->getHeaderLine('Content-Type'));
    }
}
