<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Worker;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Worker\ShutdownFlag;

#[CoversClass(ShutdownFlag::class)]
final class ShutdownFlagTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ShutdownFlag::reset();
    }

    protected function tearDown(): void
    {
        // Always clean up after each test so state does not leak
        ShutdownFlag::reset();
        parent::tearDown();
    }

    public function testFlagIsFalseByDefault(): void
    {
        self::assertFalse(ShutdownFlag::isRequested());
    }

    public function testRequestSetsFlag(): void
    {
        ShutdownFlag::request();

        self::assertTrue(ShutdownFlag::isRequested());
    }

    public function testResetClearsFlag(): void
    {
        ShutdownFlag::request();
        ShutdownFlag::reset();

        self::assertFalse(ShutdownFlag::isRequested());
    }

    public function testMultipleRequestCallsStayTrue(): void
    {
        ShutdownFlag::request();
        ShutdownFlag::request();

        self::assertTrue(ShutdownFlag::isRequested());
    }

    public function testResetAfterResetIsIdempotent(): void
    {
        ShutdownFlag::reset();
        ShutdownFlag::reset();

        self::assertFalse(ShutdownFlag::isRequested());
    }
}
