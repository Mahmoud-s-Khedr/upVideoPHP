<?php

declare(strict_types=1);

namespace VideoSystem\Auth;

use VideoSystem\Config\Config;

/**
 * HMAC-SHA256 signed embed tokens.
 *
 * Payload: {video_uuid}:{parent_origin}:{viewer_ref}:{expires_at}
 * Wire format: base64url(<payload>).base64url(HMAC-SHA256(secret, payload))
 *
 * Embed tokens gate access to the /embed/{token} HTML page and bootstrap JSON.
 * They are separate from StreamTokens which gate HLS playlist/segment/key access.
 */
final class EmbedToken
{
    /** @var int|null — override for current time in tests */
    private static ?int $testNow = null;

    public static function setTestNow(?int $timestamp): void
    {
        self::$testNow = $timestamp;
    }

    private static function now(): int
    {
        return self::$testNow ?? time();
    }

    /**
     * Sign a new embed token.
     *
     * @param string $videoUuid    UUID of the video
     * @param string $parentOrigin Allowed parent origin (e.g. "https://client-site.example")
     * @param string $viewerRef    Optional external viewer reference
     * @param int    $ttl          Lifetime in seconds (default: from config)
     */
    public static function sign(string $videoUuid, string $parentOrigin, string $viewerRef = '', int $ttl = 0): string
    {
        if ($ttl <= 0) {
            $ttl = Config::embedTokenTtlSeconds();
        }

        $expiresAt = self::now() + $ttl;
        $payload   = self::buildPayload($videoUuid, $parentOrigin, $viewerRef, $expiresAt);
        $sig       = self::hmac($payload);

        return self::b64u($payload) . '.' . self::b64u($sig);
    }

    /**
     * Verify an embed token and return its claims.
     *
     * @return EmbedTokenClaims
     * @throws TokenException On invalid signature, expiry, or malformed token
     */
    public static function verify(string $token): EmbedTokenClaims
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new TokenException('Malformed embed token.');
        }

        [$encodedPayload, $encodedSig] = $parts;
        $payload = self::b64uDecode($encodedPayload);
        $sig     = self::b64uDecode($encodedSig);

        if (!hash_equals(self::hmac($payload), $sig)) {
            throw new TokenException('Invalid embed token signature.');
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            throw new TokenException('Malformed embed token payload.');
        }

        $uuid         = $decoded['video_uuid'] ?? null;
        $parentOrigin = $decoded['parent_origin'] ?? null;
        $viewerRef    = $decoded['viewer_ref'] ?? null;
        $expiresAt    = $decoded['expires_at'] ?? null;

        if (!is_string($uuid)
            || !is_string($parentOrigin)
            || !is_string($viewerRef)
            || !is_int($expiresAt)) {
            throw new TokenException('Malformed embed token payload.');
        }

        if (self::now() > $expiresAt) {
            throw new TokenException('Embed token has expired.');
        }

        return new EmbedTokenClaims($uuid, $parentOrigin, $viewerRef, $expiresAt);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function buildPayload(string $uuid, string $parentOrigin, string $viewerRef, int $expiresAt): string
    {
        return json_encode([
            'video_uuid'    => $uuid,
            'parent_origin' => $parentOrigin,
            'viewer_ref'    => $viewerRef,
            'expires_at'    => $expiresAt,
        ], JSON_THROW_ON_ERROR);
    }

    private static function hmac(string $payload): string
    {
        return hash_hmac('sha256', $payload, Config::embedTokenSecret(), binary: true);
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
            throw new TokenException('Malformed embed token encoding.');
        }
        return $decoded;
    }
}
