<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Encoding;

use VideoSystem\Database\Connection;
use VideoSystem\Encoding\CancelledException;
use VideoSystem\Encoding\EncodingException;
use VideoSystem\Encoding\ProgressTracker;
use VideoSystem\Encoding\RenditionPipeline;
use VideoSystem\Storage\B2Client;
use VideoSystem\Tests\Integration\IntegrationTestCase;
use VideoSystem\Tests\Support\FakeB2Client;

/**
 * RenditionPipeline::encodeAll() integration tests.
 *
 * FFmpeg execution is intercepted via RenditionPipeline::setTestEncodeRenditionFn().
 * B2 uploads are intercepted via FakeB2Client.
 * ProgressTracker DB writes are suppressed via setTestDbWriter().
 *
 * A real DB is required so JobQueue::isCancelRequested() and
 * Connection::execute() (current_rendition + renditions INSERT) work correctly.
 * Tests auto-skip when the DB is unreachable.
 */
final class RenditionPipelineEncodeTest extends IntegrationTestCase
{
    private FakeB2Client $b2;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->b2 = new FakeB2Client();
        B2Client::setTestOverride($this->b2);

        $this->tmpDir = sys_get_temp_dir() . '/rp_test_' . uniqid();
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->truncateTables('renditions', 'encoding_jobs', 'videos');

