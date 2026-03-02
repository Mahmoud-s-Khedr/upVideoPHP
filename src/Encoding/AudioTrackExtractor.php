<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * Extracts audio tracks from a source video as HLS audio-only streams
 * using stream copy (no re-encoding — fast, lossless).
 *
 * Each track is demuxed into its own HLS playlist:
 *   {processingDir}/audio_{n}/index.m3u8
 *   {processingDir}/audio_{n}/seg00000.ts ...
 *
 * Then uploaded to B2 at:
 *   videos/{uuid}/audio_{n}/index.m3u8
 *   videos/{uuid}/audio_{n}/seg00000.ts ...
 *
 * A row is inserted (or updated on retry) into the audio_tracks table.
 *
 * This step runs BEFORE the original file is uploaded to B2 so that all
 * tracks are available the moment the original becomes streamable.
 *
 * Tracks that fail to extract produce a warning and are skipped — they do
 * not cause the job to fail.
 */
final class AudioTrackExtractor
{
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
    ) {}

    /** Replaces DB insert in tests. */
    private static $testDbWriter = null;

    public static function setTestDbWriter(?callable $fn): void
    {
        self::$testDbWriter = $fn;
    }

    /**
     * Extract all audio tracks to HLS audio-only playlists via stream copy.
     *
     * Resume-safe: if a track's B2 playlist already exists (from a previous run)
     * the extraction and upload for that track are skipped.
     *
     * @param list<array{index: int, language: string, codec: string, channels: int}> $audioTracks
     * @return list<string> warning messages for skipped or failed tracks
     */
    public function extractAll(array $audioTracks): array
    {
        $warnings = [];

        foreach ($audioTracks as $n => $track) {
            $trackIdx    = $track['index'];
            $b2Prefix    = "videos/{$this->videoUuid}/audio_{$trackIdx}/";
            $b2Playlist  = $b2Prefix . 'index.m3u8';

            // Resume safety: skip if already uploaded
            if (B2Client::exists($b2Playlist)) {
                continue;
            }

            $trackDir = $this->processingDir . '/audio_' . $trackIdx;
            @mkdir($trackDir, 0750, recursive: true);

            $cmd = sprintf(
                '%s -y -i %s -map 0:a:%d -c:a copy'
                    . ' -hls_time 6 -hls_playlist_type vod -hls_flags independent_segments'
                    . ' -hls_segment_filename %s %s 2>/dev/null',
                escapeshellarg(Config::ffmpegBin()),
                escapeshellarg($this->inputFile),
                $track['index'],
                escapeshellarg($trackDir . '/seg%05d.ts'),
                escapeshellarg($trackDir . '/index.m3u8')
            );

            $this->runExec($cmd, $output, $exitCode);

            $playlistPath = $trackDir . '/index.m3u8';

            if ($exitCode !== 0 || !file_exists($playlistPath)) {
                $warnings[] = sprintf(
                    'Failed to extract audio track %d (lang: %s, codec: %s, channels: %d); skipped.',
                    $track['index'],
                    $track['language'],
                    $track['codec'],
                    $track['channels']
                );
                continue;
            }

            // Upload segments to B2
            foreach (glob($trackDir . '/seg*.ts') ?: [] as $segFile) {
                $segName = basename($segFile);
                B2Client::put($b2Prefix . $segName, $segFile, 'video/MP2T');
            }
            // Upload playlist last (its presence is the resume-safety marker)
            B2Client::put($b2Playlist, $playlistPath, 'application/x-mpegURL');

            $lang  = $track['language'];
            $label = self::LANGUAGE_LABELS[$lang] ?? ucfirst($lang);

            if (self::$testDbWriter !== null) {
                (self::$testDbWriter)($this->videoId, $trackIdx, $lang, $label, rtrim($b2Prefix, '/'));
            } else {
                Connection::execute(
                    'INSERT INTO audio_tracks
                         (video_id, track_index, language_code, label, b2_key_prefix)
                     VALUES (:vid, :idx, :lang, :label, :prefix)
                     ON DUPLICATE KEY UPDATE
                         language_code = VALUES(language_code),
                         label         = VALUES(label),
                         b2_key_prefix = VALUES(b2_key_prefix)',
                    [
                        ':vid'    => $this->videoId,
                        ':idx'    => $trackIdx,
                        ':lang'   => $lang,
                        ':label'  => $label,
                        ':prefix' => rtrim($b2Prefix, '/'),
                    ]
                );
            }
        }

        return $warnings;
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
            exec($cmd, $output, $exitCode);
        }
    }
}
