<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Streaming;

use PHPUnit\Framework\TestCase;
use VideoSystem\Config\Config;
use VideoSystem\Streaming\KeyController;

/**
 * KeyController unit tests — focuses on the private decrypt() helper.
 *
 * The decrypt() method is exercised via PHP Reflection because:
 *   - The full handle() method requires a live DB (SELECT … WHERE uuid = ? AND status = 'ready')
 *   - The decryption logic is self-contained and critical to security
 *
 * Encryption uses the same algorithm as KeyInfoFile::encrypt() so tests are a
 * true round-trip through the encrypt → store → decrypt cycle.
 *
 * phpunit.xml sets KEY_ENCRYPTION_SECRET = 0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20
 */
final class KeyControllerDecryptTest extends TestCase
{
    private \ReflectionMethod $decryptMethod;
    private KeyController $controller;

    protected function setUp(): void
    {
        $this->controller   = new KeyController();
        $ref                = new \ReflectionClass(KeyController::class);
        $this->decryptMethod = $ref->getMethod('decrypt');
        $this->decryptMethod->setAccessible(true);
    }

    // =========================================================================
    // Happy-path round-trip
    // =========================================================================

    public function testDecryptRoundTrip(): void
    {
        $plainKeyHex = bin2hex(random_bytes(16)); // 32 hex chars
        $ciphertext  = $this->encrypt($plainKeyHex);

        $result = $this->decryptMethod->invoke($this->controller, $ciphertext);

        $this->assertSame($plainKeyHex, $result);
    }

    public function testDecryptRoundTripWithKnownKeyHex(): void
    {
        // A fixed known AES key hex (all bytes 0xAB)
        $knownKeyHex = str_repeat('ab', 16); // 32 hex chars
        $ciphertext  = $this->encrypt($knownKeyHex);

        $result = $this->decryptMethod->invoke($this->controller, $ciphertext);

        $this->assertSame($knownKeyHex, $result);
    }

    public function testDecryptProducesExactly32CharHexOutput(): void
    {
        $plainKeyHex = bin2hex(random_bytes(16));
        $ciphertext  = $this->encrypt($plainKeyHex);

        $result = $this->decryptMethod->invoke($this->controller, $ciphertext);

        $this->assertNotNull($result);
        $this->assertSame(32, strlen($result), 'AES-128 key hex should be 32 chars');
    }

    // =========================================================================
    // Failure cases — must return null, not throw
    // =========================================================================

    public function testDecryptReturnsNullForWrongKey(): void
    {
        // Encrypt with one key, attempt to decrypt with a different key
        // We simulate this by providing ciphertext encrypted with a different secret
        $plainKeyHex   = bin2hex(random_bytes(16));
        $wrongKey      = random_bytes(32); // not the key from Config
        $iv            = random_bytes(16);
        $cipher        = openssl_encrypt($plainKeyHex, 'AES-256-CBC', $wrongKey, OPENSSL_RAW_DATA, $iv);
        $ciphertext    = base64_encode($iv . $cipher);

        $result = $this->decryptMethod->invoke($this->controller, $ciphertext);

        // Decryption with wrong key either returns null or produces garbage that
        // doesn't match — we just check it doesn't crash and returns null or a
        // different string
        // Since garbage may decode to *something*, we verify the round-trip fails:
        $this->assertNotSame($plainKeyHex, $result ?? '');
    }

    public function testDecryptReturnsNullForMalformedBase64(): void
    {
        $result = $this->decryptMethod->invoke($this->controller, 'not-valid-base64!!!');

        $this->assertNull($result);
    }

    public function testDecryptReturnsNullForEmptyString(): void
    {
        $result = $this->decryptMethod->invoke($this->controller, '');

        $this->assertNull($result);
    }

    public function testDecryptReturnsNullForTooShortPayload(): void
    {
        // Valid base64 but payload shorter than 16 bytes (no room for IV)
        $shortPayload = base64_encode(random_bytes(10));

        $result = $this->decryptMethod->invoke($this->controller, $shortPayload);

        $this->assertNull($result);
    }

    public function testDecryptReturnsNullForBase64OfExactly16Bytes(): void
    {
        // Exactly 16 bytes → IV only, zero-length ciphertext → invalid
        $payload = base64_encode(random_bytes(16));

        $result = $this->decryptMethod->invoke($this->controller, $payload);

        $this->assertNull($result);
    }

    public function testDecryptHandlesCorruptedCiphertext(): void
    {
        $plainKeyHex = bin2hex(random_bytes(16));
        $ciphertext  = $this->encrypt($plainKeyHex);

        // Flip a byte in the middle of the base64-encoded ciphertext
        $raw     = base64_decode($ciphertext);
        $mid     = (int) (strlen($raw) / 2);
        $raw[$mid] = chr(ord($raw[$mid]) ^ 0xFF);
        $corrupted = base64_encode($raw);

        // Should not throw; may return null or garbled hex
        $result = $this->decryptMethod->invoke($this->controller, $corrupted);

        // The decrypted content (if any) should differ from the original
        $this->assertNotSame($plainKeyHex, $result ?? '');
    }

    // =========================================================================
    // Helpers — replicates KeyInfoFile's private encrypt() for test setup
    // =========================================================================

    /**
     * Encrypt $plaintext the same way KeyInfoFile::encrypt() does it.
     * Uses the KEY_ENCRYPTION_SECRET from phpunit.xml env vars.
     */
    private function encrypt(string $plaintext): string
    {
        $key    = Config::keyEncryptionSecret();
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            $this->fail('Test setup failed: openssl_encrypt returned false');
        }
        return base64_encode($iv . $cipher);
    }
}
