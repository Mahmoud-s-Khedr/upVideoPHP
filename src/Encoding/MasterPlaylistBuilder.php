<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * Builds and uploads the HLS master playlist (master.m3u8).
 *
 * Generates:
 *   #EXT-X-STREAM-INF entries for each video rendition
 *   #EXT-X-MEDIA TYPE=AUDIO entries for multiple audio tracks
 *   #EXT-X-MEDIA TYPE=SUBTITLES entries for each subtitle track
 *
 * The playlist references relative URIs — the PHP delivery endpoint
 * rewrites these to authenticated routes before serving to players.
 */
final class MasterPlaylistBuilder
{
    private const RENDITION_BANDWIDTH = [
        '1080p' => 4192000, // 4000k video + 192k audio
        '720p'  => 2628000, // 2500k + 128k
        '540p'  => 1928000, // 1800k + 128k
        '480p'  => 1328000, // 1200k + 128k
        '360p'  => 696000,  // 600k  + 96k
    ];

    private const RENDITION_RESOLUTION = [
        '1080p' => '1920x1080',
        '720p'  => '1280x720',
        '540p'  => '960x540',
        '480p'  => '854x480',
        '360p'  => '640x360',
    ];

    public function __construct(
        private readonly int    $videoId,
        private readonly string $videoUuid,
    ) {}

    /**
     * Inject pre-built audio-track and subtitle rows in tests (bypasses DB reads).
     * Pass null values to restore normal DB behaviour.
     *
     * @param list<array{track_index:int,language_code:string,label:string}>|null $audioTracks
     * @param list<array{language_code:string,label:string,is_forced:bool}>|null  $subtitles
     */
    private static ?array $testAudioTracks = null;
    private static ?array $testSubtitles   = null;

    public static function setTestData(?array $audioTracks, ?array $subtitles): void
    {
        self::$testAudioTracks = $audioTracks;
        self::$testSubtitles   = $subtitles;
    }

    /**
     * Build master.m3u8 content, write it to $processingDir, and upload to B2.
     *
     * Audio tracks are read from the audio_tracks DB table (pre-extracted in
     * Step 5 of the pipeline) so their playlists are the same B2 objects
     * available for original-quality playback — no duplicate storage.
     *
     * @param list<string> $renditionLabels Encoded labels in order
     */
    public function build(
        string $processingDir,
        array  $renditionLabels,
    ): void {
        $lines = ['#EXTM3U', '#EXT-X-VERSION:3'];

        // ----- Audio tracks — read from audio_tracks table (pre-extracted) ---
        $dbAudioTracks   = self::$testAudioTracks ?? Connection::fetchAll(
            'SELECT track_index, language_code, label FROM audio_tracks WHERE video_id = :vid ORDER BY track_index ASC',
            [':vid' => $this->videoId]
        );
        $hasAudio        = !empty($dbAudioTracks);
        $audioGroupId    = 'audio';
        $defaultAudioSet = false;

        // Nullify the static property so it does not bleed between tests
        if (self::$testAudioTracks !== null) {
            // consumed — keep until tearDown resets via setTestData(null, null)
        }

        if ($hasAudio) {
            foreach ($dbAudioTracks as $track) {
                $isDefault = !$defaultAudioSet;
                if ($isDefault) {
                    $defaultAudioSet = true;
                }
                $lines[] = sprintf(
                    '#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID="%s",LANGUAGE="%s",NAME="%s",DEFAULT=%s,AUTOSELECT=YES,URI="audio_%d/index.m3u8"',
                    $audioGroupId,
                    $track['language_code'],
                    $track['label'],
                    $isDefault ? 'YES' : 'NO',
                    (int) $track['track_index']
                );
            }
        }

        // ----- Subtitle tracks ----------------------------------------------
        $subtitleGroupId = 'subs';
        $hasSubtitles    = false;

        $dbSubtitles = self::$testSubtitles ?? Connection::fetchAll(
            'SELECT language_code, label, is_forced FROM subtitles WHERE video_id = :vid',
            [':vid' => $this->videoId]
        );

        foreach ($dbSubtitles as $sub) {
            $hasSubtitles = true;
            $forced       = (bool) $sub['is_forced'];
            $lines[]      = sprintf(
                '#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID="%s",LANGUAGE="%s",NAME="%s",DEFAULT=%s,AUTOSELECT=%s,FORCED=%s,URI="subs/%s.m3u8"',
                $subtitleGroupId,
                $sub['language_code'],
                $sub['label'],
                $forced ? 'YES' : 'NO',
                $forced ? 'YES' : 'NO',
                $forced ? 'YES' : 'NO',
                $sub['language_code']
            );
        }

        // ----- Video stream entries -----------------------------------------
        foreach ($renditionLabels as $label) {
            $bandwidth  = self::RENDITION_BANDWIDTH[$label]  ?? 1000000;
            $resolution = self::RENDITION_RESOLUTION[$label] ?? '640x360';
            $codecs     = 'avc1.42E01E,mp4a.40.2';

            $streamInf  = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,RESOLUTION=%s,CODECS="%s"',
                $bandwidth,
                $resolution,
                $codecs
            );

            if ($hasAudio) {
                $streamInf .= sprintf(',AUDIO="%s"', $audioGroupId);
            }
            if ($hasSubtitles) {
                $streamInf .= sprintf(',SUBTITLES="%s"', $subtitleGroupId);
            }

            $lines[] = $streamInf;
            $lines[] = $label . '/index.m3u8';
        }

        $content      = implode("\n", $lines) . "\n";
        $playlistPath = $processingDir . '/master.m3u8';
        $written = file_put_contents($playlistPath, $content);
        if ($written === false) {
            throw new \RuntimeException("MasterPlaylistBuilder: failed to write playlist to {$playlistPath}");
        }

        // Upload to B2
        $b2Key = "videos/{$this->videoUuid}/master.m3u8";
        B2Client::putContent($b2Key, $content, 'application/x-mpegURL');
    }
}
