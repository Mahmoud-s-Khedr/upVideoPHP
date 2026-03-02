<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Api;

use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * DELETE /api/videos/{uuid}/audio-tracks/{index}  integration tests.
 *
 * Covers: 202 success, 404 on unknown video/track, 401 on missing key,
 * B2 object deletion, and master.m3u8 rebuild for ready videos.
 */
final class AudioTrackControllerTest extends HttpIntegrationTestCase
{
    private string $apiKey = 'test-api-key-audio-track';

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('audio_tracks', 'renditions', 'encoding_jobs', 'videos', 'api_keys');
        $this->insertApiKey('test', $this->apiKey);
    }

    protected function tearDown(): void
    {
        $this->truncateTables('audio_tracks', 'renditions', 'encoding_jobs', 'videos', 'api_keys');
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function insertAudioTrack(
        int    $videoId,
        string $uuid,
        int    $trackIndex = 0,
        string $lang = 'eng'
    ): void {
        Connection::execute(
            'INSERT INTO audio_tracks (video_id, track_index, language_code, label, b2_key_prefix)
             VALUES (:vid, :idx, :lang, :label, :prefix)',
            [
                ':vid'    => $videoId,
                ':idx'    => $trackIndex,
                ':lang'   => $lang,
                ':label'  => ucfirst($lang),
                ':prefix' => "videos/{$uuid}/audio_{$trackIndex}",
            ]
        );
    }

    private function insertRendition(int $videoId, string $uuid, string $label = '720p'): void
    {
        Connection::execute(
            'INSERT INTO renditions (video_id, label, width, height, bitrate_kbps, b2_key_prefix)
             VALUES (:vid, :label, :w, :h, :bps, :prefix)',
            [
                ':vid'    => $videoId,
                ':label'  => $label,
                ':w'      => 1280,
                ':h'      => 720,
                ':bps'    => 2500,
                ':prefix' => "videos/{$uuid}/{$label}/",
            ]
        );
    }

    // =========================================================================
    // Happy paths
    // =========================================================================

    public function testDeleteAudioTrackReturns202(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $this->insertAudioTrack((int) $video['id'], $video['uuid'], 0, 'eng');
        $this->b2->seed("videos/{$video['uuid']}/audio_0/index.m3u8", '#EXTM3U');

        $response = $this->apiDelete(
            "/api/videos/{$video['uuid']}/audio-tracks/0",
            $this->apiKey
        );

        $this->assertStatus(202, $response);

        $data = $this->json($response);
        $this->assertSame($video['uuid'], $data['video_uuid']);
        $this->assertSame(0,             $data['track_index']);
        $this->assertTrue($data['deleted']);
    }

    public function testDeleteAudioTrackRemovesDbRow(): void
    {
        $video   = $this->insertVideo(['status' => 'ready']);
        $videoId = (int) $video['id'];
        $this->insertAudioTrack($videoId, $video['uuid'], 0);
        $this->b2->seed("videos/{$video['uuid']}/audio_0/index.m3u8", '#EXTM3U');

        $this->apiDelete("/api/videos/{$video['uuid']}/audio-tracks/0", $this->apiKey);

        $row = Connection::fetch(
            'SELECT id FROM audio_tracks WHERE video_id = :vid AND track_index = 0',
            [':vid' => $videoId]
        );
        $this->assertNull($row, 'Audio track DB row must be deleted');
    }

    public function testDeleteAudioTrackRemovesB2Objects(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);
        $uuid  = $video['uuid'];

        $this->insertAudioTrack((int) $video['id'], $uuid, 0);
        $this->b2->seed("videos/{$uuid}/audio_0/index.m3u8", '#EXTM3U');
        $this->b2->seed("videos/{$uuid}/audio_0/seg00001.ts", 'TS_DATA');

        $this->apiDelete("/api/videos/{$uuid}/audio-tracks/0", $this->apiKey);

        $this->assertFalse($this->b2->hasKey("videos/{$uuid}/audio_0/index.m3u8"));
        $this->assertFalse($this->b2->hasKey("videos/{$uuid}/audio_0/seg00001.ts"));
    }

    public function testDeleteAudioTrackRebuildsM3u8ForReadyVideo(): void
    {
        $video   = $this->insertVideo(['status' => 'ready']);
        $videoId = (int) $video['id'];
        $uuid    = $video['uuid'];

        $this->insertAudioTrack($videoId, $uuid, 0);
        $this->insertRendition($videoId, $uuid, '720p');
        $this->b2->seed("videos/{$uuid}/audio_0/index.m3u8", '#EXTM3U');

        $this->apiDelete("/api/videos/{$uuid}/audio-tracks/0", $this->apiKey);

        $this->assertTrue(
            $this->b2->hasKey("videos/{$uuid}/master.m3u8"),
            'master.m3u8 must be rebuilt after removing an audio track from a ready video'
        );
    }

    public function testDeleteAudioTrackDoesNotRebuildM3u8ForNonReadyVideo(): void
    {
        $video   = $this->insertVideo(['status' => 'processing']);
        $videoId = (int) $video['id'];
        $uuid    = $video['uuid'];

        $this->insertAudioTrack($videoId, $uuid, 0);
        $this->insertRendition($videoId, $uuid, '720p');
        $this->b2->seed("videos/{$uuid}/audio_0/index.m3u8", '#EXTM3U');

        $this->apiDelete("/api/videos/{$uuid}/audio-tracks/0", $this->apiKey);

        $this->assertFalse(
            $this->b2->hasKey("videos/{$uuid}/master.m3u8"),
            'master.m3u8 must NOT be rebuilt for non-ready videos'
        );
    }

    // =========================================================================
    // Not found
    // =========================================================================

    public function testDeleteAudioTrackReturns404ForUnknownVideo(): void
    {
        $response = $this->apiDelete(
            '/api/videos/' . $this->newUuid() . '/audio-tracks/0',
            $this->apiKey
        );

        $this->assertStatus(404, $response);
        $this->assertSame('NOT_FOUND', $this->json($response)['error']);
    }

    public function testDeleteAudioTrackReturns404ForUnknownTrackIndex(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        // No audio track inserted
        $response = $this->apiDelete(
            "/api/videos/{$video['uuid']}/audio-tracks/0",
            $this->apiKey
        );

        $this->assertStatus(404, $response);
        $this->assertSame('NOT_FOUND', $this->json($response)['error']);
    }

    // =========================================================================
    // Auth
    // =========================================================================

    public function testDeleteAudioTrackReturns401WithoutApiKey(): void
    {
        $video    = $this->insertVideo(['status' => 'ready']);
        $response = $this->request('DELETE', "/api/videos/{$video['uuid']}/audio-tracks/0");

        $this->assertStatus(401, $response);
    }
}
