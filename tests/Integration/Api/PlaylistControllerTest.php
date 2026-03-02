<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Api;

use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Playlist API integration tests.
 *
 * GET /api/playlists/{uuid}
 */
final class PlaylistControllerTest extends HttpIntegrationTestCase
{
    private string $apiKey = 'test-api-key-playlists';

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('playlist_videos', 'playlists', 'videos', 'api_keys');
        $this->insertApiKey('playlist-reader', $this->apiKey);
    }

    protected function tearDown(): void
    {
        $this->truncateTables('playlist_videos', 'playlists', 'videos', 'api_keys');
        parent::tearDown();
    }

    public function testReturns200ForExistingPlaylist(): void
    {
        $playlist = $this->insertPlaylist(['title' => 'Featured']);
        $video    = $this->insertVideo(['status' => 'ready', 'original_name' => 'Ready Video']);
        $this->addPlaylistVideo((int) $playlist['id'], (int) $video['id'], 0);

        $response = $this->apiGet("/api/playlists/{$playlist['uuid']}", $this->apiKey);

        $this->assertStatus(200, $response);
        $this->assertJsonResponse($response);

        $data = $this->json($response);
        $this->assertSame($playlist['uuid'], $data['uuid']);
        $this->assertSame('Featured', $data['title']);
        $this->assertCount(1, $data['videos']);
        $this->assertSame($video['uuid'], $data['videos'][0]['uuid']);
    }

    public function testReturns404ForUnknownUuid(): void
    {
        $response = $this->apiGet('/api/playlists/' . $this->newUuid(), $this->apiKey);

        $this->assertStatus(404, $response);
    }

    public function testReturns401WithoutApiKey(): void
    {
        $playlist = $this->insertPlaylist();
        $response = $this->get("/api/playlists/{$playlist['uuid']}");

        $this->assertStatus(401, $response);
    }

    public function testExcludesNonReadyVideos(): void
    {
        $playlist    = $this->insertPlaylist();
        $readyVideo  = $this->insertVideo(['status' => 'ready', 'original_name' => 'Ready']);
        $queuedVideo = $this->insertVideo(['status' => 'queued', 'original_name' => 'Queued']);

        $this->addPlaylistVideo((int) $playlist['id'], (int) $readyVideo['id'], 0);
        $this->addPlaylistVideo((int) $playlist['id'], (int) $queuedVideo['id'], 1);

        $response = $this->apiGet("/api/playlists/{$playlist['uuid']}", $this->apiKey);
        $data     = $this->json($response);

        $this->assertCount(1, $data['videos']);
        $this->assertSame($readyVideo['uuid'], $data['videos'][0]['uuid']);
    }

    public function testPreservesOrderingByPosition(): void
    {
        $playlist = $this->insertPlaylist();
        $videoA   = $this->insertVideo(['status' => 'ready', 'original_name' => 'A']);
        $videoB   = $this->insertVideo(['status' => 'ready', 'original_name' => 'B']);

        $this->addPlaylistVideo((int) $playlist['id'], (int) $videoB['id'], 1);
        $this->addPlaylistVideo((int) $playlist['id'], (int) $videoA['id'], 0);

        $response = $this->apiGet("/api/playlists/{$playlist['uuid']}", $this->apiKey);
        $data     = $this->json($response);

        $this->assertSame($videoA['uuid'], $data['videos'][0]['uuid']);
        $this->assertSame($videoB['uuid'], $data['videos'][1]['uuid']);
    }

    public function testPosterUrlIsPresignedWhenPosterExists(): void
    {
        $playlist  = $this->insertPlaylist();
        $posterKey = 'videos/poster-test/poster.jpg';
        $video     = $this->insertVideo(['status' => 'ready', 'original_name' => 'Poster Video']);

        Connection::execute(
            'UPDATE videos SET poster_b2_key = :key WHERE id = :id',
            [':key' => $posterKey, ':id' => $video['id']]
        );
        $this->b2->seed($posterKey, 'jpg-bytes');

        $this->addPlaylistVideo((int) $playlist['id'], (int) $video['id'], 0);

        $response = $this->apiGet("/api/playlists/{$playlist['uuid']}", $this->apiKey);
        $data     = $this->json($response);

        $this->assertStringContainsString($posterKey, $data['videos'][0]['poster_url']);
        $this->assertStringContainsString('ttl=900', $data['videos'][0]['poster_url']);
    }

    public function testPosterPresignFailureDoesNotFailWholeResponse(): void
    {
        $playlist = $this->insertPlaylist();
        $video    = $this->insertVideo(['status' => 'ready']);

        Connection::execute(
            'UPDATE videos SET poster_b2_key = :key WHERE id = :id',
            [':key' => 'videos/missing/poster.jpg', ':id' => $video['id']]
        );

        $this->addPlaylistVideo((int) $playlist['id'], (int) $video['id'], 0);

        $response = $this->apiGet("/api/playlists/{$playlist['uuid']}", $this->apiKey);
        $data     = $this->json($response);

        $this->assertStatus(200, $response);
        $this->assertNull($data['videos'][0]['poster_url']);
    }
}
