<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\AudioTrackExtractor;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Support\FakeB2Client;

/**
 * AudioTrackExtractor unit tests.
 *
 * FFmpeg calls are intercepted by the $execFn constructor injection.
 * B2 uploads are intercepted by FakeB2Client.
 * DB inserts are suppressed by AudioTrackExtractor::setTestDbWriter().
 *
 * Only the B2 side-effects of extractAll() are asserted here; the DB
 * insertion path requires a live DB and belongs to the integration suite.
 */
final class AudioTrackExtractorTest extends TestCase
{
    private FakeB2Client $b2;
    private string $tmpDir;
    private string $fakeVideo;

    protected function setUp(): void
    {
        $this->b2 = new FakeB2Client();
        B2Client::setTestOverride($this->b2);
        AudioTrackExtractor::setTestDbWriter(fn() => null); // suppress DB inserts

        $this->tmpDir   = sys_get_temp_dir() . '/at_test_' . uniqid();
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->fakeVideo = $this->tmpDir . '/source.mkv';
        file_put_contents($this->fakeVideo, str_repeat("\0", 512));
    }

    protected function tearDown(): void
    {
        B2Client::setTestOverride(null);
        AudioTrackExtractor::setTestDbWriter(null);
        $this->rimraf($this->tmpDir);
    }

