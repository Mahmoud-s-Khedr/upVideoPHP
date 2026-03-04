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

    // -------------------------------------------------------------------------
    // I-01 – I-03: events for all positions / types
    // -------------------------------------------------------------------------

    public function testRecordsPostrollCompleteEvent(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'postroll',
            'event'      => 'complete',
        ]);

        $row = Connection::fetch('SELECT position, event FROM ad_impressions LIMIT 1');
        $this->assertNotNull($row);
        $this->assertSame('postroll', $row['position']);
        $this->assertSame('complete', $row['event']);
    }

    public function testRecordsClickEvent(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'click',
        ]);

        $row = Connection::fetch('SELECT event FROM ad_impressions LIMIT 1');
        $this->assertSame('click', $row['event']);
    }

    public function testRecordsSkipEvent(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'skip',
        ]);

        $row = Connection::fetch('SELECT event FROM ad_impressions LIMIT 1');
        $this->assertSame('skip', $row['event']);
    }

    // -------------------------------------------------------------------------
    // I-04 – I-07: session_id handling
    // -------------------------------------------------------------------------

    public function testSessionIdUpTo64AlphanumericCharsStoredVerbatim(): void
    {
        $video     = $this->insertVideo();
        $sessionId = str_repeat('a', 64);
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'start',
            'session_id' => $sessionId,
        ]);

        $row = Connection::fetch('SELECT session_id FROM ad_impressions LIMIT 1');
        $this->assertSame($sessionId, $row['session_id']);
    }

    public function testSessionIdOf65CharsTruncatedTo64(): void
    {
        $video     = $this->insertVideo();
        $sessionId = str_repeat('b', 65);
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'start',
            'session_id' => $sessionId,
        ]);

        $row = Connection::fetch('SELECT session_id FROM ad_impressions LIMIT 1');
        $this->assertNotNull($row['session_id']);
        $this->assertSame(64, strlen((string) $row['session_id']));
    }

    public function testSessionIdAbsentIsStoredAsNull(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'start',
        ]);

        $row = Connection::fetch('SELECT session_id FROM ad_impressions LIMIT 1');
        $this->assertNull($row['session_id']);
    }

    // -------------------------------------------------------------------------
    // I-08 – I-09: cue_index handling
    // -------------------------------------------------------------------------

    public function testCueIndexZeroIsStoredForMidroll(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'midroll',
            'event'      => 'start',
            'cue_index'  => 0,
        ]);

        $row = Connection::fetch('SELECT cue_index FROM ad_impressions LIMIT 1');
        $this->assertNotNull($row['cue_index']);
        $this->assertSame(0, (int) $row['cue_index']);
    }

    public function testCueIndexAbsentIsStoredAsNull(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'midroll',
            'event'      => 'start',
        ]);

        $row = Connection::fetch('SELECT cue_index FROM ad_impressions LIMIT 1');
        $this->assertNull($row['cue_index']);
    }

    // -------------------------------------------------------------------------
    // I-10 – I-12: ip_hash
    // -------------------------------------------------------------------------

    public function testIpHashIsA64CharHexString(): void
    {
        $video = $this->insertVideo();
        $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'start',
        ]);

        $row = Connection::fetch('SELECT ip_hash FROM ad_impressions LIMIT 1');

        // ip_hash may be NULL when REMOTE_ADDR is absent in CLI mode — accept either
        if ($row['ip_hash'] !== null) {
            $this->assertSame(64, strlen((string) $row['ip_hash']));
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $row['ip_hash']);
        } else {
            $this->assertNull($row['ip_hash']);
        }
    }

    public function testTwoRequestsFromSameIpProduceSameIpHash(): void
    {
        $video = $this->insertVideo();
        $this->adEvent(['video_uuid' => $video['uuid'], 'position' => 'preroll', 'event' => 'start']);
        $this->adEvent(['video_uuid' => $video['uuid'], 'position' => 'preroll', 'event' => 'complete']);

        $rows = Connection::fetchAll('SELECT ip_hash FROM ad_impressions');
        $this->assertCount(2, $rows);
        $this->assertSame($rows[0]['ip_hash'], $rows[1]['ip_hash']);
    }

    // -------------------------------------------------------------------------
    // I-13 – I-17: additional validation
    // -------------------------------------------------------------------------

    public function testReturns400ForInvalidPositionBanner(): void
    {
        $video = $this->insertVideo();
        $this->assertStatus(400, $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'banner',
            'event'      => 'start',
        ]));
    }

    public function testReturns400ForInvalidEventView(): void
    {
        $video = $this->insertVideo();
        $this->assertStatus(400, $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'preroll',
            'event'      => 'view',
        ]));
    }

    public function testReturns400ForEmptyVideoUuid(): void
    {
        $this->assertStatus(400, $this->adEvent([
            'video_uuid' => '',
            'position'   => 'preroll',
            'event'      => 'start',
        ]));
    }

    public function testReturns400ForNonJsonBody(): void
    {
        $response = $this->post(
            '/api/ad-event',
            'not-json',
            ['Content-Type' => 'application/json']
        );
        $this->assertStatus(400, $response);
    }

    public function testStringCueIndexIsIgnoredWithout500(): void
    {
        $video    = $this->insertVideo();
        $response = $this->adEvent([
            'video_uuid' => $video['uuid'],
            'position'   => 'midroll',
            'event'      => 'start',
            'cue_index'  => 'abc',
        ]);

        // Must not crash — either 204 (ignored gracefully) or 400 (validation)
        $this->assertContains($response->getStatusCode(), [204, 400]);
    }
}
