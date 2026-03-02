<?php

declare(strict_types=1);

namespace VideoSystem\Worker;

/**
 * Process-wide graceful shutdown flag.
 *
 * Using a static property ensures that async signal handlers (pcntl_async_signals)
 * can set the flag and ALL code paths — including RenditionPipeline — see the
 * updated value immediately, without requiring reference passing through call stacks.
 */
final class ShutdownFlag
{
    private static bool $requested = false;

    public static function request(): void
    {
        self::$requested = true;
    }

    public static function isRequested(): bool
    {
        return self::$requested;
    }

    public static function reset(): void
    {
        self::$requested = false;
    }
}
