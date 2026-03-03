<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Auth\EmbedToken;
use VideoSystem\Auth\EmbedTokenClaims;
use VideoSystem\Auth\TokenException;

#[CoversClass(EmbedToken::class)]
final class EmbedTokenTest extends TestCase
{
    private string $uuid = '550e8400-e29b-41d4-a716-446655440000';

    protected function tearDown(): void
    {
        EmbedToken::setTestNow(null);
    }

    public function testSignAndVerifyRoundTripWithHttpsOrigin(): void
    {
        $token  = EmbedToken::sign($this->uuid, 'https://example.com', 'viewer-123', 60);
        $claims = EmbedToken::verify($token);

        self::assertInstanceOf(EmbedTokenClaims::class, $claims);
        self::assertSame($this->uuid, $claims->videoUuid);
        self::assertSame('https://example.com', $claims->parentOrigin);
        self::assertSame('viewer-123', $claims->viewerRef);
    }

    public function testSignAndVerifyRoundTripWithEmptyViewerRef(): void
    {
        $token  = EmbedToken::sign($this->uuid, 'https://embed.example', '', 60);
        $claims = EmbedToken::verify($token);

        self::assertSame('', $claims->viewerRef);
        self::assertSame('https://embed.example', $claims->parentOrigin);
    }

    public function testExpiredTokenThrowsTokenException(): void
    {
        $now = time();
        EmbedToken::setTestNow($now);
        $token = EmbedToken::sign($this->uuid, 'https://example.com', 'viewer', 10);

        EmbedToken::setTestNow($now + 20);

        $this->expectException(TokenException::class);
        EmbedToken::verify($token);
    }

    public function testTamperedSignatureThrowsTokenException(): void
    {
        $token = EmbedToken::sign($this->uuid, 'https://example.com', 'viewer', 60);
        [$payload, ] = explode('.', $token, 2);
        $bad = $payload . '.' . rtrim(strtr(base64_encode(str_repeat("\x00", 32)), '+/', '-_'), '=');

        $this->expectException(TokenException::class);
        EmbedToken::verify($bad);
    }

    public function testMalformedPayloadThrowsTokenException(): void
    {
        $payload = '{"video_uuid":"bad"}';
        $token   = $this->signedTokenForPayload($payload);

        $this->expectException(TokenException::class);
        EmbedToken::verify($token);
    }

    private function signedTokenForPayload(string $payload): string
    {
        $ref    = new \ReflectionClass(EmbedToken::class);
        $method = $ref->getMethod('hmac');
        $method->setAccessible(true);
        $sig = $method->invoke(null, $payload);

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')
            . '.'
            . rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    }
}
