<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Streaming;

use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * OriginalController integration tests.
 *
 * GET /api/videos/{uuid}/original
 *
 * Authenticated by API key. Returns JSON containing a presigned original URL
 * plus embedded audio-track metadata and presigned subtitle URLs.
 */
final class OriginalControllerTest extends HttpIntegrationTestCase
{
    private string $apiKey = 'test-api-key-original';

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('access_log', 'subtitles', 'audio_tracks', 'encoding_jobs', 'videos', 'api_keys');
        $this->insertApiKey('test', $this->apiKey);
    }

    protected function tearDown(): void
    {
        $this->truncateTables('access_log', 'subtitles', 'audio_tracks', 'encoding_jobs', 'videos', 'api_keys');
        parent::tearDown();
    }

    public function testReturns200JsonWhenOriginalB2KeyIsSet(): void
    {
        $b2Key = 'videos/test-uuid/original.mp4';
        $video = $this->insertVideo(['status' => 'processing']);

        Connection::execute(
            'UPDATE videos SET original_b2_key = :key WHERE id = :id',
            [':key' => $b2Key, ':id' => $video['id']]
        );

        $this->b2->seed($b2Key, 'video-bytes');

        $response = $this->apiGet("/api/videos/{$video['uuid']}/original", $this->apiKey);

        $this->assertStatus(200, $response);
        $this->assertJsonResponse($response);

        $data = $this->json($response);
        $this->assertStringContainsString($b2Key, $data['video_url']);
        $this->assertStringContainsString('ttl=900', $data['video_url']);
        $this->assertArrayHasKey('expires_at', $data);
        $this->assertSame([], $data['audio_tracks']);
        $this->assertSame([], $data['subtitle_tracks']);
    }

    public function testReturnsOrderedAudioTrackMetadata(): void
    {
        $b2Key = 'videos/test-uuid/original.mp4';
        $video = $this->insertVideo(['status' => 'processing']);

        Connection::execute(
            'UPDATE videos SET original_b2_key = :key WHERE id = :id',
            [':key' => $b2Key, ':id' => $video['id']]
        );
        $this->b2->seed($b2Key, 'video-bytes');

        Connection::execute(
            'INSERT INTO audio_tracks (video_id, track_index, language_code, label, b2_key_prefix)
             VALUES (:vid1, 1, :lang1, :label1, :prefix1),
                    (:vid2, 0, :lang0, :label0, :prefix0)',
            [
                ':vid1'   => $video['id'],
                ':vid2'   => $video['id'],
                ':lang1'  => 'fra',
                ':label1' => 'French',
                ':prefix1'=> "videos/{$video['uuid']}/audio_1",
                ':lang0'  => 'eng',
                ':label0' => 'English',
                ':prefix0'=> "videos/{$video['uuid']}/audio_0",
            ]
        );

        $response = $this->apiGet("/api/videos/{$video['uuid']}/original", $this->apiKey);
        $data     = $this->json($response);

        $this->assertCount(2, $data['audio_tracks']);
        $this->assertSame(0, $data['audio_tracks'][0]['track_index']);
        $this->assertSame('eng', $data['audio_tracks'][0]['language_code']);
        $this->assertSame('English', $data['audio_tracks'][0]['label']);
        $this->assertSame(1, $data['audio_tracks'][1]['track_index']);
    }

    public function testSubtitlePresignFailuresAreSkipped(): void
    {
        $b2Key = 'videos/test-uuid/original.mp4';
        $video = $this->insertVideo(['status' => 'processing']);

        Connection::execute(
            'UPDATE videos SET original_b2_key = :key WHERE id = :id',
            [':key' => $b2Key, ':id' => $video['id']]
        );
        $this->b2->seed($b2Key, 'video-bytes');

        $goodSubKey = "videos/{$video['uuid']}/subs/en.vtt";
        $this->b2->seed($goodSubKey, 'WEBVTT');

        Connection::execute(
            'INSERT INTO subtitles (video_id, track_index, language_code, label, is_forced, b2_vtt_key)
             VALUES (:vid1, 0, :lang1, :label1, 0, :key1),
                    (:vid2, 1, :lang2, :label2, 0, :key2)',
            [
                ':vid1'   => $video['id'],
                ':vid2'   => $video['id'],
                ':lang1'  => 'en',
                ':label1' => 'English',
                ':key1'   => $goodSubKey,
                ':lang2'  => 'es',
                ':label2' => 'Spanish',
                ':key2'   => "videos/{$video['uuid']}/subs/es.vtt",
            ]
        );

        $response = $this->apiGet("/api/videos/{$video['uuid']}/original", $this->apiKey);
        $data     = $this->json($response);

        $this->assertCount(1, $data['subtitle_tracks']);
        $this->assertSame('en', $data['subtitle_tracks'][0]['language_code']);
        $this->assertStringContainsString($goodSubKey, $data['subtitle_tracks'][0]['vtt_url']);
    }

    public function testReturns410WhenOriginalDeletedAtIsSet(): void
    {
        $video = $this->insertVideo(['status' => 'ready']);

        Connection::execute(
            'UPDATE videos SET original_deleted_at = NOW() WHERE id = :id',
            [':id' => $video['id']]
        );

        $response = $this->apiGet("/api/videos/{$video['uuid']}/original", $this->apiKey);

        $this->assertStatus(410, $response);
        $data = $this->json($response);
        $this->assertSame('GONE', $data['error']);
    }

    public function testReturns425WhenOriginalB2KeyIsNull(): void
    {
        $video    = $this->insertVideo(['status' => 'pending']);
        $response = $this->apiGet("/api/videos/{$video['uuid']}/original", $this->apiKey);

        $this->assertStatus(425, $response);
        $data = $this->json($response);
        $this->assertSame('NOT_READY', $data['error']);
    }

    public function testReturns404ForUnknownUuid(): void
    {
        $response = $this->apiGet('/api/videos/' . $this->newUuid() . '/original', $this->apiKey);

        $this->assertStatus(404, $response);
    }

    public function testReturns401WithoutApiKey(): void
    {
        $video    = $this->insertVideo();
        $response = $this->get("/api/videos/{$video['uuid']}/original");

        $this->assertStatus(401, $response);
    }
}
