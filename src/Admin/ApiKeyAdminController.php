<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;

/**
 * Admin API‑key management.
 *
 * GET  /admin/api-keys                — list all keys
 * POST /admin/api-keys/create         — create a new key
 * POST /admin/api-keys/{id}/revoke    — revoke an existing key
 */
final class ApiKeyAdminController
{
    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $keys = Connection::fetchAll(
            'SELECT id, name, can_upload, can_stream, revoked_at, created_at
             FROM api_keys
             ORDER BY created_at DESC',
            []
        );

        // Check if we have a newly-created token in the session to display once
        $newToken = $_SESSION['new_api_token'] ?? null;
        if ($newToken !== null) {
            unset($_SESSION['new_api_token']);
        }

        $twig = TwigFactory::create();
        $html = $twig->render('api-keys.twig', [
            'keys'      => $keys,
            'new_token' => $newToken,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body  = (array) ($request->getParsedBody() ?? []);
        $csrf  = (string) ($body['_csrf'] ?? '');
        $name  = trim((string) ($body['name'] ?? ''));

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', '/admin/api-keys');
        }

        if ($name === '') {
            TwigFactory::flash('error', 'Key name is required.');
            return $response->withStatus(302)->withHeader('Location', '/admin/api-keys');
        }

        $canUpload = isset($body['can_upload']) ? 1 : 0;
        $canStream = isset($body['can_stream']) ? 1 : 0;

        $rawToken = bin2hex(random_bytes(32));
        $hash     = password_hash($rawToken, PASSWORD_BCRYPT);

        Connection::execute(
            'INSERT INTO api_keys (name, key_hash, can_upload, can_stream)
             VALUES (:name, :hash, :can_upload, :can_stream)',
            [
                'name'       => $name,
                'hash'       => $hash,
                'can_upload' => $canUpload,
                'can_stream' => $canStream,
            ]
        );

        // Store raw token in session — it is displayed once on the next page load
        $_SESSION['new_api_token'] = $rawToken;
        TwigFactory::flash('success', "API key '{$name}' created. Copy the token now — it will not be shown again.");
        return $response->withStatus(302)->withHeader('Location', '/admin/api-keys');
    }

    public function revoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id   = (int) ($args['id'] ?? 0);
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', '/admin/api-keys');
        }

        $key = Connection::fetch(
            'SELECT id, name, revoked_at FROM api_keys WHERE id = :id',
            ['id' => $id]
        );

        if ($key === null) {
            TwigFactory::flash('error', "API key #{$id} not found.");
            return $response->withStatus(302)->withHeader('Location', '/admin/api-keys');
        }

        if ($key['revoked_at'] !== null) {
            TwigFactory::flash('error', "API key '{$key['name']}' is already revoked.");
            return $response->withStatus(302)->withHeader('Location', '/admin/api-keys');
        }

        Connection::execute(
            'UPDATE api_keys SET revoked_at = NOW() WHERE id = :id',
            ['id' => $id]
        );

        TwigFactory::flash('success', "API key '{$key['name']}' revoked.");
        return $response->withStatus(302)->withHeader('Location', '/admin/api-keys');
    }
}
