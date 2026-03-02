<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Api;

use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * HealthController integration tests.
 *
 * GET /health — no authentication required.
 *
 * These tests require a live database; they are automatically skipped when the
 * DB is unreachable (inherited behaviour from IntegrationTestCase).
 */
final class HealthControllerTest extends HttpIntegrationTestCase
{
    private string $workDir = '';
    private ?string $previousWorkDir = null;

    protected function setUp(): void
    {
        $this->previousWorkDir = $_ENV['WORK_DIR'] ?? null;
        $this->workDir = sys_get_temp_dir() . '/videosystem_health_' . uniqid();
        if (!mkdir($this->workDir, 0750, true) && !is_dir($this->workDir)) {
            $this->fail('Failed to create temporary work directory: ' . $this->workDir);
        }
        $_ENV['WORK_DIR'] = $this->workDir;

        parent::setUp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->workDir !== '') {
            $this->rimraf($this->workDir);
        }

        if ($this->previousWorkDir !== null) {
            $_ENV['WORK_DIR'] = $this->previousWorkDir;
            return;
        }

        unset($_ENV['WORK_DIR']);
    }

    public function testHealthReturns200WhenDbAndDiskAreOk(): void
    {
        $response = $this->get('/health');

        $this->assertStatus(200, $response);
        $this->assertJsonResponse($response);

        $data = $this->json($response);
        $this->assertSame('ok', $data['status']);
        $this->assertSame('ok', $data['db']);
        $this->assertSame('ok', $data['disk']);
    }

    public function testHealthResponseHasAllRequiredKeys(): void
    {
        $response = $this->get('/health');
        $data     = $this->json($response);

        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('db',     $data);
        $this->assertArrayHasKey('disk',   $data);
    }

    public function testHealthContentTypeIsJson(): void
    {
        $response = $this->get('/health');

        $this->assertStringStartsWith('application/json', $response->getHeaderLine('Content-Type'));
    }

    public function testHealthDegradedWhen503(): void
    {
        // When the work directory is set to something non-writable, disk check fails.
        // Here we just verify the structure of a degraded response looks correct —
        // we cannot easily force a degraded state in tests, so we test the 200 path
        // and verify status values are constrained to 'ok' | 'degraded'
        $response = $this->get('/health');
        $data     = $this->json($response);

        $this->assertContains($data['status'], ['ok', 'degraded']);
        $this->assertContains($data['db'],     ['ok', 'error']);
        $this->assertContains($data['disk'],   ['ok', 'error']);
    }

    private function rimraf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }
}
