<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Streaming;

use VideoSystem\Auth\StreamToken;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * PlaylistController integration tests.
 *
 * GET /api/stream/{uuid}/master.m3u8
 * GET /api/stream/{uuid}/{label}/index.m3u8
 *
 * Both routes require a valid stream token (StreamTokenAuth middleware).
 */
final class PlaylistControllerTest extends HttpIntegrationTestCase
{
    private string $uuid;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('videos');

        $video       = $this->insertVideo(['status' => 'ready']);
        $this->uuid  = $video['uuid'];
        $this->token = StreamToken::sign($this->uuid, '', 3600);
    }

    protected function tearDown(): void
    {
        $this->truncateTables('videos');
        parent::tearDown();
    }

    // =========================================================================
    // master.m3u8
    // =========================================================================

    public function testMasterPlaylistReturns200WithRewrittenUrls(): void
    {
        // Seed a master playlist in FakeB2
        $this->b2->seed(
            "videos/{$this->uuid}/master.m3u8",
            "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-STREAM-INF:BANDWIDTH=4500000\n1080p/index.m3u8\n"
        );

        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $this->token);

        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $body = (string) $response->getBody();

        // Relative paths should be replaced by absolute delivery URLs
        $this->assertStringContainsString('/api/stream/', $body);
        $this->assertStringContainsString('1080p/index.m3u8', $body);

        // Content-Type must be an HLS MIME type
        $this->assertStringContainsString('mpegURL', $response->getHeaderLine('Content-Type'));
    }

    public function testMasterPlaylistReturnsNoStoreCache(): void
    {
        $this->b2->seed("videos/{$this->uuid}/master.m3u8", "#EXTM3U\n");
        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $this->token);

        $this->assertSame('no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testMasterPlaylistRewritesAudioAndStripsSubtitleMetadata(): void
    {
        $this->b2->seed(
            "videos/{$this->uuid}/master.m3u8",
            "#EXTM3U\n"
            . "#EXT-X-VERSION:6\n"
            . "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"audio\",NAME=\"English\",URI=\"audio_0/index.m3u8\"\n"
            . "#EXT-X-MEDIA:TYPE=SUBTITLES,GROUP-ID=\"subs\",NAME=\"English\",URI=\"subs/eng.m3u8\"\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=4500000,AUDIO=\"audio\",SUBTITLES=\"subs\"\n"
            . "1080p/index.m3u8\n"
        );

        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $this->token);

        $this->assertStatus(200, $response);
        $response->getBody()->rewind();
        $body = (string) $response->getBody();

        $this->assertStringContainsString("/api/stream/{$this->uuid}/audio_0/index.m3u8", $body);
        $this->assertStringNotContainsString('/api/audio_0/', $body);
        $this->assertStringNotContainsString('/api/subs/', $body);
        $this->assertStringNotContainsString('TYPE=SUBTITLES', $body);
        $this->assertStringNotContainsString('SUBTITLES="subs"', $body);
    }

    public function testMasterPlaylistReturns404WhenVideoNotInDb(): void
    {
        $fakeUuid = $this->newUuid();
        $token    = StreamToken::sign($fakeUuid, '', 3600);

        $response = $this->streamGet("/api/stream/{$fakeUuid}/master.m3u8", $token);

        $this->assertStatus(404, $response);
    }

    public function testMasterPlaylistReturns404WhenB2FileAbsent(): void
    {
        // Video exists in DB but no B2 object
        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $this->token);

        $this->assertStatus(404, $response);
    }

    public function testMasterPlaylistReturns403WithoutToken(): void
    {
        $response = $this->get("/api/stream/{$this->uuid}/master.m3u8");

        $this->assertStatus(403, $response);
    }

    // =========================================================================
    // Rendition playlist
    // =========================================================================

    public function testRenditionPlaylistReturns200(): void
    {
        $playlistContent = "#EXTM3U\n#EXT-X-VERSION:6\n#EXT-X-TARGETDURATION:6\n" .
                           "#EXT-X-KEY:METHOD=AES-128,URI=\"../keys/0\",IV=0x0\n" .
                           "#EXTINF:6.0,\nseg00001.ts\n#EXT-X-ENDLIST\n";

        $this->b2->seed("videos/{$this->uuid}/720p/index.m3u8", $playlistContent);

        $response = $this->streamGet("/api/stream/{$this->uuid}/720p/index.m3u8", $this->token);

        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $body = (string) $response->getBody();
        $this->assertStringContainsString('#EXTM3U', $body);
    }

    public function testRenditionPlaylistReturns404WhenVideoNotReady(): void
    {
        $this->truncateTables('videos');
        $video    = $this->insertVideo(['status' => 'processing']);
        $uuid     = $video['uuid'];
        $token    = StreamToken::sign($uuid, '', 60);

        $response = $this->streamGet("/api/stream/{$uuid}/720p/index.m3u8", $token);

        $this->assertStatus(404, $response);
    }

    public function testRenditionPlaylistReturns404ForInvalidLabel(): void
    {
        $response = $this->streamGet("/api/stream/{$this->uuid}/invalid/index.m3u8", $this->token);

        $this->assertStatus(404, $response);
    }

    // =========================================================================
    // Token propagation in non-browser mode
    // =========================================================================

    /**
     * When a ?token= query param accompanies the request, the rewritten master
     * playlist must append ?token=<value> to every URI so the player can follow
     * rendition links without needing cookies.
     */
    public function testMasterPlaylistRewrittenUrisContainTokenWhenProvided(): void
    {
        $this->b2->seed(
            "videos/{$this->uuid}/master.m3u8",
            "#EXTM3U\n#EXT-X-VERSION:6\n" .
            "#EXT-X-STREAM-INF:BANDWIDTH=4500000\n1080p/index.m3u8\n"
        );

        $response = $this->streamGet("/api/stream/{$this->uuid}/master.m3u8", $this->token);

        $this->assertStatus(200, $response);

        $response->getBody()->rewind();
        $body = (string) $response->getBody();

        // The rewritten rendition URI must include the token as a query parameter
        $this->assertStringContainsString(
            '?token=' . $this->token,
            $body,
            'Rewritten playlist URIs must include ?token= when a token was supplied on the request'
        );
    }
}
