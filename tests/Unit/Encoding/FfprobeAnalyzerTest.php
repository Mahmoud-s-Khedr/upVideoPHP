<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\FfprobeAnalyzer;

/**
 * FfprobeAnalyzer — injects a fake shell_exec callback so no real binary is needed.
 */
final class FfprobeAnalyzerTest extends TestCase
{
    protected function setUp(): void
    {
        FfprobeAnalyzer::setTestShellExec(null);
    }

    protected function tearDown(): void
    {
        FfprobeAnalyzer::setTestShellExec(null);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function fakeOutput(array $streams = [], array $format = []): string
    {
        return json_encode([
            'streams' => $streams,
            'format'  => array_merge(['duration' => '120.000000'], $format),
        ], JSON_THROW_ON_ERROR);
    }

    private function videoStream(int $width = 1280, int $height = 720, string $codec = 'h264'): array
    {
        return [
            'codec_type' => 'video',
            'codec_name' => $codec,
            'width'      => $width,
            'height'     => $height,
            'avg_frame_rate' => '24000/1001',
        ];
    }

    private function audioStream(string $language = 'eng', string $codec = 'aac', int $channels = 2): array
    {
        return [
            'codec_type' => 'audio',
            'codec_name' => $codec,
            'channels'   => $channels,
            'tags'       => ['language' => $language],
        ];
    }

    private function subtitleStream(string $language = 'eng', string $codec = 'subrip', bool $forced = false): array
    {
        return [
            'codec_type'  => 'subtitle',
            'codec_name'  => $codec,
            'tags'        => ['language' => $language],
            'disposition' => ['forced' => $forced ? 1 : 0],
        ];
    }

    // =========================================================================
    // Happy path
    // =========================================================================

    public function testReturnsCorrectStructureForSimpleVideo(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([
            $this->videoStream(1920, 1080),
        ]));

        $result = FfprobeAnalyzer::analyze('/fake/video.mp4');

        $this->assertSame(120.0, $result['duration']);
        $this->assertSame(1920, $result['width']);
        $this->assertSame(1080, $result['height']);
        $this->assertSame('h264', $result['video_codec']);
        $this->assertEqualsWithDelta(23.976, $result['fps'], 0.01);
        $this->assertEmpty($result['audio_tracks']);
        $this->assertEmpty($result['subtitle_tracks']);
    }

    public function testParsesMultipleAudioTracks(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([
            $this->videoStream(),
            $this->audioStream('eng', 'aac', 2),
            $this->audioStream('fra', 'ac3', 6),
        ]));

        $result = FfprobeAnalyzer::analyze('/fake/video.mkv');

        $this->assertCount(2, $result['audio_tracks']);
        $this->assertSame(0, $result['audio_tracks'][0]['index']);
        $this->assertSame('eng', $result['audio_tracks'][0]['language']);
        $this->assertSame('aac', $result['audio_tracks'][0]['codec']);
        $this->assertSame(2, $result['audio_tracks'][0]['channels']);
        $this->assertSame(1, $result['audio_tracks'][1]['index']);
        $this->assertSame('fra', $result['audio_tracks'][1]['language']);
    }

    public function testParsesSubtitleTracks(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([
            $this->videoStream(),
            $this->subtitleStream('eng', 'subrip', false),
            $this->subtitleStream('spa', 'subrip', true),
        ]));

        $result = FfprobeAnalyzer::analyze('/fake/video.mkv');

        $this->assertCount(2, $result['subtitle_tracks']);
        $this->assertSame(0, $result['subtitle_tracks'][0]['index']);
        $this->assertSame('eng', $result['subtitle_tracks'][0]['language']);
        $this->assertFalse($result['subtitle_tracks'][0]['forced']);
        $this->assertSame(1, $result['subtitle_tracks'][1]['index']);
        $this->assertTrue($result['subtitle_tracks'][1]['forced']);
    }

    public function testDurationParsedCorrectly(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput(
            [$this->videoStream()],
            ['duration' => '3661.500000']
        ));

        $result = FfprobeAnalyzer::analyze('/fake/video.mp4');
        $this->assertEqualsWithDelta(3661.5, $result['duration'], 0.001);
    }

    public function testOnlyFirstVideoStreamIsUsed(): void
    {
        // Two video streams — only the first should be picked
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([
            $this->videoStream(1920, 1080, 'h264'),
            $this->videoStream(640, 480, 'hevc'),
        ]));

        $result = FfprobeAnalyzer::analyze('/fake/video.mp4');

        $this->assertSame(1920, $result['width']);
        $this->assertSame(1080, $result['height']);
        $this->assertSame('h264', $result['video_codec']);
    }

    public function testMissingLanguageTagDefaultsToUnd(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([
            $this->videoStream(),
            [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels'   => 2,
                // no 'tags' key at all
            ],
        ]));

        $result = FfprobeAnalyzer::analyze('/fake/video.mp4');
        $this->assertSame('und', $result['audio_tracks'][0]['language']);
    }

    public function testParsesSuccessfullyWhenFfprobeEmitsWarningBeforeJson(): void
    {
        // Reproduces the real-world failure with Theora-in-MKV files that cause
        // ffprobe to print an EBML warning line before the JSON block:
        //   [matroska,webm @ 0x...] Length 5 indicated by an EBML number's first
        //   byte 0x0a at pos 35 (0x23) exceeds max length 4.
        //   { "streams": [...] }
        $warningPrefix = "[matroska,webm @ 0x5607063fc180] Length 5 indicated by an EBML number's"
            . " first byte 0x0a at pos 35 (0x23) exceeds max length 4.\n";
        $json = $this->fakeOutput([$this->videoStream(1280, 720, 'theora')]);

        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $warningPrefix . $json);

        $result = FfprobeAnalyzer::analyze('/fake/video.mkv');

        $this->assertSame('theora', $result['video_codec']);
        $this->assertSame(720, $result['height']);
    }

    // =========================================================================
    // Error paths
    // =========================================================================

    public function testThrowsWhenShellExecReturnsNull(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => null);
        $this->expectException(\RuntimeException::class);
        FfprobeAnalyzer::analyze('/fake/video.mp4');
    }

    public function testThrowsWhenShellExecReturnsMalformedJson(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => 'not-json-at-all');
        $this->expectException(\RuntimeException::class);
        FfprobeAnalyzer::analyze('/fake/video.mp4');
    }

    public function testThrowsWhenNoStreamsKey(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => json_encode(['format' => ['duration' => '10']]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no stream data');
        FfprobeAnalyzer::analyze('/fake/video.mp4');
    }

    public function testThrowsWhenNoVideoStream(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([
            $this->audioStream(),
        ]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No video stream');
        FfprobeAnalyzer::analyze('/fake/video.mp4');
    }

    public function testThrowsOnZeroDimensions(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([
            [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width'      => 0,
                'height'     => 0,
            ],
        ]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid video dimensions');
        FfprobeAnalyzer::analyze('/fake/video.mp4');
    }

    public function testThrowsOnEmptyStreamsList(): void
    {
        FfprobeAnalyzer::setTestShellExec(fn($cmd) => $this->fakeOutput([]));
        $this->expectException(\RuntimeException::class);
        FfprobeAnalyzer::analyze('/fake/video.mp4');
    }
}
