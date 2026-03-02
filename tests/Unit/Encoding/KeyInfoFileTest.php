<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\KeyInfoFile;

/**
 * Tests the file I/O and cleanup behaviour of KeyInfoFile WITHOUT a real database.
 * The create() method requires a DB INSERT internally, so only cleanup and static
 * helper methods are tested here. DB-dependent behaviour lives in the integration suite.
 */
#[CoversClass(KeyInfoFile::class)]
final class KeyInfoFileTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $dir = sys_get_temp_dir() . '/keyinfo_test_' . uniqid('', true);
        mkdir($dir, 0700, true);
        $this->tmpDir = $dir;
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $file) {
            is_dir($file) ? $this->removeDir($file) : unlink($file);
        }
        rmdir($dir);
    }

    // -------------------------------------------------------------------------
    // cleanup() — removes only the expected temp files
    // -------------------------------------------------------------------------

    public function testCleanupRemovesKeyAndKeyInfoFiles(): void
    {
        $keyPath     = $this->tmpDir . '/enc_0.key';
        $keyInfoPath = $this->tmpDir . '/enc.keyinfo';

        // Simulate files created by create()
        file_put_contents($keyPath, str_repeat("\x00", 16));
        file_put_contents($keyInfoPath, "https://example.com/key\n/tmp/enc_0.key\nabcdef0123456789");

        $kif = new KeyInfoFile(1, $this->tmpDir, 0);
        $kif->cleanup();

        self::assertFileDoesNotExist($keyPath);
        self::assertFileDoesNotExist($keyInfoPath);
    }

    public function testCleanupIsIdempotentWhenFilesAlreadyGone(): void
    {
        $kif = new KeyInfoFile(1, $this->tmpDir, 0);

        // Should not throw even if files don't exist
        $kif->cleanup();
        $kif->cleanup();

        self::assertTrue(true); // no exception = pass
    }

    public function testCleanupDoesNotDeleteOtherFiles(): void
    {
        $other = $this->tmpDir . '/original.mp4';
        file_put_contents($other, 'some data');

        $kif = new KeyInfoFile(1, $this->tmpDir, 0);
        $kif->cleanup();

        self::assertFileExists($other);
    }

    // -------------------------------------------------------------------------
    // cleanupStaleFiles() — static glob-based cleanup
    // -------------------------------------------------------------------------

    public function testCleanupStaleFilesRemovesKeyFiles(): void
    {
        $key0 = $this->tmpDir . '/enc_0.key';
        $key1 = $this->tmpDir . '/enc_1.key';
        $info = $this->tmpDir . '/enc.keyinfo';

        file_put_contents($key0, str_repeat("\x01", 16));
        file_put_contents($key1, str_repeat("\x02", 16));
        file_put_contents($info, 'keyinfo content');

        KeyInfoFile::cleanupStaleFiles($this->tmpDir);

        self::assertFileDoesNotExist($key0);
        self::assertFileDoesNotExist($key1);
        self::assertFileDoesNotExist($info);
    }

    public function testCleanupStaleFilesLeaveOtherFilesIntact(): void
    {
        $other   = $this->tmpDir . '/seg00001.ts';
        $stale   = $this->tmpDir . '/enc_0.key';

        file_put_contents($other, 'ts data');
        file_put_contents($stale, str_repeat("\x00", 16));

        KeyInfoFile::cleanupStaleFiles($this->tmpDir);

        self::assertFileDoesNotExist($stale);
        self::assertFileExists($other);
    }

    public function testCleanupStaleFilesOnEmptyDirectoryDoesNotThrow(): void
    {
        KeyInfoFile::cleanupStaleFiles($this->tmpDir);

        self::assertTrue(true); // no exception = pass
    }

    public function testCleanupStaleFilesOnNonExistentDirectoryDoesNotThrow(): void
    {
        KeyInfoFile::cleanupStaleFiles('/tmp/totally_nonexistent_' . uniqid());

        self::assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Key index variants
    // -------------------------------------------------------------------------

    public function testDifferentKeyIndexesProduceDifferentFiles(): void
    {
        // Just verifies the internal path logic by checking cleanup removes the right file

        $key0 = $this->tmpDir . '/enc_0.key';
        $key1 = $this->tmpDir . '/enc_1.key';

        file_put_contents($key0, str_repeat("\x00", 16));
        file_put_contents($key1, str_repeat("\x00", 16));

        // Cleanup for index 0 should only remove enc_0.key
        $kif0 = new KeyInfoFile(1, $this->tmpDir, 0);
        $kif0->cleanup();

        self::assertFileDoesNotExist($key0);
        self::assertFileExists($key1);
    }
}
