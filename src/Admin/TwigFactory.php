<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

/**
 * Creates and configures the Twig environment used by admin controllers.
 */
final class TwigFactory
{
    private static ?Environment $instance = null;
    private static ?array $poppedFlash = null;

    public static function create(): Environment
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $loader = new FilesystemLoader(__DIR__ . '/../../templates/admin');

        $twig = new Environment($loader, [
            'cache'       => false, // Enable file cache in production: __DIR__ . '/../../var/cache/twig'
            'auto_reload' => true,
            'autoescape'  => 'html',
        ]);

        // Global: current admin username from session
        $twig->addGlobal('admin_username', $_SESSION['admin_username'] ?? null);
        $twig->addGlobal('admin_id',       $_SESSION['admin_id']       ?? null);

        // Global: one-time flash message consumed by the template
        $twig->addGlobal('flash', self::popFlash());

        // Helper function: CSRF token for forms
        $twig->addFunction(new TwigFunction('csrf_token', function (): string {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return $_SESSION['csrf_token'];
        }));

        self::$instance = $twig;
        return $twig;
    }

    /**
     * Refresh session-dependent globals on the cached Twig instance.
     * Call this before rendering to pick up any session changes (login, flash, etc.).
     */
    public static function refreshSessionGlobals(): void
    {
        if (self::$instance === null) {
            return;
        }
        self::$instance->addGlobal('admin_username', $_SESSION['admin_username'] ?? null);
        self::$instance->addGlobal('admin_id',       $_SESSION['admin_id']       ?? null);
        self::$instance->addGlobal('flash',          self::$poppedFlash ?? $_SESSION['flash'] ?? null);
    }

    /**
     * Store a flash message for the next request.
     */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
        self::$poppedFlash = null; // invalidate cache so refreshSessionGlobals() picks up the new flash
    }

    /**
     * Consume and return flash message (or null if none).
     * @return array{type:string,message:string}|null
     */
    private static function popFlash(): ?array
    {
        if (isset($_SESSION['flash'])) {
            $flash = $_SESSION['flash'];
            unset($_SESSION['flash']);
            self::$poppedFlash = $flash;
            return $flash;
        }
        return null;
    }

    /**
     * Validate the CSRF token from a POST request.
     */
    public static function validateCsrf(string $token): bool
    {
        if (empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    /** Reset the singleton (for tests). */
    public static function reset(): void
    {
        self::$instance  = null;
        self::$poppedFlash = null;
    }
}