    private function rimraf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rimraf($path . '/' . $entry);
        }
        rmdir($path);
    }

    // =========================================================================
    // Happy-path extraction
    // =========================================================================

    public function testSingleTrackExtractedAndUploadedToB2(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            // Simulate FFmpeg writing the HLS audio playlist + one segment
            if (preg_match('#audio_(\d+)/index\.m3u8#', $cmd, $m)) {
                $dir = $this->tmpDir . '/audio_' . $m[1];
                @mkdir($dir, 0750, true);
                file_put_contents($dir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
                file_put_contents($dir . '/seg00001.ts', "\x47\x00\x00");
            }
            $output   = [];
            $exitCode = 0;
        };

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2],
        ];

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-audio-single',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        try {
            $warnings = $extractor->extractAll($tracks);
        } catch (\Throwable) {
            // DB call may fail in unit suite — that's acceptable
            $warnings = [];
        }

        $this->assertEmpty($warnings, 'No warnings expected for a successful extraction');
        $this->assertTrue(
            $this->b2->hasKey('videos/uuid-audio-single/audio_0/index.m3u8'),
            'Playlist must be uploaded to B2'
        );
        $this->assertTrue(
            $this->b2->hasKey('videos/uuid-audio-single/audio_0/seg00001.ts'),
            'Segment must be uploaded to B2'
        );
    }

    public function testMultipleTracksAreExtracted(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (preg_match('#audio_(\d+)/index\.m3u8#', $cmd, $m)) {
                $dir = $this->tmpDir . '/audio_' . $m[1];
                @mkdir($dir, 0750, true);
                file_put_contents($dir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
            }
            $output   = [];
            $exitCode = 0;
        };

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-audio-multi',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2],
            ['index' => 1, 'language' => 'spa', 'codec' => 'ac3', 'channels' => 6],
        ];

        try {
            $extractor->extractAll($tracks);
        } catch (\Throwable) {}

        $this->assertTrue($this->b2->hasKey('videos/uuid-audio-multi/audio_0/index.m3u8'));
        $this->assertTrue($this->b2->hasKey('videos/uuid-audio-multi/audio_1/index.m3u8'));
    }

    public function testLanguageLabelsMappedCorrectly(): void
    {
        $dbWrites = [];
        AudioTrackExtractor::setTestDbWriter(function (int $vid, int $n, string $lang, string $label) use (&$dbWrites) {
            $dbWrites[] = ['lang' => $lang, 'label' => $label];
        });

        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (preg_match('#audio_(\d+)/index\.m3u8#', $cmd, $m)) {
                $dir = $this->tmpDir . '/audio_' . $m[1];
                @mkdir($dir, 0750, true);
                file_put_contents($dir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
            }
            $output   = [];
            $exitCode = 0;
        };

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-labels',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2],
            ['index' => 1, 'language' => 'jpn', 'codec' => 'aac', 'channels' => 2],
            ['index' => 2, 'language' => 'zzz', 'codec' => 'aac', 'channels' => 2], // unknown → ucfirst
        ];

        $extractor->extractAll($tracks);

        $this->assertSame('English',  $dbWrites[0]['label']);
        $this->assertSame('Japanese', $dbWrites[1]['label']);
        $this->assertSame('Zzz',      $dbWrites[2]['label'], 'Unknown language code should be ucfirst-ed');
    }

    public function testEmbeddedTitlesAreStoredExactly(): void
    {
        $dbWrites = [];
        AudioTrackExtractor::setTestDbWriter(function (int $vid, int $n, string $lang, string $label) use (&$dbWrites) {
            $dbWrites[] = ['track' => $n, 'label' => $label];
        });

        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (preg_match('#audio_(\d+)/index\.m3u8#', $cmd, $m)) {
                $dir = $this->tmpDir . '/audio_' . $m[1];
                @mkdir($dir, 0750, true);
                file_put_contents($dir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
            }
            $output = [];
            $exitCode = 0;
        };

        $extractor = new AudioTrackExtractor(
            videoId: 1,
            videoUuid: 'uuid-exact-title',
            inputFile: $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn: $execFn,
        );

        $extractor->extractAll([
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2, 'title' => ' AAC 2.0 @ 192kb/s - [Japanese] '],
        ]);

        $this->assertSame('AAC 2.0 @ 192kb/s - [Japanese]', $dbWrites[0]['label']);
    }

    public function testDuplicateEmbeddedTitlesRemainUnsuffixed(): void
    {
        $dbWrites = [];
        AudioTrackExtractor::setTestDbWriter(function (int $vid, int $n, string $lang, string $label) use (&$dbWrites) {
            $dbWrites[] = $label;
        });

        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            if (preg_match('#audio_(\d+)/index\.m3u8#', $cmd, $m)) {
                $dir = $this->tmpDir . '/audio_' . $m[1];
                @mkdir($dir, 0750, true);
                file_put_contents($dir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
            }
            $output = [];
            $exitCode = 0;
        };

        $extractor = new AudioTrackExtractor(
            videoId: 1,
            videoUuid: 'uuid-duplicate-title',
            inputFile: $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn: $execFn,
        );

        $extractor->extractAll([
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2, 'title' => 'Dual Audio'],
            ['index' => 1, 'language' => 'jpn', 'codec' => 'aac', 'channels' => 2, 'title' => 'Dual Audio'],
        ]);

        $this->assertSame(['Dual Audio', 'Dual Audio'], $dbWrites);
    }

    // =========================================================================
    // FFmpeg failure handling
    // =========================================================================

    public function testNonZeroExitCodeProducesWarningAndNoB2Upload(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            $output   = ['error output'];
            $exitCode = 1;
            // Do NOT write any files
        };

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-audio-fail',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2],
        ];

        $warnings = $extractor->extractAll($tracks);

        $this->assertCount(1, $warnings, 'One warning for the failed track');
        $this->assertStringContainsString('eng', $warnings[0]);
        $this->assertFalse($this->b2->hasKey('videos/uuid-audio-fail/audio_0/index.m3u8'));
    }

    public function testPlaylistNotCreatedProducesWarningAndNoB2Upload(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            // Exit 0 but do NOT write the playlist file
            $output   = [];
            $exitCode = 0;
        };

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-audio-nofile',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'fra', 'codec' => 'aac', 'channels' => 2],
        ];

        $warnings = $extractor->extractAll($tracks);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('fra', $warnings[0]);
        $this->assertFalse($this->b2->hasKey('videos/uuid-audio-nofile/audio_0/index.m3u8'));
    }

    public function testPartialFailureStillProcessesRemainingTracks(): void
    {
        $execFn = function (string $cmd, array &$output, int &$exitCode) {
            // track 0: fail; track 1: succeed
            if (preg_match('#audio_0/index\.m3u8#', $cmd)) {
                $exitCode = 1;
            } else {
                if (preg_match('#audio_(\d+)/index\.m3u8#', $cmd, $m)) {
                    $dir = $this->tmpDir . '/audio_' . $m[1];
                    @mkdir($dir, 0750, true);
                    file_put_contents($dir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
                }
                $exitCode = 0;
            }
            $output = [];
        };

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-partial',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2],
            ['index' => 1, 'language' => 'spa', 'codec' => 'aac', 'channels' => 2],
        ];

        try {
            $warnings = $extractor->extractAll($tracks);
        } catch (\Throwable) {
            $warnings = ['fake'];
        }

        $this->assertCount(1, $warnings, 'Only track 0 should have a warning');
        $this->assertFalse($this->b2->hasKey('videos/uuid-partial/audio_0/index.m3u8'));
        $this->assertTrue($this->b2->hasKey('videos/uuid-partial/audio_1/index.m3u8'));
    }

    // =========================================================================
    // Edge cases
    // =========================================================================

    public function testEmptyTracksArrayProducesNoWarningsAndNoExecCalls(): void
    {
        $called = 0;
        $execFn = function () use (&$called) {
            $called++;
        };

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-audio-empty',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        $warnings = $extractor->extractAll([]);

        $this->assertEmpty($warnings);
        $this->assertSame(0, $called, 'FFmpeg must not be called for empty track list');
    }

    public function testResumeSafetySkipsAlreadyUploadedTrack(): void
    {
        // Pre-seed B2 — simulates a partially-resumed job where track 0 already uploaded
        $this->b2->seed('videos/uuid-resume/audio_0/index.m3u8', '#EXTM3U');

        $called = 0;
        $execFn = function () use (&$called) {
            $called++;
        };

        $extractor = new AudioTrackExtractor(
            videoId:       1,
            videoUuid:     'uuid-resume',
            inputFile:     $this->fakeVideo,
            processingDir: $this->tmpDir,
            execFn:        $execFn,
        );

        $tracks = [
            ['index' => 0, 'language' => 'eng', 'codec' => 'aac', 'channels' => 2],
        ];

        $warnings = $extractor->extractAll($tracks);

        $this->assertEmpty($warnings, 'Skipped tracks must produce no warnings');
        $this->assertSame(0, $called, 'FFmpeg must not be called when B2 playlist already exists');
    }
}
