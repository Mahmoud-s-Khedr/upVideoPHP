<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Auth\StreamToken;
use VideoSystem\Auth\TokenException;

#[CoversClass(StreamToken::class)]
final class StreamTokenTest extends TestCase
{
    private string $uuid = '550e8400-e29b-41d4-a716-446655440000';

    protected function tearDown(): void
    {
        StreamToken::setTestNow(null);
    }

    // -------------------------------------------------------------------------
    // sign + verify — happy paths
    // -------------------------------------------------------------------------

    public function testSignAndVerifyRoundTrip(): void
    {
        $token = StreamToken::sign($this->uuid, '', 60);
        $result = StreamToken::verify($token);

        self::assertSame($this->uuid, $result);
    }

    public function testSignAndVerifyWithIpBinding(): void
    {
        $ip    = '203.0.113.42';
        $token = StreamToken::sign($this->uuid, $ip, 60);
        $result = StreamToken::verify($token, $ip);

        self::assertSame($this->uuid, $result);
    }

    public function testIpBoundTokenAcceptsMatchingIp(): void
    {
        $ip    = '10.0.0.1';
        $token = StreamToken::sign($this->uuid, $ip, 60);

        self::assertSame($this->uuid, StreamToken::verify($token, $ip));
    }

    public function testTokenWithoutIpBindingIgnoresCallerIp(): void
    {
        // signed with no IP — verifying with any IP must succeed
        $token = StreamToken::sign($this->uuid, '', 60);

        self::assertSame($this->uuid, StreamToken::verify($token, '99.99.99.99'));
    }

    public function testNoIpOnVerifySkipsIpCheck(): void
    {
        // Even if token was IP-bound, passing '' to verify skips the check
        $ip    = '192.168.1.1';
        $token = StreamToken::sign($this->uuid, $ip, 60);

        self::assertSame($this->uuid, StreamToken::verify($token, ''));
    }

    // -------------------------------------------------------------------------
    // TTL defaults to Config value
    // -------------------------------------------------------------------------

    public function testZeroTtlFallsBackToConfigDefault(): void
    {
        // phpunit.xml sets STREAM_TOKEN_TTL_SECONDS=3600 → token valid for 1 h
        $token = StreamToken::sign($this->uuid, '', 0);

        self::assertSame($this->uuid, StreamToken::verify($token));
    }

    // -------------------------------------------------------------------------
    // Expired tokens
    // -------------------------------------------------------------------------

    public function testExpiredTokenThrowsTokenException(): void
    {
        // Sign a valid 10-second token, then advance the clock past expiry
        $now   = time();
        StreamToken::setTestNow($now);
        $token = StreamToken::sign($this->uuid, '', 10);

        // Advance clock by 20 seconds so the token is definitely expired
        StreamToken::setTestNow($now + 20);

        $this->expectException(TokenException::class);
        StreamToken::verify($token);
    }

    // -------------------------------------------------------------------------
    // Tampered tokens
    // -------------------------------------------------------------------------

    public function testTamperedSignatureThrowsTokenException(): void
    {
        $token = StreamToken::sign($this->uuid, '', 60);
        [$payload, ] = explode('.', $token, 2);
        $bad = $payload . '.' . rtrim(strtr(base64_encode(str_repeat("\x00", 32)), '+/', '-_'), '=');

        $this->expectException(TokenException::class);
        StreamToken::verify($bad);
    }

    public function testTamperedPayloadThrowsTokenException(): void
    {
        $token = StreamToken::sign($this->uuid, '', 60);

        // Replace the encoded payload (first segment before .)
        [$payload, $sig] = explode('.', $token, 2);
        $tampered = rtrim(strtr(base64_encode('evil-uuid:9999999999:'), '+/', '-_'), '=') . '.' . $sig;

        $this->expectException(TokenException::class);
        StreamToken::verify($tampered);
    }

    // -------------------------------------------------------------------------
    // Malformed tokens
    // -------------------------------------------------------------------------

    public function testMissingDotSeparatorThrowsTokenException(): void
    {
        $this->expectException(TokenException::class);
        StreamToken::verify('nodotinthisstring');
    }

    public function testEmptyTokenThrowsTokenException(): void
    {
        $this->expectException(TokenException::class);
        StreamToken::verify('');
    }

    public function testGarbageBase64ThrowsTokenException(): void
    {
        $this->expectException(TokenException::class);
        StreamToken::verify('!!!.???');
    }

    // -------------------------------------------------------------------------
    // Wrong IP
    // -------------------------------------------------------------------------

    public function testIpBoundTokenRejectsWrongIp(): void
    {
        $token = StreamToken::sign($this->uuid, '10.0.0.1', 60);

        $this->expectException(TokenException::class);
        StreamToken::verify($token, '10.0.0.2');
    }

    // -------------------------------------------------------------------------
    // Token structure
    // -------------------------------------------------------------------------

    public function testTokenHasExactlyOneDotSeparator(): void
    {
        $token = StreamToken::sign($this->uuid, '', 60);
        $parts = explode('.', $token);

        self::assertCount(2, $parts, 'Token must be exactly two base64url parts separated by a dot.');
    }

    public function testTokenPartsAreBase64UrlEncoded(): void
    {
        $token = StreamToken::sign($this->uuid, '', 60);
        [$payload, $sig] = explode('.', $token, 2);

        // base64url must not contain +, /, or = padding
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $payload);
        self::assertDoesNotMatchRegularExpression('/[+\/=]/', $sig);
    }
}
