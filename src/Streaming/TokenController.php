<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Auth\StreamToken;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

/**
 * POST /api/videos/{uuid}/token
 *
 * Issues a short-lived stream token for embedding a video.
 *
 * Default (browser): sets an HttpOnly Secure SameSite=Strict cookie and
 * returns the playlist URL without the token embedded (C7).
 *
 * ?format=token (non-browser): returns the token in the JSON body and
 * embeds it in the master_playlist_url.
 */
final class TokenController
{
    public function issue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid = $request->getAttribute('uuid');

        $video = Connection::fetch(
            "SELECT id, uuid, status FROM videos WHERE uuid = :uuid",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        // Token is issued for any non-error status so users can watch the original while encoding
        if ($video['status'] === 'error') {
            return $this->json($response, 422, ['error' => 'VIDEO_ERROR', 'message' => 'Video is in an error state.']);
        }

        $queryParams = $request->getQueryParams();
        $nonBrowser  = ($queryParams['format'] ?? '') === 'token';

        // For cookie mode, optionally bind the token to the client IP.
        // Only trust X-Forwarded-For when the direct peer is a known proxy.
        $serverParams = $request->getServerParams();
        $remoteAddr   = $serverParams['REMOTE_ADDR'] ?? '';
        $trusted      = Config::trustedProxies();
        $xff          = $request->getHeaderLine('X-Forwarded-For');
        if ($remoteAddr === '' && $xff !== '') {
            $clientIp = trim(explode(',', $xff)[0]);
        } elseif (!empty($trusted) && in_array($remoteAddr, $trusted, true)) {
            $clientIp = $xff !== '' ? trim(explode(',', $xff)[0]) : $remoteAddr;
        } else {
            $clientIp = $remoteAddr;
        }

        // For non-browser clients, skip IP binding (mobile apps may switch networks)
        $bindIp  = $nonBrowser ? '' : $clientIp;
        $token   = StreamToken::sign($uuid, $bindIp);
        $baseUrl = Config::appBaseUrl();
        $ttl     = Config::streamTokenTtlSeconds();

        $expiresAt   = (new \DateTimeImmutable('+' . $ttl . ' seconds'))->format(\DateTimeInterface::ATOM);
        $playlistUrl = "{$baseUrl}/api/stream/{$uuid}/master.m3u8";

        if ($nonBrowser) {
            // Return token in body; embed in URL (for clients that can't use cookies)
            $playlistUrl .= '?token=' . urlencode($token);
            return $this->json($response, 200, [
                'token'               => $token,
                'expires_at'          => $expiresAt,
                'master_playlist_url' => $playlistUrl,
            ]);
        }

        // Browser default: set HttpOnly cookie, don't leak token in URL (C7)
        $cookieValue = sprintf(
            'stream_token=%s; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=%d',
            rawurlencode($token),
            $ttl
        );

        $payload = json_encode([
            'expires_at'          => $expiresAt,
            'master_playlist_url' => $playlistUrl,
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Set-Cookie', $cookieValue);
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
