<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;
use VideoSystem\Database\Connection;

/**
 * Handles admin login and logout.
 *
 * GET  /admin/login  — render login form
 * POST /admin/login  — validate credentials
 * POST /admin/logout — destroy session and redirect to login
 */
final class AuthController
{
    public function loginForm(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        // Already logged in → redirect to dashboard
        if (!empty($_SESSION['admin_id'])) {
            return $response
                ->withStatus(302)
                ->withHeader('Location', '/admin');
        }

        $twig = TwigFactory::create();
        $html = $twig->render('login.twig', [
            'error' => null,
        ]);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function loginSubmit(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body     = (array) ($request->getParsedBody() ?? []);
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $csrf     = (string) ($body['_csrf'] ?? '');

        // CSRF guard
        if (!TwigFactory::validateCsrf($csrf)) {
            return $this->renderLoginError($response, 'Invalid form submission. Please try again.');
        }

        if ($username === '' || $password === '') {
            return $this->renderLoginError($response, 'Username and password are required.');
        }

        $row = Connection::fetch(
            'SELECT id, username, password_hash FROM admin_users WHERE username = :username LIMIT 1',
            ['username' => $username]
        );

        if ($row === null || !password_verify($password, $row['password_hash'])) {
            return $this->renderLoginError($response, 'Invalid username or password.');
        }

        // Regenerate session ID to prevent fixation
        session_regenerate_id(true);

        $_SESSION['admin_id']       = (int) $row['id'];
        $_SESSION['admin_username'] = $row['username'];

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/admin');
    }

    public function logout(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        return $response
            ->withStatus(302)
            ->withHeader('Location', '/admin/login');
    }

    private function renderLoginError(ResponseInterface $response, string $error): ResponseInterface
    {
        $twig = TwigFactory::create();
        $html = $twig->render('login.twig', ['error' => $error]);
        $response->getBody()->write($html);
        return $response
            ->withStatus(422)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }
}
