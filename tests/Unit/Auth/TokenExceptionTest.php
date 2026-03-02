<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Auth\TokenException;

#[CoversClass(TokenException::class)]
final class TokenExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $e = new TokenException('test message');

        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testMessageIsAccessible(): void
    {
        $e = new TokenException('Invalid token signature.');

        self::assertSame('Invalid token signature.', $e->getMessage());
    }
}
