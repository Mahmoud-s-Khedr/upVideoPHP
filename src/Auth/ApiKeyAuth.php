<?php

declare(strict_types=1);

namespace VideoSystem\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use VideoSystem\Database\Connection;

/**
 * Slim middleware that validates an API key from the Authorization: Bearer header.
 *
 * On success, attaches the resolved api_keys row to the request as 'apiKey' attribute.
 * On failure, returns 401 JSON immediately.
 */
final class ApiKeyAuth implements MiddlewareInterface
{
    private readonly ResponseFactoryInterface $responseFactory;

    /**
     * @param bool $requireUpload  When true, also checks can_upload = 1 on the key.
     * @param bool $requireStream  When true, also checks can_stream = 1 on the key.
     */
    public function __construct(
        private readonly bool $requireUpload = false,
        private readonly bool $requireStream = false,
    ) {
        $this->responseFactory = new ResponseFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('UNAUTHORIZED', 'Missing or malformed Authorization header.');
        }

        $rawToken = substr($authHeader, 7);
        if ($rawToken === '') {
            return $this->unauthorized('UNAUTHORIZED', 'Empty bearer token.');
        }

        // Load all non-revoked keys and verify via bcrypt (timing-safe via password_verify)
        $keys = Connection::fetchAll(
            'SELECT id, name, key_hash, can_upload, can_stream FROM api_keys WHERE revoked_at IS NULL'
        );

        $matched = null;
        foreach ($keys as $key) {
            if (password_verify($rawToken, $key['key_hash'])) {
                $matched = $key;
                break;
            }
        }

        if ($matched === null) {
            return $this->unauthorized('UNAUTHORIZED', 'Invalid API key.');
        }

        if ($this->requireUpload && !$matched['can_upload']) {
            return $this->unauthorized('FORBIDDEN', 'This API key does not have upload permission.');
        }

        if ($this->requireStream && !$matched['can_stream']) {
            return $this->unauthorized('FORBIDDEN', 'This API key does not have stream permission.');
        }

        return $handler->handle($request->withAttribute('apiKey', $matched));
    }

    private function unauthorized(string $code, string $message): ResponseInterface
    {
        $statusCode = $code === 'UNAUTHORIZED' ? 401 : 403;
        $body       = json_encode(['error' => $code, 'message' => $message], JSON_THROW_ON_ERROR);
        $response   = $this->responseFactory->createResponse($statusCode);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
