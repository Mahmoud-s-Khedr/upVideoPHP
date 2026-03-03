<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Streaming;

use VideoSystem\Auth\StreamToken;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

final class SubtitleControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('subtitles', 'videos');
    }

    protected function tearDown(): void
    {
        $this->truncateTables('subtitles', 'videos');
        parent::tearDown();
    }

    public function testSubtitleRouteStreamsSameOriginVttContent(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $token = StreamToken::sign($video['uuid'], '', 3600);
        $b2Key = "videos/{$video['uuid']}/subs/en.vtt";

        $this->b2->seed($b2Key, "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nHello\n");

        Connection::execute(
            'INSERT INTO subtitles (video_id, track_index, language_code, label, is_forced, b2_vtt_key)
             VALUES (:vid, 0, :lang, :label, 0, :key)',
            [
                ':vid' => $video['id'],
                ':lang' => 'eng',
                ':label' => 'English',
                ':key' => $b2Key,
            ]
        );

        $response = $this->streamGet("/api/stream/{$video['uuid']}/subtitles/0.vtt", $token);

        $this->assertStatus(200, $response);
        $this->assertSame('text/vtt; charset=UTF-8', $response->getHeaderLine('Content-Type'));
        $response->getBody()->rewind();
        $this->assertStringContainsString('WEBVTT', (string) $response->getBody());
    }
}
