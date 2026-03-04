<?php

declare(strict_types=1);

namespace VideoSystem\Config;

/**
 * Typed configuration accessor. Reads from $_ENV (populated by phpdotenv in bootstrap).
 *
 * All getters throw \RuntimeException if a required variable is missing or empty.
 */
final class Config
{
    // -------------------------------------------------------------------------
    // Database
    // -------------------------------------------------------------------------

    public static function dbHost(): string
    {
        return self::requireString('DB_HOST');
    }

    public static function dbPort(): int
    {
        return (int) ($_ENV['DB_PORT'] ?? 3306);
    }

    public static function dbName(): string
    {
        return self::requireString('DB_NAME');
    }

    public static function dbUser(): string
    {
        return self::requireString('DB_USER');
    }

    public static function dbPass(): string
    {
        return $_ENV['DB_PASS'] ?? '';
    }

    // -------------------------------------------------------------------------
    // Backblaze B2
    // -------------------------------------------------------------------------

    public static function b2KeyId(): string
    {
        return self::requireString('B2_KEY_ID');
    }

    public static function b2AppKey(): string
    {
        return self::requireString('B2_APP_KEY');
    }

    public static function b2Bucket(): string
    {
        return self::requireString('B2_BUCKET');
    }

    public static function b2Endpoint(): string
    {
        return self::requireString('B2_ENDPOINT');
    }

    /**
     * Public-facing endpoint used only when generating presigned PUT URLs for
     * direct browser uploads. Falls back to b2Endpoint() when not set, which
     * is correct for Backblaze B2 and local setups where the same endpoint is
     * reachable both internally and by the browser.
     *
     * Override with B2_PUBLIC_ENDPOINT when MinIO sits behind a TLS-terminating
     * reverse proxy (e.g. nginx on the host terminating HTTPS) so the presigned
     * URL given to the browser uses the public HTTPS URL rather than the
     * internal http://minio:9000 address.
     */
    public static function b2PublicEndpoint(): string
    {
        $pub = $_ENV['B2_PUBLIC_ENDPOINT'] ?? '';
        return $pub !== '' ? $pub : self::b2Endpoint();
    }

    public static function b2Region(): string
    {
        return self::requireString('B2_REGION');
    }

    // -------------------------------------------------------------------------
    // Security
    // -------------------------------------------------------------------------

    public static function streamTokenSecret(): string
    {
        return self::requireString('STREAM_TOKEN_SECRET');
    }

    public static function streamTokenTtlSeconds(): int
    {
        return (int) ($_ENV['STREAM_TOKEN_TTL_SECONDS'] ?? 14400);
    }

    /**
     * Returns the raw 32-byte key used for AES-256 encryption of key_hex values at rest.
     * The env var is stored as a 64-char hex string.
     */
    public static function keyEncryptionSecret(): string
    {
        $hex = self::requireString('KEY_ENCRYPTION_SECRET');
        if (!preg_match('/^[0-9a-fA-F]{64}$/', $hex)) {
            throw new \RuntimeException('KEY_ENCRYPTION_SECRET must be a 64-character hexadecimal string (32 bytes).');
        }
        $binary = hex2bin($hex);
        if ($binary === false) {
            throw new \RuntimeException('KEY_ENCRYPTION_SECRET could not be decoded from hex.');
        }
        return $binary;
    }

    // -------------------------------------------------------------------------
    // Embed tokens
    // -------------------------------------------------------------------------

    public static function embedTokenSecret(): string
    {
        return self::requireString('EMBED_TOKEN_SECRET');
    }

    public static function embedTokenTtlSeconds(): int
    {
        return (int) ($_ENV['EMBED_TOKEN_TTL_SECONDS'] ?? 14400);
    }

    // -------------------------------------------------------------------------
    // FFmpeg
    // -------------------------------------------------------------------------

    public static function ffmpegBin(): string
    {
        return $_ENV['FFMPEG_BIN'] ?? '/usr/bin/ffmpeg';
    }

    public static function ffprobeBin(): string
    {
        return $_ENV['FFPROBE_BIN'] ?? '/usr/bin/ffprobe';
    }

    // -------------------------------------------------------------------------
    // Worker / filesystem
    // -------------------------------------------------------------------------

    public static function workDir(): string
    {
        return rtrim($_ENV['WORK_DIR'] ?? '/var/video-work', '/');
    }

    public static function maxUploadBytes(): int
    {
        return (int) ($_ENV['MAX_UPLOAD_BYTES'] ?? 21474836480);
    }

    public static function workerPollInterval(): int
    {
        return (int) ($_ENV['WORKER_POLL_INTERVAL'] ?? 5);
    }

    public static function staleJobTimeoutMinutes(): int
    {
        return (int) ($_ENV['STALE_JOB_TIMEOUT_MINUTES'] ?? 120);
    }

    /**
     * How long a video row may stay in 'pending' status before the reaper
     * marks it as 'error'. This covers the case where the client called
     * /upload/init but the PUT to storage never completed (network error,
     * wrong MinIO host, closed tab, etc.).
     *
     * Should be >= B2_UPLOAD_PRESIGN_TTL_SECONDS / 60 so that the presigned
     * URL has already expired by the time the record is cleaned up.
     */
    public static function pendingUploadTtlMinutes(): int
    {
        return (int) ($_ENV['PENDING_UPLOAD_TTL_MINUTES'] ?? 60);
    }

    public static function minDiskFreeBytes(): int
    {
        return (int) ($_ENV['MIN_DISK_FREE_BYTES'] ?? 21474836480);
    }

    public static function b2UploadPresignTtlSeconds(): int
    {
        return (int) ($_ENV['B2_UPLOAD_PRESIGN_TTL_SECONDS'] ?? 14400);
    }

    public static function multipartPartSizeBytes(): int
    {
        $default = 64 * 1024 * 1024; // 64 MB
        return max(5 * 1024 * 1024, (int) ($_ENV['MULTIPART_PART_SIZE_BYTES'] ?? $default));
    }

    public static function ffmpegMaxMinutes(): int
    {
        return max(1, (int) ($_ENV['FFMPEG_MAX_MINUTES'] ?? 360));
    }

    // -------------------------------------------------------------------------
    // HTTP / CORS
    // -------------------------------------------------------------------------

    public static function corsAllowedOrigin(): string
    {
        return $_ENV['CORS_ALLOWED_ORIGIN'] ?? '';
    }

    /**
     * Returns the list of trusted reverse-proxy IP addresses.
     * X-Forwarded-For is only trusted when the direct peer IP is in this list.
     * Empty list = always use REMOTE_ADDR (safe default).
     *
     * @return list<string>
     */
    public static function trustedProxies(): array
    {
        $raw = $_ENV['TRUSTED_PROXIES'] ?? '';
        if ($raw === '') {
            return [];
        }
        return array_map('trim', explode(',', $raw));
    }

    public static function appBaseUrl(): string
    {
        return rtrim(self::requireString('APP_BASE_URL'), '/');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function requireString(string $key): string
    {
        $value = $_ENV[$key] ?? '';
        if ($value === '') {
            throw new \RuntimeException("Required environment variable '{$key}' is not set.");
        }
        return $value;
    }
}
