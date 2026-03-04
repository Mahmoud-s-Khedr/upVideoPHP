<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Player;

use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

final class PlayerEventControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('access_log', 'videos');
    }

    protected function tearDown(): void
    {
        $this->truncateTables('access_log', 'videos');
        parent::tearDown();
    }

    public function testPlayerEventsAcceptAdViewTelemetry(): void
    {
        $video = $this->insertVideo();

        $response = $this->post(
            '/api/player-events',
            json_encode([
                'video_uuid' => $video['uuid'],
                'session_id' => 'session-123',
                'surface' => 'watch',
                'action' => 'ad_view',
                'source_kind' => 'none',
                'details' => [
                    'placement' => 'watch_top_banner',
                ],
            ], JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json']
        );

        $this->assertStatus(202, $response);

        $row = Connection::fetch(
            'SELECT action, session_id, details_json FROM access_log WHERE video_id = :vid ORDER BY id DESC LIMIT 1',
            [':vid' => $video['id']]
        );

        $this->assertNotNull($row);
        $this->assertSame('ad_view', $row['action']);
        $this->assertSame('session-123', $row['session_id']);
        $this->assertStringContainsString('"placement":"watch_top_banner"', (string) $row['details_json']);
    }

    public function testPlayerEventsAcceptAdClickTelemetry(): void
    {
        $video = $this->insertVideo();

        $response = $this->post(
            '/api/player-events',
            json_encode([
                'video_uuid' => $video['uuid'],
                'surface' => 'embed',
                'action' => 'ad_click',
                'source_kind' => 'none',
                'details' => [
                    'placement' => 'embed_banner',
                ],
            ], JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json']
        );

        $this->assertStatus(202, $response);

        $row = Connection::fetch(
            'SELECT action, details_json FROM access_log WHERE video_id = :vid ORDER BY id DESC LIMIT 1',
            [':vid' => $video['id']]
        );

        $this->assertNotNull($row);
        $this->assertSame('ad_click', $row['action']);
        $this->assertStringContainsString('"placement":"embed_banner"', (string) $row['details_json']);
        $this->assertStringContainsString('"surface":"embed"', (string) $row['details_json']);
    }
}
