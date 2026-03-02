<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Config\Config;

/**
 * Runs ffprobe and returns structured stream information.
 *
 * Test seam: call FfprobeAnalyzer::setTestShellExec(fn($cmd) => '...')
 * to inject canned JSON output, then reset with null after the test.
 */
final class FfprobeAnalyzer
{
    /** @var (callable(string): ?string)|null Overrides shell_exec in tests. */
    private static $testShellExec = null;

    public static function setTestShellExec(?callable $fn): void
    {
        self::$testShellExec = $fn;
    }
    /**
     * @return array{
     *   duration: float,
     *   width: int,
     *   height: int,
     *   video_codec: string,
     *   audio_tracks: list<array{index: int, language: string, codec: string, channels: int}>,
     *   subtitle_tracks: list<array{index: int, language: string, codec: string, forced: bool}>,
     * }
     *
     * @throws \RuntimeException if ffprobe fails or no video stream is found
     */
    public static function analyze(string $filePath): array
    {
        $cmd = sprintf(
            '%s -v error -print_format json -show_streams -show_format %s 2>&1',
            escapeshellarg(Config::ffprobeBin()),
            escapeshellarg($filePath)
        );

        $output   = self::$testShellExec !== null
            ? (self::$testShellExec)($cmd)
            : shell_exec($cmd);
        $data     = json_decode($output ?? '', associative: true);

        if (!is_array($data) || empty($data['streams'])) {
            throw new \RuntimeException('ffprobe returned no stream data for: ' . $filePath);
        }

        $duration     = (float) ($data['format']['duration'] ?? 0.0);
        $videoStream  = null;
        $audioTracks  = [];
        $subtitleTracks = [];
        $audioIndex   = 0;
        $subIndex     = 0;

        foreach ($data['streams'] as $stream) {
            $codecType = $stream['codec_type'] ?? '';

            if ($codecType === 'video' && $videoStream === null) {
                $videoStream = $stream;
            } elseif ($codecType === 'audio') {
                $audioTracks[] = [
                    'index'    => $audioIndex++,
                    'language' => $stream['tags']['language'] ?? 'und',
                    'codec'    => $stream['codec_name'] ?? 'unknown',
                    'channels' => (int) ($stream['channels'] ?? 2),
                ];
            } elseif ($codecType === 'subtitle') {
                $subtitleTracks[] = [
                    'index'    => $subIndex++,
                    'language' => $stream['tags']['language'] ?? 'und',
                    'codec'    => $stream['codec_name'] ?? 'unknown',
                    'forced'   => (bool) ($stream['disposition']['forced'] ?? false),
                ];
            }
        }

        if ($videoStream === null) {
            throw new \RuntimeException('No video stream found in: ' . $filePath);
        }

        $width  = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            throw new \RuntimeException('Invalid video dimensions detected in: ' . $filePath);
        }

        return [
            'duration'        => $duration,
            'width'           => $width,
            'height'          => $height,
            'video_codec'     => $videoStream['codec_name'] ?? 'unknown',
            'audio_tracks'    => $audioTracks,
            'subtitle_tracks' => $subtitleTracks,
        ];
    }
}
