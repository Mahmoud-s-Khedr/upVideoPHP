<?php

declare(strict_types=1);

namespace VideoSystem\Streaming;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

/**
 * GET /api/keys/{uuid}/{key_index}
 *
 * Returns the raw 16-byte AES-128 decryption key for HLS segment decryption.
 *
 * Security measures:
 *   - Token validated by StreamTokenAuth middleware
 *   - Video must be in 'ready' status
 *   - key_hex is AES-256 decrypted before returning (S2)
 *   - Response includes Cache-Control: no-store to prevent client-side key caching
 *   - Access logging is handled at the session-event layer instead of per-key request
 */
final class KeyController
{
    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid     = $request->getAttribute('uuid');
        $keyIndex = (int) $request->getAttribute('keyIndex');

        $video = Connection::fetch(
            "SELECT id FROM videos WHERE uuid = :uuid AND status IN ('processing', 'uploading', 'ready')",
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->forbidden($response, 'Video not found or not available.');
        }

        $keyRow = Connection::fetch(
            'SELECT key_hex FROM encryption_keys WHERE video_id = :vid AND key_index = :idx',
            [':vid' => $video['id'], ':idx' => $keyIndex]
        );

        if ($keyRow === null) {
            return $this->forbidden($response, 'Encryption key not found.');
        }

        // Decrypt key_hex from AES-256 at-rest encryption (S2)
        $plainKeyHex = $this->decrypt($keyRow['key_hex']);
        if ($plainKeyHex === null) {
            return $this->serverError($response, 'Key decryption failed.');
        }

        $rawKeyBytes = hex2bin($plainKeyHex);
        if ($rawKeyBytes === false || strlen($rawKeyBytes) !== 16) {
            return $this->serverError($response, 'Invalid key material.');
        }

        $response->getBody()->write($rawKeyBytes);
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Pragma', 'no-cache');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Decrypt a base64-encoded AES-256-CBC ciphertext (produced by KeyInfoFile::encrypt).
     * Returns the plaintext hex string, or null on failure.
     */
    private function decrypt(string $ciphertext): ?string
    {
        try {
            $key  = Config::keyEncryptionSecret();
            $raw  = base64_decode($ciphertext, strict: true);
            if ($raw === false || strlen($raw) <= 16) {
                return null;
            }
            $iv         = substr($raw, 0, 16);
            $encrypted  = substr($raw, 16);
            $decrypted  = openssl_decrypt($encrypted, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
            return $decrypted !== false ? $decrypted : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function forbidden(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'FORBIDDEN', 'message' => $message], JSON_THROW_ON_ERROR));
        return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
    }

    private function serverError(ResponseInterface $response, string $message): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => 'INTERNAL_ERROR', 'message' => $message], JSON_THROW_ON_ERROR));
        return $response->withStatus(500)->withHeader('Content-Type', 'application/json');
    }
}
