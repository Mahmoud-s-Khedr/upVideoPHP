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
     *   fps: float,
     *   video_codec: string,
     *   audio_tracks: list<array{index: int, language: string, codec: string, channels: int, title:?string}>,
     *   subtitle_tracks: list<array{index: int, language: string, codec: string, forced: bool, title:?string}>,
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

        $output = self::$testShellExec !== null
            ? (self::$testShellExec)($cmd)
            : shell_exec($cmd);

        // ffprobe may emit diagnostic warnings (e.g. EBML quirks, unsupported
        // tags) to stderr *before* the JSON block when 2>&1 is used.
        // Strip everything before the first '{' so json_decode sees only JSON.
        $rawOutput  = $output ?? '';
        $jsonStart  = strpos($rawOutput, '{');
        $jsonString = $jsonStart !== false ? substr($rawOutput, $jsonStart) : '';
        $data       = json_decode($jsonString, associative: true);

        if (!is_array($data) || empty($data['streams'])) {
            $fileSize   = file_exists($filePath) ? filesize($filePath) : false;
            $rawPreview = mb_substr(trim($rawOutput), 0, 500);
            error_log(sprintf(
                '[FfprobeAnalyzer] No stream data — file: %s | size: %s | ffprobe output: %s',
                $filePath,
                $fileSize === false ? 'missing' : number_format((int) $fileSize) . ' bytes',
                $rawPreview !== '' ? $rawPreview : '(empty)'
            ));
            throw new \RuntimeException(sprintf(
                'ffprobe returned no stream data for: %s (file size: %s)',
                $filePath,
                $fileSize === false ? 'missing' : number_format((int) $fileSize) . ' bytes'
            ));
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
                    'title'    => self::normalizeTitle($stream['tags']['title'] ?? null),
                ];
            } elseif ($codecType === 'subtitle') {
                $subtitleTracks[] = [
                    'index'    => $subIndex++,
                    'language' => $stream['tags']['language'] ?? 'und',
                    'codec'    => $stream['codec_name'] ?? 'unknown',
                    'forced'   => (bool) ($stream['disposition']['forced'] ?? false),
                    'title'    => self::normalizeTitle($stream['tags']['title'] ?? null),
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
            'fps'             => self::parseFrameRate(
                (string) ($videoStream['avg_frame_rate'] ?? $videoStream['r_frame_rate'] ?? '')
            ),
            'video_codec'     => $videoStream['codec_name'] ?? 'unknown',
            'audio_tracks'    => $audioTracks,
            'subtitle_tracks' => $subtitleTracks,
        ];
    }

    private static function parseFrameRate(string $raw): float
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '0/0') {
            return 24.0;
        }

        if (str_contains($raw, '/')) {
            [$numerator, $denominator] = array_pad(explode('/', $raw, 2), 2, '0');
            $num = (float) $numerator;
            $den = (float) $denominator;

            if ($num > 0 && $den > 0) {
                return $num / $den;
            }
        }

        $fps = (float) $raw;
        return $fps > 0 ? $fps : 24.0;
    }

    private static function normalizeTitle(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
