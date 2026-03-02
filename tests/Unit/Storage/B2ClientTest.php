<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Support\FakeB2Client;

/**
 * B2Client unit tests.
 *
 * Verifies that the test-override seam works correctly: when a FakeB2Client
 * is installed, all static B2Client methods delegate to it.
 *
 * Note: We do NOT test with real S3/B2 credentials here — that belongs in an
 * integration/E2E suite where credentials are available.
 */
final class B2ClientTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/b2test_' . uniqid();
        mkdir($this->tmpDir, 0750, recursive: true);
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
        $this->rimraf($this->tmpDir);
    }

    // =========================================================================
    // Override slot lifecycle
    // =========================================================================

    public function testGetTestOverrideReturnsNullByDefault(): void
    {
        $this->assertNull(B2Client::getTestOverride());
    }

    public function testSetAndGetTestOverride(): void
    {
        $fake = new FakeB2Client();
        B2Client::setTestOverride($fake);
        $this->assertSame($fake, B2Client::getTestOverride());
    }

    public function testSetTestOverrideToNullClearsOverride(): void
    {
        B2Client::setTestOverride(new FakeB2Client());
        B2Client::setTestOverride(null);
        $this->assertNull(B2Client::getTestOverride());
    }

    // =========================================================================
    // put() delegates to override
    // =========================================================================

    public function testPutDelegatesToFakeClient(): void
    {
        $fake = new FakeB2Client();
        B2Client::setTestOverride($fake);

        $file = $this->tmpDir . '/hello.bin';
        file_put_contents($file, 'test content');

        B2Client::put('my/key.bin', $file, 'application/octet-stream');

        $this->assertTrue($fake->hasKey('my/key.bin'));
        $this->assertSame('test content', $fake->read('my/key.bin'));
        $this->assertSame(1, $fake->countCalls('put'));
    }

    // =========================================================================
    // putContent() delegates to override
    // =========================================================================

    public function testPutContentDelegatesToFakeClient(): void
    {
        $fake = new FakeB2Client();
        B2Client::setTestOverride($fake);

        B2Client::putContent('inline/key.txt', 'hello world', 'text/plain');

        $this->assertTrue($fake->hasKey('inline/key.txt'));
        $this->assertSame('hello world', $fake->read('inline/key.txt'));
        $this->assertSame(1, $fake->countCalls('putContent'));
    }

    // =========================================================================
    // getContent() delegates to override
    // =========================================================================

    public function testGetContentDelegatesToFakeClient(): void
    {
        $fake = new FakeB2Client();
        $fake->seed('remote/data.txt', 'remote content');
        B2Client::setTestOverride($fake);

        $content = B2Client::getContent('remote/data.txt');

        $this->assertSame('remote content', $content);
    }

    public function testGetContentThrowsForMissingKey(): void
    {
        $fake = new FakeB2Client();
        B2Client::setTestOverride($fake);

        $this->expectException(\RuntimeException::class);
        B2Client::getContent('does/not/exist');
    }

    // =========================================================================
    // exists() delegates to override
    // =========================================================================

    public function testExistsTrueWhenKeyPresent(): void
    {
        $fake = new FakeB2Client();
        $fake->seed('exists/file.txt', 'data');
        B2Client::setTestOverride($fake);

        $this->assertTrue(B2Client::exists('exists/file.txt'));
    }

    public function testExistsFalseWhenKeyAbsent(): void
    {
        $fake = new FakeB2Client();
        B2Client::setTestOverride($fake);

        $this->assertFalse(B2Client::exists('no/such/key'));
    }

    // =========================================================================
    // delete() delegates to override
    // =========================================================================

    public function testDeleteRemovesKey(): void
    {
        $fake = new FakeB2Client();
        $fake->seed('to/delete.txt', 'bye');
        B2Client::setTestOverride($fake);

        B2Client::delete('to/delete.txt');

        $this->assertFalse($fake->hasKey('to/delete.txt'));
        $this->assertSame(1, $fake->countCalls('delete'));
    }

    // =========================================================================
    // deleteObjects() delegates to override
    // =========================================================================

    public function testDeleteObjectsRemovesMultipleKeys(): void
    {
        $fake = new FakeB2Client();
        $fake->seed('seg/001.ts', 'a');
        $fake->seed('seg/002.ts', 'b');
        $fake->seed('seg/003.ts', 'c');
        B2Client::setTestOverride($fake);

        B2Client::deleteObjects(['seg/001.ts', 'seg/002.ts']);

        $this->assertFalse($fake->hasKey('seg/001.ts'));
        $this->assertFalse($fake->hasKey('seg/002.ts'));
        $this->assertTrue($fake->hasKey('seg/003.ts'), 'Non-deleted key should remain');
    }

    // =========================================================================
    // listObjects() delegates to override
    // =========================================================================

    public function testListObjectsReturnsPrefixedKeys(): void
    {
        $fake = new FakeB2Client();
        $fake->seed('videos/abc/720p/index.m3u8', 'playlist');
        $fake->seed('videos/abc/720p/seg001.ts',  'seg');
        $fake->seed('videos/xyz/720p/seg001.ts',  'other');
        B2Client::setTestOverride($fake);

        $keys = B2Client::listObjects('videos/abc/');

        $this->assertContains('videos/abc/720p/index.m3u8', $keys);
        $this->assertContains('videos/abc/720p/seg001.ts',  $keys);
        $this->assertNotContains('videos/xyz/720p/seg001.ts', $keys);
    }

    // =========================================================================
    // deletePrefix() delegates to override
    // =========================================================================

    public function testDeletePrefixRemovesAllMatchingkeys(): void
    {
        $fake = new FakeB2Client();
        $fake->seed('videos/abc/720p/index.m3u8', 'playlist');
        $fake->seed('videos/abc/720p/seg001.ts',  'seg');
        $fake->seed('videos/def/720p/seg001.ts',  'other');
        B2Client::setTestOverride($fake);

        B2Client::deletePrefix('videos/abc/');

        $this->assertFalse($fake->hasKey('videos/abc/720p/index.m3u8'));
        $this->assertFalse($fake->hasKey('videos/abc/720p/seg001.ts'));
        $this->assertTrue($fake->hasKey('videos/def/720p/seg001.ts'));
    }

    // =========================================================================
    // presignUrl() delegates to override
    // =========================================================================

    public function testPresignUrlReturnsFakeUrl(): void
    {
        $fake = new FakeB2Client();
        B2Client::setTestOverride($fake);

        $url = B2Client::presignUrl('videos/abc/source.mp4', 3600);

        $this->assertStringContainsString('videos/abc/source.mp4', $url);
        $this->assertStringContainsString('3600', $url);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function rimraf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
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
