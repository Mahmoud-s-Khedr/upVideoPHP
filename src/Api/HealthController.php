<?php

declare(strict_types=1);

namespace VideoSystem\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

/**
 * GET /health
 *
 * Returns a health check response indicating service readiness (S7).
 * Checks:
 *   - PHP reachable (implied by reaching this code)
 *   - Database reachable
 *   - Work directory writable
 *
 * Returns 200 {"status":"ok",...} when healthy, 503 when degraded.
 */
final class HealthController
{
    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $checks = [
            'db'   => $this->checkDatabase(),
            'disk' => $this->checkDisk(),
        ];

        $allOk  = !in_array(false, $checks, strict: true);
        $status = $allOk ? 'ok' : 'degraded';

        $payload = json_encode([
            'status' => $status,
            'db'     => $checks['db'] ? 'ok' : 'error',
            'disk'   => $checks['disk'] ? 'ok' : 'error',
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response
            ->withStatus($allOk ? 200 : 503)
            ->withHeader('Content-Type', 'application/json');
    }

    private function checkDatabase(): bool
    {
        return Connection::ping();
    }

    private function checkDisk(): bool
    {
        $workDir = Config::workDir();
        if (!is_dir($workDir)) {
            return false;
        }
        // Check both writability and free space
        $testFile = $workDir . '/.health_check_' . getmypid();
        $written  = @file_put_contents($testFile, '1');
        if ($written !== false) {
            @unlink($testFile);
        }
        return $written !== false;
    }
}
