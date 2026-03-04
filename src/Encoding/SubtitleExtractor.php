<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * Extracts subtitle tracks from a source video and converts them to WebVTT.
 *
 * Image-based subtitle codecs (dvd_subtitle, hdmv_pgs_subtitle) are skipped
 * with a warning — they do not cause the job to fail.
 */
final class SubtitleExtractor
{
    private const IMAGE_BASED_CODECS = ['dvd_subtitle', 'hdmv_pgs_subtitle', 'dvbsub'];

    private const LANGUAGE_LABELS = [
        'eng' => 'English',
        'spa' => 'Spanish',
        'fra' => 'French',
        'deu' => 'German',
        'ita' => 'Italian',
        'jpn' => 'Japanese',
        'por' => 'Portuguese',
        'rus' => 'Russian',
        'chi' => 'Chinese',
        'ara' => 'Arabic',
        'kor' => 'Korean',
        'und' => 'Unknown',
    ];

    public function __construct(
        private readonly int     $videoId,
        private readonly string  $videoUuid,
        private readonly string  $inputFile,
        private readonly string  $processingDir,
        private readonly ?\Closure $execFn = null,
        private readonly ?\Closure $heartbeatFn = null,
    ) {}

    /** @var (callable(int, string, string, string, bool): void)|null — replaces DB insert in tests. */
    private static $testDbWriter = null;

    public static function setTestDbWriter(?callable $fn): void
    {
        self::$testDbWriter = $fn;
    }

    /**
     * Extract all eligible subtitle tracks. Returns warnings for skipped tracks.
     *
     * @param list<array{index: int, language: string, codec: string, forced: bool, title?: ?string}> $subtitleTracks
     * @return list<string> warning messages for skipped tracks
     */
    public function extractAll(array $subtitleTracks): array
    {
        $warnings  = [];
        $subsDir   = $this->processingDir . '/subs';
        $labels    = $this->buildLabels($subtitleTracks);

        if (!empty($subtitleTracks)) {
            @mkdir($subsDir, 0750, recursive: true);
        }

        foreach ($subtitleTracks as $idx => $track) {
            if (in_array($track['codec'], self::IMAGE_BASED_CODECS, true)) {
                $warnings[] = sprintf(
                    "Subtitle track %d (codec: %s, lang: %s) is image-based; skipped because OCR pipeline is disabled.",
                    $track['index'], $track['codec'], $track['language']
                );
                continue;
            }

            $lang     = $track['language'];
            $trackIdx = $track['index'];
            $vttFile  = $lang . '_' . $trackIdx . '.vtt';
            $vttPath  = $subsDir . '/' . $vttFile;

            $cmd = sprintf(
                '%s -y -i %s -map 0:s:%d -c:s webvtt %s',
                escapeshellarg(Config::ffmpegBin()),
                escapeshellarg($this->inputFile),
                $trackIdx,
                escapeshellarg($vttPath)
            );

            $this->runExec($cmd, $output, $exitCode);

            if ($exitCode !== 0 || !file_exists($vttPath)) {
                $warnings[] = sprintf(
                    "Failed to extract subtitle track %d (lang: %s, codec: %s); skipped.",
                    $trackIdx, $lang, $track['codec']
                );
                continue;
            }

            // Upload VTT to B2 — filename includes track index to avoid language collisions
            $b2Key = "videos/{$this->videoUuid}/subs/{$lang}_{$trackIdx}.vtt";
            B2Client::put($b2Key, $vttPath, 'text/vtt');

            // Insert into subtitles table
            if (self::$testDbWriter !== null) {
                (self::$testDbWriter)($this->videoId, $lang, $labels[$idx], $b2Key, $track['forced']);
            } else {
                Connection::execute(
                    'INSERT INTO subtitles (video_id, track_index, language_code, label, is_forced, b2_vtt_key)
                     VALUES (:vid, :tidx, :lang, :label, :forced, :key)
                     ON DUPLICATE KEY UPDATE
                         language_code = VALUES(language_code),
                         label         = VALUES(label),
                         is_forced     = VALUES(is_forced),
                         b2_vtt_key    = VALUES(b2_vtt_key)',
                    [
                        ':vid'    => $this->videoId,
                        ':tidx'   => $trackIdx,
                        ':lang'   => $lang,
                        ':label'  => $labels[$idx],
                        ':forced' => $track['forced'] ? 1 : 0,
                        ':key'    => $b2Key,
                    ]
                );
            }
        }

        return $warnings;
    }

    /**
     * @param list<array{language:string,title?:?string}> $tracks
     * @return list<string>
     */
    private function buildLabels(array $tracks): array
    {
        return array_map(function (array $track): string {
            $title = trim((string) ($track['title'] ?? ''));
            if ($title !== '') {
                return $title;
            }

            return self::LANGUAGE_LABELS[$track['language']] ?? ucfirst($track['language']);
        }, $tracks);
    }

    /**
     * Run a shell command via exec(), or use the injected $execFn in tests.
     */
    private function runExec(string $cmd, mixed &$output, mixed &$exitCode): void
    {
        $output   = [];
        $exitCode = 0;
        if ($this->execFn !== null) {
            ($this->execFn)($cmd, $output, $exitCode);
        } else {
            $this->runProcessWithHeartbeat($cmd, $output, $exitCode);
        }
    }

    private function runProcessWithHeartbeat(string $cmd, mixed &$output, mixed &$exitCode): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if ($process === false) {
            $output   = [];
            $exitCode = 1;
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $lastHeartbeat = microtime(true);

        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read === []) {
                break;
            }

            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 1, 0);

            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk !== false && $chunk !== '' && $stream === $pipes[1]) {
                    $stdout .= $chunk;
                }
            }

            if ($this->heartbeatFn !== null && (microtime(true) - $lastHeartbeat) >= 30.0) {
                ($this->heartbeatFn)();
                $lastHeartbeat = microtime(true);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = trim($stdout);
        $output = $stdout === '' ? [] : preg_split('/\R/', $stdout);
    }
}
