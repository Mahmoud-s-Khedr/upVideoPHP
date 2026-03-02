<?php

declare(strict_types=1);

namespace VideoSystem\Database\Seeders;

use VideoSystem\Database\Connection;

/**
 * Seeds the admin_users table with an initial admin user.
 */
final class AdminUserSeeder
{
    public static function run(string $username = 'admin', string $password = 'admin123'): void
    {
        // Skip if already exists
        $existing = Connection::fetch(
            'SELECT id FROM admin_users WHERE username = :username LIMIT 1',
            ['username' => $username]
        );

        if ($existing !== null) {
            echo "  [skip] admin user '{$username}' already exists\n";
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        Connection::execute(
            'INSERT INTO admin_users (username, password_hash) VALUES (:username, :hash)',
            ['username' => $username, 'hash' => $hash]
        );

        echo "  [ok]   admin user '{$username}' created\n";
    }
}
