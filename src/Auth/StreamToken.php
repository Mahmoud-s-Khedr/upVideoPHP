<?php

declare(strict_types=1);

namespace VideoSystem\Auth;

use VideoSystem\Config\Config;

/**
 * HMAC-SHA256 signed stream tokens.
 *
 * Payload structure: {video_uuid}:{expires_at}:{ip}
 * Token wire format: base64url(<payload>).<base64url(HMAC-SHA256(secret, payload))>
 *
 * IP binding is optional — pass an empty string to skip IP verification.
 */
final class StreamToken
{
    /** @var int|null — override for current time in tests; null means use time() */
    private static ?int $testNow = null;

    /**
     * Override "current time" in tests. Pass null to restore real time().
     */
    public static function setTestNow(?int $timestamp): void
    {
        self::$testNow = $timestamp;
    }

    private static function now(): int
    {
        return self::$testNow ?? time();
    }

    /**
     * Sign a new token.
     *
     * @param string $videoUuid UUID of the video being accessed
     * @param string $ip        Client IP (pass '' to skip IP binding)
     * @param int    $ttl       Lifetime in seconds (default: from .env)
     */
    public static function sign(string $videoUuid, string $ip = '', int $ttl = 0): string
    {
        if ($ttl <= 0) {
            $ttl = Config::streamTokenTtlSeconds();
        }

        $expiresAt = self::now() + $ttl;
        $payload   = self::buildPayload($videoUuid, $expiresAt, $ip);
        $sig       = self::hmac($payload);

        return self::b64u($payload) . '.' . self::b64u($sig);
    }

    /**
     * Verify a token and return the video UUID it was issued for.
     *
     * @param string $token Raw token string (from cookie or query param)
     * @param string $ip    Client IP to verify against (pass '' to skip IP check)
     * @return string       Video UUID
     * @throws TokenException On invalid signature, expiry, or IP mismatch
     */
    public static function verify(string $token, string $ip = ''): string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new TokenException('Malformed token.');
        }

        [$encodedPayload, $encodedSig] = $parts;
        $payload = self::b64uDecode($encodedPayload);
        $sig     = self::b64uDecode($encodedSig);

        if (!hash_equals(self::hmac($payload), $sig)) {
            throw new TokenException('Invalid token signature.');
        }

        $parts = explode(':', $payload, 3);
        if (count($parts) !== 3) {
            throw new TokenException('Malformed token payload: expected uuid:expiry:ip format.');
        }

        [$uuid, $expiresAt, $boundIp] = $parts;

        if (self::now() > (int) $expiresAt) {
            throw new TokenException('Token has expired.');
        }

        if ($boundIp !== '' && $ip !== '' && !hash_equals($boundIp, $ip)) {
            throw new TokenException('Token IP binding mismatch.');
        }

        return $uuid;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function buildPayload(string $uuid, int $expiresAt, string $ip): string
    {
        return $uuid . ':' . $expiresAt . ':' . $ip;
    }

    private static function hmac(string $payload): string
    {
        return hash_hmac('sha256', $payload, Config::streamTokenSecret(), binary: true);
    }

    private static function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $data): string
    {
        $padded = strtr($data, '-_', '+/');
        $mod    = strlen($padded) % 4;
        if ($mod !== 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        $decoded = base64_decode($padded, strict: true);
        if ($decoded === false) {
            throw new TokenException('Malformed token encoding.');
        }
        return $decoded;
    }
}
