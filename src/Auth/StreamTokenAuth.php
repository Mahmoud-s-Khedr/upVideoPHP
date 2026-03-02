<?php

declare(strict_types=1);

namespace VideoSystem\Auth;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use VideoSystem\Config\Config;

/**
 * Slim middleware that validates a short-lived stream token.
 *
 * Token source priority:
 *   1. HttpOnly cookie named 'stream_token' (browser default, C7)
 *   2. ?token= query parameter (non-browser clients only)
 *
 * On success, attaches the verified video UUID to the request as 'streamUuid'.
 * The UUID from the token must match the {uuid} route parameter.
 */
final class StreamTokenAuth implements MiddlewareInterface
{
    private readonly ResponseFactoryInterface $responseFactory;

    public function __construct()
    {
        $this->responseFactory = new ResponseFactory();
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $rawToken = $this->extractToken($request);

        if ($rawToken === null) {
            return $this->forbidden('Missing stream token.');
        }

        $clientIp = $this->resolveClientIp($request);

        try {
            $tokenUuid = StreamToken::verify($rawToken, $clientIp);
        } catch (TokenException $e) {
            return $this->forbidden($e->getMessage());
        }

        // Verify the token's UUID matches the route's {uuid} parameter
        $routeUuid = $request->getAttribute('uuid') ?? '';
        if ($routeUuid !== '' && $tokenUuid !== $routeUuid) {
            return $this->forbidden('Token UUID does not match resource UUID.');
        }

        return $handler->handle($request->withAttribute('streamUuid', $tokenUuid));
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        // 1. Cookie (browser default)
        $cookies = $request->getCookieParams();
        if (isset($cookies['stream_token']) && $cookies['stream_token'] !== '') {
            return $cookies['stream_token'];
        }

        // 2. Query parameter (non-browser fallback)
        $params = $request->getQueryParams();
        if (isset($params['token']) && $params['token'] !== '') {
            return $params['token'];
        }

        return null;
    }

    private function resolveClientIp(ServerRequestInterface $request): string
    {
        $serverParams = $request->getServerParams();
        $remoteAddr   = $serverParams['REMOTE_ADDR'] ?? '';
        $trusted      = Config::trustedProxies();
        if (!empty($trusted) && in_array($remoteAddr, $trusted, true)) {
            $xff = $request->getHeaderLine('X-Forwarded-For');
            if ($xff !== '') {
                return trim(explode(',', $xff)[0]);
            }
        }
        return $remoteAddr;
    }

    private function forbidden(string $message): ResponseInterface
    {
        $body     = json_encode(['error' => 'FORBIDDEN', 'message' => $message], JSON_THROW_ON_ERROR);
        $response = $this->responseFactory->createResponse(403);
        $response->getBody()->write($body);
        return $response->withHeader('Content-Type', 'application/json');
    }
}
