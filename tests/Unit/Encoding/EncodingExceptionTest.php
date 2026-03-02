<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\EncodingException;
use VideoSystem\Encoding\CancelledException;

#[CoversClass(EncodingException::class)]
#[CoversClass(CancelledException::class)]
final class EncodingExceptionTest extends TestCase
{
    // -------------------------------------------------------------------------
    // EncodingException
    // -------------------------------------------------------------------------

    public function testExtendsRuntimeException(): void
    {
        $e = new EncodingException('FFmpeg failed');

        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testDefaultIsNotNonRetryable(): void
    {
        $e = new EncodingException('Transient error');

        self::assertFalse($e->isNonRetryable());
    }

    public function testNonRetryableFlagStoredCorrectly(): void
    {
        $e = new EncodingException('moov atom not found', true);

        self::assertTrue($e->isNonRetryable());
    }

    public function testMessageAccessible(): void
    {
        $msg = 'Invalid data found when processing input';
        $e   = new EncodingException($msg, true);

        self::assertSame($msg, $e->getMessage());
    }

    public function testRetryableVariantIsRetryable(): void
    {
        $e = new EncodingException('Transient intermittent issue', false);

        self::assertFalse($e->isNonRetryable());
    }

    // -------------------------------------------------------------------------
    // CancelledException
    // -------------------------------------------------------------------------

    public function testCancelledExceptionExtendsRuntimeException(): void
    {
        $e = new CancelledException('Job was cancelled.');

        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testCancelledExceptionMessageAccessible(): void
    {
        $e = new CancelledException('Cancel requested by user.');

        self::assertSame('Cancel requested by user.', $e->getMessage());
    }
}
