<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use VideoSystem\Auth\ApiKeyAuth;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\IntegrationTestCase;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Http\Message\ResponseInterface;

#[CoversClass(ApiKeyAuth::class)]
final class ApiKeyAuthTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('api_keys');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function makeRequest(string $authHeader = ''): ServerRequestInterface
    {
        $factory = new ServerRequestFactory();
        $request = $factory->createServerRequest('GET', 'https://example.com/api/test');

        if ($authHeader !== '') {
            $request = $request->withHeader('Authorization', $authHeader);
        }

        return $request;
    }

    private function makePassthroughHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $factory = new ResponseFactory();
                return $factory->createResponse(200);
            }
        };
    }

    private function processMiddleware(ApiKeyAuth $middleware, ServerRequestInterface $request): ResponseInterface
    {
        return $middleware->process($request, $this->makePassthroughHandler());
    }

    // -------------------------------------------------------------------------
    // No / malformed Authorization header → 401
    // -------------------------------------------------------------------------

    public function testNoAuthorizationHeaderReturns401(): void
    {
        $mw       = new ApiKeyAuth();
        $response = $this->processMiddleware($mw, $this->makeRequest());

        self::assertSame(401, $response->getStatusCode());
    }

    public function testMalformedAuthorizationHeaderReturns401(): void
    {
        $mw       = new ApiKeyAuth();
        $response = $this->processMiddleware($mw, $this->makeRequest('Basic dXNlcjpwYXNz'));

        self::assertSame(401, $response->getStatusCode());
    }

    public function testEmptyBearerTokenReturns401(): void
    {
        $mw       = new ApiKeyAuth();
        $response = $this->processMiddleware($mw, $this->makeRequest('Bearer '));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Wrong token → 401
    // -------------------------------------------------------------------------

    public function testWrongBearerTokenReturns401(): void
    {
        $this->insertApiKey('valid-key', 'correct-token');

        $mw       = new ApiKeyAuth();
        $response = $this->processMiddleware($mw, $this->makeRequest('Bearer wrong-token'));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Revoked key → 401
    // -------------------------------------------------------------------------

    public function testRevokedKeyReturns401(): void
    {
        $this->insertApiKey('revoked-key', 'revoked-token');
        Connection::execute(
            "UPDATE api_keys SET revoked_at = NOW() WHERE name = 'revoked-key'"
        );

        $mw       = new ApiKeyAuth();
        $response = $this->processMiddleware($mw, $this->makeRequest('Bearer revoked-token'));

        self::assertSame(401, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Valid key → 200 and attaches apiKey attribute
    // -------------------------------------------------------------------------

    public function testValidKeyReturns200(): void
    {
        $this->insertApiKey('working-key', 'my-secret-token');

        $mw       = new ApiKeyAuth();
        $response = $this->processMiddleware($mw, $this->makeRequest('Bearer my-secret-token'));

        self::assertSame(200, $response->getStatusCode());
    }

    public function testValidKeyAttachesApiKeyAttribute(): void
    {
        $this->insertApiKey('attr-key', 'attr-token');

        $capturedRequest = null;
        $handler = new class ($capturedRequest) implements RequestHandlerInterface {
            public ?ServerRequestInterface $captured = null;

            public function __construct(?ServerRequestInterface &$ref)
            {
                $this->captured = null;
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->captured = $request;
                $factory = new ResponseFactory();
                return $factory->createResponse(200);
            }
        };

        $mw = new ApiKeyAuth();
        $mw->process($this->makeRequest('Bearer attr-token'), $handler);

        self::assertNotNull($handler->captured);
        $apiKey = $handler->captured->getAttribute('apiKey');
        self::assertIsArray($apiKey);
        self::assertSame('attr-key', $apiKey['name']);
    }

    // -------------------------------------------------------------------------
    // Permission checks → 403
    // -------------------------------------------------------------------------

    public function testCanUploadFalseWithRequireUploadReturns403(): void
    {
        $this->insertApiKey('no-upload', 'no-upload-token', canUpload: false, canStream: true);

        $mw       = new ApiKeyAuth(requireUpload: true);
        $response = $this->processMiddleware($mw, $this->makeRequest('Bearer no-upload-token'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCanStreamFalseWithRequireStreamReturns403(): void
    {
        $this->insertApiKey('no-stream', 'no-stream-token', canUpload: true, canStream: false);

        $mw       = new ApiKeyAuth(requireStream: true);
        $response = $this->processMiddleware($mw, $this->makeRequest('Bearer no-stream-token'));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testCanUploadWithRequireUploadReturns200(): void
    {
        $this->insertApiKey('uploader', 'upload-token', canUpload: true);

        $mw       = new ApiKeyAuth(requireUpload: true);
        $response = $this->processMiddleware($mw, $this->makeRequest('Bearer upload-token'));

        self::assertSame(200, $response->getStatusCode());
    }

    // -------------------------------------------------------------------------
    // Error body is JSON
    // -------------------------------------------------------------------------

    public function testUnauthorisedResponseBodyIsJson(): void
    {
        $mw       = new ApiKeyAuth();
        $response = $this->processMiddleware($mw, $this->makeRequest());

        $body = (string) $response->getBody();
        $data = json_decode($body, true);

        self::assertIsArray($data);
        self::assertArrayHasKey('error', $data);
        self::assertArrayHasKey('message', $data);
    }
}
