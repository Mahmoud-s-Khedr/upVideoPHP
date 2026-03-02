<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Upload;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Upload\ValidationException;

#[CoversClass(ValidationException::class)]
final class ValidationExceptionTest extends TestCase
{
    public function testExtendsRuntimeException(): void
    {
        $e = new ValidationException('FILE_TOO_LARGE', 'File exceeds maximum size.');

        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testDefaultHttpStatusIs422(): void
    {
        $e = new ValidationException('INVALID_MIME', 'Bad MIME type.');

        self::assertSame(422, $e->getHttpStatus());
    }

    public function testCustomHttpStatusStoredCorrectly(): void
    {
        $e = new ValidationException('UNAUTHORIZED', 'Not authorised.', 401);

        self::assertSame(401, $e->getHttpStatus());
    }

    public function testGetErrorCodeReturnsCorrectCode(): void
    {
        $e = new ValidationException('INVALID_FILE_MAGIC', 'Magic bytes mismatch.');

        self::assertSame('INVALID_FILE_MAGIC', $e->getErrorCode());
    }

    public function testMessageAccessible(): void
    {
        $message = 'No video stream detected in the file.';
        $e = new ValidationException('NO_VIDEO_STREAM', $message);

        self::assertSame($message, $e->getMessage());
    }

    public function testAllKnownErrorCodes(): void
    {
        $codes = [
            'FILE_TOO_LARGE',
            'INVALID_MIME',
            'INVALID_FILE_MAGIC',
            'INVALID_VIDEO',
            'NO_VIDEO_STREAM',
            'UNAUTHORIZED',
        ];

        foreach ($codes as $code) {
            $e = new ValidationException($code, 'test');
            self::assertSame($code, $e->getErrorCode(), "Error code '{$code}' not stored correctly.");
        }
    }
}
