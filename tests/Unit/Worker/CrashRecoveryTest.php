<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Worker;

use PHPUnit\Framework\TestCase;
use VideoSystem\Worker\CrashRecovery;

/**
 * CrashRecovery unit tests.
 *
 * Tests the filesystem helpers that require no network or database access.
 * B2-related methods (precleanB2) are covered in the Integration suite
 * where a FakeB2Client is available.
 */
final class CrashRecoveryTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/cr_test_' . uniqid();
        mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        // Safety net — test should have cleaned up, but don't leave temp dirs
        if (is_dir($this->tmpDir)) {
            $this->rimraf($this->tmpDir);
        }
    }

    // =========================================================================
    // deleteDirectory
    // =========================================================================

    public function testDeleteDirectoryRemovesEmptyDir(): void
    {
        $dir = $this->tmpDir . '/empty';
        mkdir($dir);

        CrashRecovery::deleteDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryRemovesDirWithFiles(): void
    {
        $dir = $this->tmpDir . '/filled';
        mkdir($dir);
        file_put_contents($dir . '/a.ts', 'segment');
        file_put_contents($dir . '/b.ts', 'segment');

        CrashRecovery::deleteDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryRemovesNestedStructure(): void
    {
        $dir = $this->tmpDir . '/nested';
        mkdir($dir . '/sub/dir', 0750, recursive: true);
        file_put_contents($dir . '/sub/dir/file.txt', 'data');
        file_put_contents($dir . '/sub/other.m3u8', 'playlist');
        file_put_contents($dir . '/root.key', 'key');

        CrashRecovery::deleteDirectory($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testDeleteDirectoryIsIdempotentWhenDirDoesNotExist(): void
    {
        $nonexistent = $this->tmpDir . '/does_not_exist';

        // Must not throw
        CrashRecovery::deleteDirectory($nonexistent);

        $this->assertDirectoryDoesNotExist($nonexistent); // sanity
    }

    public function testDeleteDirectoryDoesNotAffectSiblings(): void
    {
        $target  = $this->tmpDir . '/to_delete';
        $sibling = $this->tmpDir . '/sibling';
        mkdir($target);
        mkdir($sibling);
        file_put_contents($sibling . '/keep.txt', 'important');

        CrashRecovery::deleteDirectory($target);

        $this->assertDirectoryDoesNotExist($target);
        $this->assertDirectoryExists($sibling);
        $this->assertFileExists($sibling . '/keep.txt');
    }

    // =========================================================================
    // scanForStaleKeyFiles
    // =========================================================================

    public function testScanForStaleKeyFilesRemovesKeyAndKeyinfoFiles(): void
    {
        $processingDir = $this->tmpDir . '/processing';
        mkdir($processingDir);

        // Create stale files that KeyInfoFile::cleanupStaleFiles would remove
        file_put_contents($processingDir . '/enc.keyinfo', "enc_0.key\nhttps://...\n");
        file_put_contents($processingDir . '/enc_0.key', random_bytes(16));
        file_put_contents($processingDir . '/enc_1.key', random_bytes(16));

        CrashRecovery::scanForStaleKeyFiles($processingDir);

        // KeyInfoFile::cleanupStaleFiles removes *.key and *.keyinfo files
        $remaining = glob($processingDir . '/*.key') ?: [];
        $this->assertEmpty($remaining, '*.key files should be removed');

        $keyinfos = glob($processingDir . '/*.keyinfo') ?: [];
        $this->assertEmpty($keyinfos, '*.keyinfo files should be removed');
    }

    public function testScanForStaleKeyFilesIsNoopWhenDirAbsent(): void
    {
        // Should not throw even if the directory doesn't exist
        CrashRecovery::scanForStaleKeyFiles($this->tmpDir . '/nonexistent');
        $this->addToAssertionCount(1); // no exception = pass
    }

    public function testScanForStaleKeyFilesLeavesOtherFilesIntact(): void
    {
        $processingDir = $this->tmpDir . '/proc2';
        mkdir($processingDir);

        // Stale key file
        file_put_contents($processingDir . '/enc_0.key', random_bytes(16));

        // Files that should be preserved
        file_put_contents($processingDir . '/source.mp4', 'video_data');
        mkdir($processingDir . '/720p');
        file_put_contents($processingDir . '/720p/seg00001.ts', 'segment_data');

        CrashRecovery::scanForStaleKeyFiles($processingDir);

        $this->assertFileExists($processingDir . '/source.mp4', 'Source file should be preserved');
        $this->assertFileExists($processingDir . '/720p/seg00001.ts', 'Segment should be preserved');
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function rimraf(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
