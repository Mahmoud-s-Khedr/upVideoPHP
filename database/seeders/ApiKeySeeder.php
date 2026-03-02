<?php

declare(strict_types=1);

namespace VideoSystem\Database\Seeders;

use VideoSystem\Database\Connection;

/**
 * Seeds the api_keys table with two development keys:
 *   1. dev-full-access  — can_upload=1, can_stream=1
 *   2. dev-stream-only  — can_upload=0, can_stream=1
 *
 * The raw tokens are printed to stdout. They are never stored in the database.
 */
final class ApiKeySeeder
{
    /** @return list<array{name:string,token:string}> */
    public static function run(): array
    {
        $keys = [
            [
                'name'       => 'dev-full-access',
                'can_upload' => 1,
                'can_stream' => 1,
            ],
            [
                'name'       => 'dev-stream-only',
                'can_upload' => 0,
                'can_stream' => 1,
            ],
        ];

        $created = [];
        $pdo     = Connection::get();

        foreach ($keys as $key) {
            // Skip if a key with this name already exists
            $existing = Connection::fetch(
                'SELECT id FROM api_keys WHERE name = :name LIMIT 1',
                ['name' => $key['name']]
            );
            if ($existing !== null) {
                echo "  [skip] api_key '{$key['name']}' already exists\n";
                continue;
            }

            $rawToken = bin2hex(random_bytes(32)); // 64 hex chars
            $hash     = password_hash($rawToken, PASSWORD_BCRYPT);

            Connection::execute(
                'INSERT INTO api_keys (name, key_hash, can_upload, can_stream)
                 VALUES (:name, :hash, :can_upload, :can_stream)',
                [
                    'name'       => $key['name'],
                    'hash'       => $hash,
                    'can_upload' => $key['can_upload'],
                    'can_stream' => $key['can_stream'],
                ]
            );

            $created[] = ['name' => $key['name'], 'token' => $rawToken];
            echo "  [ok]   api_key '{$key['name']}' created  token={$rawToken}\n";
        }

        return $created;
    }
}
