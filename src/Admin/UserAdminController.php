<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;

/**
 * Admin user management.
 *
 * GET  /admin/users                    — list all admin users
 * POST /admin/users/create             — create a new admin user
 * POST /admin/users/{id:[0-9]+}/delete — delete an admin user
 */
final class UserAdminController
{
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $users = Connection::fetchAll(
            'SELECT id, username, created_at, updated_at FROM admin_users ORDER BY id ASC',
            []
        );

        $twig = TwigFactory::create();
        $html = $twig->render('users.twig', ['users' => $users]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body     = (array) ($request->getParsedBody() ?? []);
        $csrf     = (string) ($body['_csrf'] ?? '');
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $confirm  = (string) ($body['password_confirm'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        if ($username === '') {
            TwigFactory::flash('error', 'Username is required.');
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        if (!preg_match('/^[a-zA-Z0-9_.\-]{3,64}$/', $username)) {
            TwigFactory::flash('error', 'Username must be 3–64 characters: letters, digits, _ . -');
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        if (strlen($password) < 8) {
            TwigFactory::flash('error', 'Password must be at least 8 characters.');
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        if ($password !== $confirm) {
            TwigFactory::flash('error', 'Passwords do not match.');
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        $exists = Connection::fetch(
            'SELECT id FROM admin_users WHERE username = :username',
            ['username' => $username]
        );

        if ($exists !== null) {
            TwigFactory::flash('error', "Username '{$username}' is already taken.");
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        Connection::execute(
            'INSERT INTO admin_users (username, password_hash) VALUES (:username, :hash)',
            ['username' => $username, 'hash' => password_hash($password, PASSWORD_BCRYPT)]
        );

        TwigFactory::flash('success', "Admin user '{$username}' created.");
        return $response->withStatus(302)->withHeader('Location', '/admin/users');
    }

    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id   = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        if ($id === (int) ($_SESSION['admin_id'] ?? 0)) {
            TwigFactory::flash('error', 'You cannot delete your own account.');
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        $user = Connection::fetch(
            'SELECT id, username FROM admin_users WHERE id = :id',
            ['id' => $id]
        );

        if ($user === null) {
            TwigFactory::flash('error', "User #{$id} not found.");
            return $response->withStatus(302)->withHeader('Location', '/admin/users');
        }

        $pdo = \VideoSystem\Database\Connection::get();
        $pdo->beginTransaction();
        try {
            // Lock the table to prevent concurrent deletes from racing
            $countRow = $pdo->query('SELECT COUNT(*) AS cnt FROM admin_users FOR UPDATE')->fetch();
            $total    = (int) ($countRow['cnt'] ?? 0);

            if ($total <= 1) {
                $pdo->rollBack();
                TwigFactory::flash('error', 'Cannot delete the last admin user.');
                return $response->withStatus(302)->withHeader('Location', '/admin/users');
            }

            $stmt = $pdo->prepare('DELETE FROM admin_users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        TwigFactory::flash('success', "Admin user '{$user['username']}' deleted.");
        return $response->withStatus(302)->withHeader('Location', '/admin/users');
    }
}