        ProgressTracker::setTestDbWriter(fn() => null); // suppress progress DB writes
        RenditionPipeline::setTestEncodeRenditionFn(null); // ensure clean state
    }

    protected function tearDown(): void
    {
        RenditionPipeline::setTestEncodeRenditionFn(null);
        ProgressTracker::setTestDbWriter(null);
        B2Client::setTestOverride(null);

        $this->rimraf($this->tmpDir);
        $this->truncateTables('renditions', 'encoding_jobs', 'videos');

        parent::tearDown();
    }

    private function rimraf(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->rimraf($path . '/' . $entry);
        }
        rmdir($path);
    }

    /**
     * Build a RenditionPipeline for a single test.
     *
     * @param int $sourceHeight Source video height (controls which renditions are applicable)
     */
    private function makePipeline(
        int    $jobId,
        int    $videoId,
        string $uuid,
        int    $sourceHeight = 1080
    ): RenditionPipeline {
        $labels   = array_column(
            array_filter(
                [['1080p', 1080], ['720p', 720], ['540p', 540], ['480p', 480], ['360p', 360]],
                fn(array $r) => $sourceHeight >= $r[1]
            ),
            0
        );
        $progress = new ProgressTracker($jobId, $labels);

        return new RenditionPipeline(
            jobId:           $jobId,
            videoId:         $videoId,
            videoUuid:       $uuid,
            processingDir:   $this->tmpDir,
            keyInfoPath:     $this->tmpDir . '/key.keyinfo',
            durationSec:     60.0,
            sourceHeight:    $sourceHeight,
            audioTrackCount: 0,
            progress:        $progress,
            selectedLabels:  [],
        );
    }

    /** Fake encode fn that creates the minimum output files encodeAll needs. */
    private function fakeFfmpeg(): \Closure
    {
        return function (string $label, string $renditionDir): void {
            file_put_contents($renditionDir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
            file_put_contents($renditionDir . '/seg00001.ts', "\x47\x00\x00");
        };
    }

    // =========================================================================
    // Happy paths
    // =========================================================================

    public function testEncodeAllCompletesAllApplicableRenditions(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $job   = $this->insertJob((int) $video['id'], 'claimed');

        RenditionPipeline::setTestEncodeRenditionFn($this->fakeFfmpeg());

        $pipeline  = $this->makePipeline((int) $job['id'], (int) $video['id'], $video['uuid']);
        $completed = $pipeline->encodeAll();

        $this->assertSame(['1080p', '720p', '540p', '480p', '360p'], $completed);

        // Each rendition must be present in B2
        foreach ($completed as $label) {
            $this->assertTrue(
                $this->b2->hasKey("videos/{$video['uuid']}/{$label}/index.m3u8"),
                "{$label}/index.m3u8 must be in B2"
            );
        }
    }

    public function testEncodeAllRecordsRenditionsInDb(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $job   = $this->insertJob((int) $video['id'], 'claimed');

        RenditionPipeline::setTestEncodeRenditionFn($this->fakeFfmpeg());

        $pipeline = $this->makePipeline((int) $job['id'], (int) $video['id'], $video['uuid']);
        $pipeline->encodeAll();

        $rows = Connection::fetchAll(
            'SELECT label FROM renditions WHERE video_id = :vid ORDER BY height DESC',
            [':vid' => $video['id']]
        );
        $recordedLabels = array_column($rows, 'label');

        $this->assertEqualsCanonicalizing(
            ['1080p', '720p', '540p', '480p', '360p'],
            $recordedLabels,
            'All five renditions must be recorded in the renditions table'
        );
    }

    public function testEncodeAllSkipsRenditionsAlreadyPresentInB2(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $job   = $this->insertJob((int) $video['id'], 'claimed');

        // Simulate 1080p already encoded + uploaded
        $this->b2->seed("videos/{$video['uuid']}/1080p/index.m3u8", '#EXTM3U');

        $seenLabels = [];
        RenditionPipeline::setTestEncodeRenditionFn(
            function (string $label, string $renditionDir) use (&$seenLabels): void {
                $seenLabels[] = $label;
                file_put_contents($renditionDir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
            }
        );

        $pipeline  = $this->makePipeline((int) $job['id'], (int) $video['id'], $video['uuid']);
        $completed = $pipeline->encodeAll();

        $this->assertNotContains('1080p', $seenLabels, '1080p must be skipped (already in B2)');
        $this->assertContains('1080p',    $completed,   '1080p must still appear in the returned list');
    }

    public function testEncodeAllOnlyEncodeLabelsApplicableToSourceHeight(): void
    {
        // 480p source — 1080p, 720p, 540p should be filtered out
        $video = $this->insertVideo(['status' => 'processing']);
        $job   = $this->insertJob((int) $video['id'], 'claimed');

        $seenLabels = [];
        RenditionPipeline::setTestEncodeRenditionFn(
            function (string $label, string $renditionDir) use (&$seenLabels): void {
                $seenLabels[] = $label;
                file_put_contents($renditionDir . '/index.m3u8', "#EXTM3U\n#EXT-X-ENDLIST\n");
            }
        );

        $pipeline  = $this->makePipeline((int) $job['id'], (int) $video['id'], $video['uuid'], sourceHeight: 480);
        $completed = $pipeline->encodeAll();

        $this->assertEqualsCanonicalizing(['480p', '360p'], $completed);
        $this->assertEqualsCanonicalizing(['480p', '360p'], $seenLabels);
        $this->assertNotContains('1080p', $seenLabels);
        $this->assertNotContains('720p',  $seenLabels);
        $this->assertNotContains('540p',  $seenLabels);
    }

    // =========================================================================
    // Cancellation
    // =========================================================================

    public function testEncodeAllThrowsCancelledExceptionWhenCancelRequested(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $job   = $this->insertJob((int) $video['id'], 'claimed');

        // Set cancel_requested flag before encodeAll() runs the first check
        Connection::execute(
            'UPDATE encoding_jobs SET cancel_requested = 1 WHERE id = :id',
            [':id' => $job['id']]
        );

        // Fake fn should never be reached — cancellation check fires first
        RenditionPipeline::setTestEncodeRenditionFn(function (): void {
            throw new \LogicException('encodeRendition must not be called after cancellation');
        });

        $pipeline = $this->makePipeline((int) $job['id'], (int) $video['id'], $video['uuid']);

        $this->expectException(CancelledException::class);
        $pipeline->encodeAll();
    }

    // =========================================================================
    // Error handling
    // =========================================================================

    public function testEncodeAllPropagatesEncodingException(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $job   = $this->insertJob((int) $video['id'], 'claimed');

        RenditionPipeline::setTestEncodeRenditionFn(function (): void {
            throw new EncodingException('Simulated FFmpeg failure', nonRetryable: false);
        });

        $pipeline = $this->makePipeline((int) $job['id'], (int) $video['id'], $video['uuid']);

        $this->expectException(EncodingException::class);
        $this->expectExceptionMessage('Simulated FFmpeg failure');
        $pipeline->encodeAll();
    }

    public function testNonRetryableEncodingExceptionFlagIsPreserved(): void
    {
        $video = $this->insertVideo(['status' => 'processing']);
        $job   = $this->insertJob((int) $video['id'], 'claimed');

        RenditionPipeline::setTestEncodeRenditionFn(function (): void {
            throw new EncodingException('moov atom not found', nonRetryable: true);
        });

        $pipeline = $this->makePipeline((int) $job['id'], (int) $video['id'], $video['uuid']);

        try {
            $pipeline->encodeAll();
            $this->fail('Expected EncodingException');
        } catch (EncodingException $e) {
            $this->assertTrue($e->isNonRetryable(), 'Non-retryable flag must be preserved');
        }
    }
}
