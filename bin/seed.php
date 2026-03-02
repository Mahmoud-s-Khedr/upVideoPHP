#!/usr/bin/env php
<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// bin/seed.php — Development database seeder
// ---------------------------------------------------------------------------
// Usage:
//   php bin/seed.php
//   php bin/seed.php --admin-password=mysecret
//   php bin/seed.php --admin-user=superadmin --admin-password=mysecret
// ---------------------------------------------------------------------------

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;
use VideoSystem\Database\Seeders\AdminUserSeeder;
use VideoSystem\Database\Seeders\ApiKeySeeder;

// Load .env from project root
$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Parse CLI arguments
$opts          = getopt('', ['admin-user:', 'admin-password:']);
$adminUser     = $opts['admin-user']     ?? 'admin';
$adminPassword = $opts['admin-password'] ?? 'admin123';

echo "=== VideoSystem Database Seeder ===\n\n";

// ------------------------------------------------------------------
// 1. Admin user
// ------------------------------------------------------------------
echo "-- Admin users --\n";
AdminUserSeeder::run($adminUser, $adminPassword);

// ------------------------------------------------------------------
// 2. API keys
// ------------------------------------------------------------------
echo "\n-- API keys --\n";
ApiKeySeeder::run();

echo "\nDone.\n";
echo "IMPORTANT: copy the token values above — they are shown only once.\n";
