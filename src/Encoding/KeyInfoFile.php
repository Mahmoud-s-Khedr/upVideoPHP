<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

/**
 * Manages AES-128 encryption key material for a single encode job.
 *
 * Responsibilities:
 *   - Generate cryptographically secure key + IV
 *   - Encrypt key material at rest before DB INSERT (S2)
 *   - Write temporary .key and .keyinfo files for FFmpeg
 *   - Delete those files immediately after FFmpeg exits (C5)
 *   - Register a shutdown handler as a safety net for crash cleanup (C5)
 *
 * The raw 16-byte key is NEVER persisted to disk beyond the FFmpeg process lifetime.
 * It lives in the DB only as AES-256-encrypted ciphertext.
 */
final class KeyInfoFile
{
    private string $keyPath;
    private string $keyInfoPath;
    private bool   $registered = false;

    public function __construct(
        private readonly int    $videoId,
        private readonly string $processingDir,
        private readonly int    $keyIndex = 0,
    ) {
        $this->keyPath     = $processingDir . '/enc_' . $keyIndex . '.key';
        $this->keyInfoPath = $processingDir . '/enc.keyinfo';
    }

    /**
     * Generate a new key+IV, store encrypted in DB, write temp files.
     *
     * @return string  Path to the .keyinfo file (pass to FFmpeg as -hls_key_info_file)
     */
    public function create(): string
    {
        $keyBytes = random_bytes(16);
        $keyHex   = bin2hex($keyBytes);
        $ivBytes  = random_bytes(16);
        $ivHex    = bin2hex($ivBytes);

        // Encrypt key material before storing (S2)
        $encryptedKeyHex = $this->encrypt($keyHex);
        $encryptedIvHex  = $this->encrypt($ivHex);

        // Upsert into encryption_keys
        Connection::execute(
            'INSERT INTO encryption_keys (video_id, key_index, key_hex, iv_hex)
             VALUES (:vid, :idx, :key, :iv)
             ON DUPLICATE KEY UPDATE key_hex = VALUES(key_hex), iv_hex = VALUES(iv_hex)',
            [
                ':vid' => $this->videoId,
                ':idx' => $this->keyIndex,
                ':key' => $encryptedKeyHex,
                ':iv'  => $encryptedIvHex,
            ]
        );

        // Write temporary binary key file (16 raw bytes)
        file_put_contents($this->keyPath, $keyBytes);
        chmod($this->keyPath, 0600);

        // Build key URL for FFmpeg to embed in the playlist's #EXT-X-KEY tag
        $keyUrl = Config::appBaseUrl() . '/api/keys/' . $this->getVideoUuid() . '/' . $this->keyIndex;

        // Write keyinfo file (3 lines: URL, local path, IV hex)
        $keyInfo = implode("\n", [$keyUrl, $this->keyPath, $ivHex]);
        file_put_contents($this->keyInfoPath, $keyInfo);
        chmod($this->keyInfoPath, 0600);

        // Register shutdown handler as crash-recovery safety net (C5)
        $this->registerShutdownCleanup();

        return $this->keyInfoPath;
    }

    /**
     * Delete the temporary key files. Call immediately after FFmpeg exits.
     */
    public function cleanup(): void
    {
        @unlink($this->keyPath);
        @unlink($this->keyInfoPath);
    }

    /**
     * Remove any stale key files from a previous crashed run (C5 startup scan).
     */
    public static function cleanupStaleFiles(string $processingDir): void
    {
        foreach (glob($processingDir . '/enc_*.key') ?: [] as $file) {
            @unlink($file);
        }
        @unlink($processingDir . '/enc.keyinfo');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * AES-256-CBC encrypt a hex string using KEY_ENCRYPTION_SECRET (S2).
     * Output is base64-encoded ciphertext with prepended IV.
     */
    private function encrypt(string $plaintext): string
    {
        $key    = Config::keyEncryptionSecret();
        $iv     = random_bytes(16);
        $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new \RuntimeException('Failed to encrypt key material.');
        }
        return base64_encode($iv . $cipher);
    }

    private function getVideoUuid(): string
    {
        $row = Connection::fetch('SELECT uuid FROM videos WHERE id = :id', [':id' => $this->videoId]);
        if ($row === null || empty($row['uuid'])) {
            throw new \RuntimeException(
                "KeyInfoFile: no video found for id={$this->videoId}; cannot build key URL."
            );
        }
        return $row['uuid'];
    }

    private function registerShutdownCleanup(): void
    {
        if ($this->registered) {
            return;
        }
        $keyPath     = $this->keyPath;
        $keyInfoPath = $this->keyInfoPath;
        register_shutdown_function(function () use ($keyPath, $keyInfoPath) {
            @unlink($keyPath);
            @unlink($keyInfoPath);
        });
        $this->registered = true;
    }
}
