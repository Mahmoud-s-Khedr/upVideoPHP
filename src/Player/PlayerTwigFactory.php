<?php

declare(strict_types=1);

namespace VideoSystem\Player;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Twig environment for public player pages (embed + watch).
 * Separate from the admin TwigFactory — no session globals, no CSRF.
 */
final class PlayerTwigFactory
{
    private static ?Environment $instance = null;

    public static function create(): Environment
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $loader = new FilesystemLoader(__DIR__ . '/../../templates/player');

        self::$instance = new Environment($loader, [
            'cache'       => false,
            'auto_reload' => true,
            'autoescape'  => 'html',
        ]);

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}
