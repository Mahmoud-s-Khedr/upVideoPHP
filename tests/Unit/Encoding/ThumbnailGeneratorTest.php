<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\ThumbnailGenerator;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Support\FakeB2Client;

/**
 * ThumbnailGenerator unit tests.
 *
 * FFmpeg calls are intercepted via the $execFn constructor injection.
 * B2 uploads are intercepted via FakeB2Client::setTestOverride().
 * DB calls (Connection::execute) are suppressed by writing real temp files
 * so the DB branch is never reached — poster/sprite are only inserted when
 * file_exists() returns true AND exitCode === 0.
 */
final class ThumbnailGeneratorTest extends TestCase
{
    private FakeB2Client $b2;
    private string $tmpDir;
    private string $fakeVideo;

    protected function setUp(): void
    {
        $this->b2       = new FakeB2Client();
        B2Client::setTestOverride($this->b2);
        ThumbnailGenerator::setTestDbWriter(fn() => null); // suppress DB writes

        $this->tmpDir   = sys_get_temp_dir() . '/tg_test_' . uniqid();
        mkdir($this->tmpDir, 0750, recursive: true);

        // Create a dummy "video" file (content doesn't matter; FFmpeg is faked)
        $this->fakeVideo = $this->tmpDir . '/source.mp4';
        file_put_contents($this->fakeVideo, str_repeat("\0", 512));
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
        ThumbnailGenerator::setTestDbWriter(null);
        $this->rimraf($this->tmpDir);
    }

    // =========================================================================
    // Poster tests
    // =========================================================================

    public function testPosterIsUploadedToB2OnSuccess(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            // Simulate FFmpeg writing poster.jpg
            if (str_contains($cmd, 'poster.jpg')) {
                file_put_contents($this->tmpDir . '/poster.jpg', 'JPEG_DATA');
            }
            $output   = [];
            $exitCode = 0;
        };

        $gen = new ThumbnailGenerator(
            videoId:      1,
            videoUuid:    'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            durationSec:  30.0,    // Under 60 s → no sprite
            execFn:       $execFn,
        );

        // We expect a DB call via Connection::execute — but since there's no DB
        // in the unit suite we use a try/catch; the B2 assertion is what matters
        try {
            $gen->generate();
        } catch (\Throwable) {
            // DB call failed — that's fine for this unit test
        }

        $this->assertTrue(
            $this->b2->hasKey('videos/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/poster.jpg'),
            'Poster should be uploaded to B2'
        );
    }

    public function testPosterIsSkippedWhenFfmpegFails(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            $output   = ['error: codec not found'];
            $exitCode = 1;
            // Do NOT write poster.jpg
        };

        $gen = new ThumbnailGenerator(
            videoId:      1,
            videoUuid:    'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            durationSec:  30.0,
            execFn:       $execFn,
        );

        try {
            $gen->generate();
        } catch (\Throwable) {}

        $this->assertFalse(
            $this->b2->hasKey('videos/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/poster.jpg'),
            'Poster should not be in B2 when FFmpeg failed'
        );
    }

    public function testPosterIsSkippedWhenFileDoesNotExist(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            // Succeed with exit code 0 but do NOT write poster.jpg
            $output   = [];
            $exitCode = 0;
        };

        $gen = new ThumbnailGenerator(
            videoId:      1,
            videoUuid:    'uuuu-iiii',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            durationSec:  30.0,
            execFn:       $execFn,
        );

        try {
            $gen->generate();
        } catch (\Throwable) {}

        $this->assertFalse($this->b2->hasKey('videos/uuuu-iiii/poster.jpg'));
    }

    // =========================================================================
    // Sprite sheet tests
    // =========================================================================

    public function testSpriteIsGeneratedForLongVideo(): void
    {
        $calls = 0;
        $execFn = function (string $cmd, array &$output, int &$exitCode) use (&$calls) {
            $calls++;
            if (str_contains($cmd, 'poster.jpg')) {
                file_put_contents($this->tmpDir . '/poster.jpg', 'JPEG_DATA');
            }
            if (str_contains($cmd, 'sprite.jpg')) {
                file_put_contents($this->tmpDir . '/sprite.jpg', 'JPEG_SPRITE');
            }
            $output   = [];
            $exitCode = 0;
        };

        $gen = new ThumbnailGenerator(
            videoId:      1,
            videoUuid:    'sprite-test-uuid',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            durationSec:  120.0,   // Over 60 s → sprite should be generated
            execFn:       $execFn,
        );

        try {
            $gen->generate();
        } catch (\Throwable) {}

        $this->assertSame(2, $calls, 'FFmpeg should be called twice (poster + sprite)');
        $this->assertTrue($this->b2->hasKey('videos/sprite-test-uuid/sprite.jpg'));
    }

    public function testSpriteIsSkippedForShortVideo(): void
    {
        $calls = 0;
        $execFn = function (string $cmd, array &$output, int &$exitCode) use (&$calls) {
            $calls++;
            if (str_contains($cmd, 'poster.jpg')) {
                file_put_contents($this->tmpDir . '/poster.jpg', 'JPEG_DATA');
            }
            $output   = [];
            $exitCode = 0;
        };

        $gen = new ThumbnailGenerator(
            videoId:      1,
            videoUuid:    'short-video-uuid',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            durationSec:  60.0,    // Exactly 60 s — NOT greater than → no sprite
            execFn:       $execFn,
        );

        try {
            $gen->generate();
        } catch (\Throwable) {}

        $this->assertSame(1, $calls, 'FFmpeg should be called once (poster only)');
        $this->assertFalse($this->b2->hasKey('videos/short-video-uuid/sprite.jpg'));
    }

    public function testSpriteIsSkippedForVeryShortVideo(): void
    {
        $calls = 0;
        $execFn = function (string $cmd, array &$output, int &$exitCode) use (&$calls) {
            $calls++;
            file_put_contents($this->tmpDir . '/poster.jpg', 'JPEG_DATA');
            $output   = [];
            $exitCode = 0;
        };

        $gen = new ThumbnailGenerator(
            videoId:      1,
            videoUuid:    'very-short-uuid',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            durationSec:  5.0,
            execFn:       $execFn,
        );

        try {
            $gen->generate();
        } catch (\Throwable) {}

        $this->assertSame(1, $calls);
    }

    public function testSpriteNotUploadedWhenFfmpegFails(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (str_contains($cmd, 'poster.jpg')) {
                file_put_contents($this->tmpDir . '/poster.jpg', 'JPEG_DATA');
                $exitCode = 0;
            } else {
                // Sprite generation fails
                $exitCode = 1;
            }
            $output = [];
        };

        $gen = new ThumbnailGenerator(
            videoId:      1,
            videoUuid:    'sprite-fail-uuid',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            durationSec:  120.0,
            execFn:       $execFn,
        );

        try {
            $gen->generate();
        } catch (\Throwable) {}

        $this->assertFalse($this->b2->hasKey('videos/sprite-fail-uuid/sprite.jpg'));
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
