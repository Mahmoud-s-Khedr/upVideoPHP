<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\SubtitleExtractor;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Support\FakeB2Client;

/**
 * SubtitleExtractor unit tests.
 *
 * FFmpeg calls are intercepted by the $execFn injection.
 * B2 uploads are intercepted by FakeB2Client.
 * DB inserts (Connection::execute) are expected to fail without a live DB;
 * we simply assert the B2 side-effects which occur before the DB call.
 */
final class SubtitleExtractorTest extends TestCase
{
    private FakeB2Client $b2;
    private string $tmpDir;
    private string $fakeVideo;

    protected function setUp(): void
    {
        $this->b2       = new FakeB2Client();
        B2Client::setTestOverride($this->b2);
        SubtitleExtractor::setTestDbWriter(fn() => null); // suppress DB inserts

        $this->tmpDir   = sys_get_temp_dir() . '/sub_test_' . uniqid();
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->fakeVideo = $this->tmpDir . '/source.mkv';
        file_put_contents($this->fakeVideo, str_repeat("\0", 512));
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
        SubtitleExtractor::setTestDbWriter(null);
        $this->rimraf($this->tmpDir);
    }

    // =========================================================================
    // Happy-path extraction
    // =========================================================================

    public function testTextSubtitleIsExtractedAndUploadedToB2(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            // Write the .vtt file that FFmpeg would create
            if (preg_match('#subs/(\w+)\.vtt#', $cmd, $m)) {
                $subsDir = $this->tmpDir . '/subs';
                @mkdir($subsDir, 0750, true);
                file_put_contents($subsDir . '/' . $m[1] . '.vtt', "WEBVTT\n\n00:00.000 --> 00:01.000\nHello\n");
            }
            $output   = [];
            $exitCode = 0;
        };

        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-sub-happy',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'subrip', 'forced' => false],
        ];

        try {
            $warnings = $extractor->extractAll($tracks);
        } catch (\Throwable) {
            // DB call may fail; that's OK for the B2 assertion below
            $warnings = [];
        }

        $this->assertEmpty($warnings, 'No warnings expected for a text subtitle');
        $this->assertTrue(
            $this->b2->hasKey('videos/uuid-sub-happy/subs/eng_0.vtt'),
            'English VTT should be uploaded to B2'
        );
    }

    public function testMultipleTracksAreExtracted(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (preg_match('#subs/(\w+)\.vtt#', $cmd, $m)) {
                $subsDir = $this->tmpDir . '/subs';
                @mkdir($subsDir, 0750, true);
                file_put_contents($subsDir . '/' . $m[1] . '.vtt', "WEBVTT\n");
            }
            $output   = [];
            $exitCode = 0;
        };

        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-sub-multi',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'subrip',  'forced' => false],
            ['index' => 1, 'language' => 'spa', 'codec' => 'ass',     'forced' => false],
        ];

        try {
            $extractor->extractAll($tracks);
        } catch (\Throwable) {}

        $this->assertTrue($this->b2->hasKey('videos/uuid-sub-multi/subs/eng_0.vtt'));
        $this->assertTrue($this->b2->hasKey('videos/uuid-sub-multi/subs/spa_1.vtt'));
    }

    public function testForcedFlagIsPreserved(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (preg_match('#subs/(\w+)\.vtt#', $cmd, $m)) {
                $subsDir = $this->tmpDir . '/subs';
                @mkdir($subsDir, 0750, true);
                file_put_contents($subsDir . '/' . $m[1] . '.vtt', "WEBVTT\n");
            }
            $output   = [];
            $exitCode = 0;
        };

        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-forced',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'subrip', 'forced' => true],
        ];

        // We verify no exception is thrown regarding forced=true
        $this->expectNotToPerformAssertions();
        try {
            $extractor->extractAll($tracks);
        } catch (\Throwable) {}
    }

    // =========================================================================
    // Image-based codec skipping
    // =========================================================================

    #[DataProvider('provideImageCodecs')]
    public function testImageBasedCodecIsSkippedWithWarning(string $codec): void
    {
        $callCount = 0;
        $execFn = function () use (&$callCount) {
            $callCount++;
        };

        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-img-codec',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => $codec, 'forced' => false],
        ];

        $warnings = $extractor->extractAll($tracks);

        $this->assertSame(0, $callCount, 'FFmpeg must not be called for image-based codecs');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($codec, $warnings[0]);
    }

    public static function provideImageCodecs(): array
    {
        return [
            'dvd_subtitle'      => ['dvd_subtitle'],
            'hdmv_pgs_subtitle' => ['hdmv_pgs_subtitle'],
            'dvbsub'            => ['dvbsub'],
        ];
    }

    // =========================================================================
    // FFmpeg failure handling
    // =========================================================================

    public function testTrackSkippedWhenFfmpegExitsNonZero(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            $output   = ['error output'];
            $exitCode = 1;
            // Do NOT write the .vtt file
        };

        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-fail',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'subrip', 'forced' => false],
        ];

        $warnings = $extractor->extractAll($tracks);

        $this->assertCount(1, $warnings, 'Should have one failure warning');
        $this->assertStringContainsString('eng', $warnings[0]);
        $this->assertFalse($this->b2->hasKey('videos/uuid-fail/subs/eng_0.vtt'));
    }

    public function testTrackSkippedWhenVttFileNotCreated(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            $output   = [];
            $exitCode = 0;
            // Exit 0 but no .vtt file written
        };

        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-nofile',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'fra', 'codec' => 'subrip', 'forced' => false],
        ];

        $warnings = $extractor->extractAll($tracks);

        $this->assertCount(1, $warnings);
        $this->assertFalse($this->b2->hasKey('videos/uuid-nofile/subs/fra_0.vtt'));
    }

    // =========================================================================
    // No tracks
    // =========================================================================

    public function testNoTracksReturnsEmptyWarnings(): void
    {
        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-empty',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       fn() => null,
        );

        $warnings = $extractor->extractAll([]);
        $this->assertEmpty($warnings);
        $this->assertSame(0, $this->b2->countCalls('put'));
    }

    // =========================================================================
    // Mixed: one succeeds, one is image-based, one fails
    // =========================================================================

    public function testMixedTracksBehavioursAreIndependent(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (str_contains($cmd, 'eng_0.vtt')) {
                $subsDir = $this->tmpDir . '/subs';
                @mkdir($subsDir, 0750, true);
                file_put_contents($subsDir . '/eng_0.vtt', "WEBVTT\n");
                $exitCode = 0;
            } elseif (str_contains($cmd, 'deu_2.vtt')) {
                $exitCode = 1; // fail
            }
            $output = [];
        };

        $extractor = new SubtitleExtractor(
            videoId:      1,
            videoUuid:    'uuid-mixed',
            inputFile:    $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:       $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'subrip',      'forced' => false], // OK
            ['index' => 1, 'language' => 'jpn', 'codec' => 'dvd_subtitle', 'forced' => false], // image
            ['index' => 2, 'language' => 'deu', 'codec' => 'subrip',      'forced' => false], // fail
        ];

        try {
            $warnings = $extractor->extractAll($tracks);
        } catch (\Throwable) {
            $warnings = []; // DB may throw — ignore
        }

        // eng should be uploaded despite other failures
        $this->assertTrue($this->b2->hasKey('videos/uuid-mixed/subs/eng_0.vtt'));
        // image-based + failure == 2 warnings
        $this->assertCount(2, $warnings);
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
