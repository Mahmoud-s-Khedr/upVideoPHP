<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * PSR-15 middleware that protects all /admin/* routes (except /admin/login).
 *
 * - Starts the PHP session if not already active.
 * - Redirects to /admin/login if $_SESSION['admin_id'] is not set.
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Reset TwigFactory singleton so flash + username globals refresh each request
        TwigFactory::reset();

        // Allow login and logout through without auth check
        $path = $request->getUri()->getPath();
        if ($path === '/admin/login' || $path === '/admin/logout') {
            return $handler->handle($request);
        }

        // Require authenticated admin session
        if (empty($_SESSION['admin_id'])) {
            $response = new Response();
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/admin/login');
        }

        return $handler->handle($request);
    }
}
