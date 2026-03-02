<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Api;

use Psr\Http\Message\ResponseInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * AdImpressionController integration tests.
 *
 * POST /api/ad-event — no authentication required.
 *
 * These tests require a live database; they are automatically skipped when the
 * DB is unreachable (inherited behaviour from IntegrationTestCase).
 */
final class AdImpressionControllerTest extends HttpIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('ad_impressions', 'videos');
    }

    protected function tearDown(): void
    {
        $this->truncateTables('ad_impressions', 'videos');
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function adEvent(array $payload): ResponseInterface
    {
        return $this->post(
            '/api/ad-event',
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json']
        );
    }

    // -------------------------------------------------------------------------
    // Happy paths
    // -------------------------------------------------------------------------

    public function testReturns204ForValidPrerollStart(): void
    {
        $video    = $this->insertVideo();
        $response = $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'start',
        ]);

        $this->assertStatus(204, $response);
    }

    public function testReturns204WithoutOptionalFields(): void
    {
        $video    = $this->insertVideo();
        $response = $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'postroll',
            'event'      => 'complete',
        ]);

        $this->assertStatus(204, $response);
    }

    // -------------------------------------------------------------------------
    // DB persistence
    // -------------------------------------------------------------------------

    public function testRecordsImpressionRow(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'start',
        ]);

        $row = Connection::fetch(
            'SELECT video_id, position, event FROM ad_impressions LIMIT 1'
        );

        $this->assertNotNull($row);
        $this->assertSame((int) $video['id'], (int) $row['video_id']);
        $this->assertSame('preroll', $row['position']);
        $this->assertSame('start',   $row['event']);
    }

    public function testMidrollCueIndexIsStored(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'midroll',
            'event'      => 'start',
            'cue_index'  => 2,
        ]);

        $row = Connection::fetch(
            'SELECT cue_index FROM ad_impressions LIMIT 1'
        );

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row['cue_index']);
    }

    public function testSessionIdIsSanitized(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'start',
            'session_id' => 'abc123!@#',
        ]);

        $row = Connection::fetch(
            'SELECT session_id FROM ad_impressions LIMIT 1'
        );

        $this->assertNotNull($row);
        $this->assertSame('abc123', $row['session_id']);
    }

    // -------------------------------------------------------------------------
    // Validation errors → 400
    // -------------------------------------------------------------------------

    public function testReturns400ForInvalidPosition(): void
    {
        $video    = $this->insertVideo();
        $response = $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'bad',
            'event'      => 'start',
        ]);

        $this->assertStatus(400, $response);
    }

    public function testReturns400ForInvalidEvent(): void
    {
        $video    = $this->insertVideo();
        $response = $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'bad',
        ]);

        $this->assertStatus(400, $response);
    }

    public function testReturns400ForMissingVideoUuid(): void
    {
        $response = $this->adEvent([
            'position' => 'preroll',
            'event'    => 'start',
        ]);

        $this->assertStatus(400, $response);
    }

    // -------------------------------------------------------------------------
    // Not found → 404
    // -------------------------------------------------------------------------

    public function testReturns404ForUnknownVideoUuid(): void
    {
        $response = $this->adEvent([
            'video_uuid' => $this->newUuid(),
            'position'   => 'preroll',
            'event'      => 'start',
        ]);

        $this->assertStatus(404, $response);
    }
}
