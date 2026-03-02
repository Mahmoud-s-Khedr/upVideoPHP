<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Config\Config;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    // phpunit.xml injects all required env vars, so getters must work by default.

    // -------------------------------------------------------------------------
    // Required vars — present (phpunit.xml sets them)
    // -------------------------------------------------------------------------

    public function testDbHostReturnsString(): void
    {
        self::assertIsString(Config::dbHost());
        self::assertNotEmpty(Config::dbHost());
    }

    public function testDbPortReturnsInt(): void
    {
        self::assertIsInt(Config::dbPort());
        self::assertSame(3306, Config::dbPort());
    }

    public function testDbNameReturnsString(): void
    {
        self::assertIsString(Config::dbName());
    }

    public function testB2ConfigVarsReturnStrings(): void
    {
        self::assertNotEmpty(Config::b2KeyId());
        self::assertNotEmpty(Config::b2AppKey());
        self::assertNotEmpty(Config::b2Bucket());
        self::assertNotEmpty(Config::b2Endpoint());
        self::assertNotEmpty(Config::b2Region());
    }

    public function testStreamTokenSecretReturnsString(): void
    {
        self::assertNotEmpty(Config::streamTokenSecret());
    }

    public function testStreamTokenTtlSecondsReturnsInt(): void
    {
        // phpunit.xml sets STREAM_TOKEN_TTL_SECONDS=3600
        self::assertSame(3600, Config::streamTokenTtlSeconds());
    }

    public function testAppBaseUrlReturnsString(): void
    {
        // phpunit.xml sets APP_BASE_URL=https://example.com
        self::assertSame('https://example.com', Config::appBaseUrl());
    }

    public function testAppBaseUrlStripsTrailingSlash(): void
    {
        $original = $_ENV['APP_BASE_URL'] ?? '';
        $_ENV['APP_BASE_URL'] = 'https://example.com/';
        self::assertSame('https://example.com', Config::appBaseUrl());
        $_ENV['APP_BASE_URL'] = $original;
    }

    public function testWorkDirStripsTrailingSlash(): void
    {
        $original = $_ENV['WORK_DIR'] ?? '';
        $_ENV['WORK_DIR'] = '/var/video-work/';
        self::assertSame('/var/video-work', Config::workDir());
        $_ENV['WORK_DIR'] = $original;
    }

    // -------------------------------------------------------------------------
    // KEY_ENCRYPTION_SECRET — valid 64-char hex → 32 raw bytes
    // -------------------------------------------------------------------------

    public function testKeyEncryptionSecretReturns32Bytes(): void
    {
        $key = Config::keyEncryptionSecret();

        self::assertIsString($key);
        self::assertSame(32, strlen($key));
    }

    public function testKeyEncryptionSecretDecodesHexCorrectly(): void
    {
        // phpunit.xml sets it to 0102...20 — verify first byte
        $key = Config::keyEncryptionSecret();

        self::assertSame("\x01", $key[0]);
    }

    // -------------------------------------------------------------------------
    // KEY_ENCRYPTION_SECRET — wrong length → RuntimeException
    // -------------------------------------------------------------------------

    public function testKeyEncryptionSecretTooShortThrows(): void
    {
        $original = $_ENV['KEY_ENCRYPTION_SECRET'];
        $_ENV['KEY_ENCRYPTION_SECRET'] = 'aabbcc'; // only 6 chars

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/64-character/');
            Config::keyEncryptionSecret();
        } finally {
            $_ENV['KEY_ENCRYPTION_SECRET'] = $original;
        }
    }

    // -------------------------------------------------------------------------
    // Required vars — missing → RuntimeException
    // -------------------------------------------------------------------------

    public function testMissingRequiredVarThrowsRuntimeException(): void
    {
        $original = $_ENV['DB_HOST'] ?? null;
        unset($_ENV['DB_HOST']);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/DB_HOST/');
            Config::dbHost();
        } finally {
            if ($original !== null) {
                $_ENV['DB_HOST'] = $original;
            }
        }
    }

    // -------------------------------------------------------------------------
    // Optional vars — defaults
    // -------------------------------------------------------------------------

    public function testFfmpegBinDefaultsToUsrBin(): void
    {
        $original = $_ENV['FFMPEG_BIN'] ?? null;
        unset($_ENV['FFMPEG_BIN']);

        self::assertSame('/usr/bin/ffmpeg', Config::ffmpegBin());

        if ($original !== null) {
            $_ENV['FFMPEG_BIN'] = $original;
        }
    }

    public function testFfprobeBinDefaultsToUsrBin(): void
    {
        $original = $_ENV['FFPROBE_BIN'] ?? null;
        unset($_ENV['FFPROBE_BIN']);

        self::assertSame('/usr/bin/ffprobe', Config::ffprobeBin());

        if ($original !== null) {
            $_ENV['FFPROBE_BIN'] = $original;
        }
    }

    public function testMaxUploadBytesDefaultIs8Gb(): void
    {
        $original = $_ENV['MAX_UPLOAD_BYTES'] ?? null;
        unset($_ENV['MAX_UPLOAD_BYTES']);

        self::assertSame(8589934592, Config::maxUploadBytes());

        if ($original !== null) {
            $_ENV['MAX_UPLOAD_BYTES'] = $original;
        }
    }

    public function testMinDiskFreeBytesDefaultIs20Gb(): void
    {
        $original = $_ENV['MIN_DISK_FREE_BYTES'] ?? null;
        unset($_ENV['MIN_DISK_FREE_BYTES']);

        self::assertSame(21474836480, Config::minDiskFreeBytes());

        if ($original !== null) {
            $_ENV['MIN_DISK_FREE_BYTES'] = $original;
        }
    }

    public function testStaleJobTimeoutMinutesDefaultIs30(): void
    {
        $original = $_ENV['STALE_JOB_TIMEOUT_MINUTES'] ?? null;
        unset($_ENV['STALE_JOB_TIMEOUT_MINUTES']);

        self::assertSame(30, Config::staleJobTimeoutMinutes());

        if ($original !== null) {
            $_ENV['STALE_JOB_TIMEOUT_MINUTES'] = $original;
        }
    }

    public function testWorkerPollIntervalDefaultIs5(): void
    {
        $original = $_ENV['WORKER_POLL_INTERVAL'] ?? null;
        unset($_ENV['WORKER_POLL_INTERVAL']);

        self::assertSame(5, Config::workerPollInterval());

        if ($original !== null) {
            $_ENV['WORKER_POLL_INTERVAL'] = $original;
        }
    }

    public function testCorsAllowedOriginDefaultsToEmpty(): void
    {
        $original = $_ENV['CORS_ALLOWED_ORIGIN'] ?? null;
        unset($_ENV['CORS_ALLOWED_ORIGIN']);

        self::assertSame('', Config::corsAllowedOrigin());

        if ($original !== null) {
            $_ENV['CORS_ALLOWED_ORIGIN'] = $original;
        }
    }
}
