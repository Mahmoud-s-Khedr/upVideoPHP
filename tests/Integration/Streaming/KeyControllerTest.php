<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Streaming;

use VideoSystem\Auth\StreamToken;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * KeyController integration tests.
 *
 * GET /api/keys/{uuid}/{keyIndex}
 *
 * Requires a valid stream token. Returns the raw 16-byte AES-128 decryption key.
 * The key is stored AES-256-encrypted in the DB and decrypted on-the-fly.
 */
final class KeyControllerTest extends HttpIntegrationTestCase
{
    private string $uuid;
    private string $token;
    private string $plainKeyHex;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('encryption_keys', 'videos');

        // Insert a ready video
        $video       = $this->insertVideo(['status' => 'ready']);
        $this->uuid  = $video['uuid'];
        $this->token = StreamToken::sign($this->uuid, '', 3600);

        // Generate a known AES key and encrypt it for storage
        $this->plainKeyHex = bin2hex(random_bytes(16)); // 32 hex chars
        $encryptedHex      = $this->encryptKeyHex($this->plainKeyHex);

        Connection::execute(
            'INSERT INTO encryption_keys (video_id, key_index, key_hex, iv_hex)
             VALUES (:vid, :idx, :key_hex, :iv_hex)',
            [
                ':vid'     => $video['id'],
                ':idx'     => 0,
                ':key_hex' => $encryptedHex,
                ':iv_hex'  => $encryptedHex, // iv_hex is also stored; not used by KeyController
            ]
        );
    }

    protected function tearDown(): void
    {
        $this->truncateTables('encryption_keys', 'videos');
        parent::tearDown();
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function testReturns200WithRaw16ByteKey(): void
    {
        $response = $this->streamGet("/api/keys/{$this->uuid}/0", $this->token);

        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $rawBody = (string) $response->getBody();

        $this->assertSame(16, strlen($rawBody), 'Response body must be 16 raw bytes');
    }

    public function testResponseBodyMatchesOriginalAesKey(): void
    {
        $response = $this->streamGet("/api/keys/{$this->uuid}/0", $this->token);

        $response->getBody()->rewind();
        $rawBody = (string) $response->getBody();

        // The response body is hex2bin($plainKeyHex)
        $this->assertSame(hex2bin($this->plainKeyHex), $rawBody);
    }

    public function testContentTypeIsOctetStream(): void
    {
        $response = $this->streamGet("/api/keys/{$this->uuid}/0", $this->token);

        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
    }

    public function testCacheControlIsNoStore(): void
    {
        $response = $this->streamGet("/api/keys/{$this->uuid}/0", $this->token);

        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    // =========================================================================
    // 403 — video not ready
    // =========================================================================

    public function testReturns403WhenVideoIsNotReady(): void
    {
        $this->truncateTables('encryption_keys', 'videos');

        $video = $this->insertVideo(['status' => 'processing']);
        $token = StreamToken::sign($video['uuid'], '', 3600);

        $response = $this->streamGet("/api/keys/{$video['uuid']}/0", $token);

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // 403 — key not found
    // =========================================================================

    public function testReturns403WhenKeyIndexNotFound(): void
    {
        // Key index 99 was never inserted
        $response = $this->streamGet("/api/keys/{$this->uuid}/99", $this->token);

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // 403 — no token
    // =========================================================================

    public function testReturns403WithoutToken(): void
    {
        $response = $this->get("/api/keys/{$this->uuid}/0");

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Encrypt a key hex string the same way KeyInfoFile::encrypt() does.
     */
    private function encryptKeyHex(string $plaintext): string
    {
        $key    = Config::keyEncryptionSecret();
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            $this->fail('Test setup: openssl_encrypt returned false');
        }
        return base64_encode($iv . $cipher);
    }
}
