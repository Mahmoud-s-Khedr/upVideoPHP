<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\RenditionPipeline;
use VideoSystem\Encoding\ProgressTracker;

/**
 * RenditionPipeline — tests that do not require FFmpeg or a live DB.
 *
 * Full end-to-end pipeline tests (encodeAll, cancellation, B2 upload) belong
 * in the Integration suite where a real or fake FFmpeg binary is available.
 */
final class RenditionPipelineTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Helper — build a minimal RenditionPipeline with a faked ProgressTracker
    // -------------------------------------------------------------------------

    private function makePipeline(int $sourceHeight): RenditionPipeline
    {
        return new RenditionPipeline(
            jobId:           999,
            videoId:         1,
            videoUuid:       'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            processingDir:   sys_get_temp_dir(),
            keyInfoPath:     sys_get_temp_dir() . '/enc.keyinfo',
            durationSec:     120.0,
            sourceHeight:    $sourceHeight,
            sourceFps:       24.0,
            audioTrackCount: 1,
            progress:        new ProgressTracker(999, []),
        );
    }

    protected function tearDown(): void
    {
        // Nothing to tear down — no DB or B2 interactions in these tests
    }

    // =========================================================================
    // getApplicableLabels — pure function, no IO
    // =========================================================================

    public function testAllFourLabelsFor1080p(): void
    {
        $labels = $this->makePipeline(1080)->getApplicableLabels();
        $this->assertSame(['1080p', '720p', '540p', '480p', '360p'], $labels);
    }

    public function testThreeLabelsFor720p(): void
    {
        $labels = $this->makePipeline(720)->getApplicableLabels();
        $this->assertSame(['720p', '540p', '480p', '360p'], $labels);
    }

    public function testTwoLabelsFor480p(): void
    {
        $labels = $this->makePipeline(480)->getApplicableLabels();
        $this->assertSame(['480p', '360p'], $labels);
    }

    public function testOneLabelFor360p(): void
    {
        $labels = $this->makePipeline(360)->getApplicableLabels();
        $this->assertSame(['360p'], $labels);
    }

    public function testNoLabelsFor359p(): void
    {
        $labels = $this->makePipeline(359)->getApplicableLabels();
        $this->assertEmpty($labels);
    }

    public function testAllFourLabelsFor4KSource(): void
    {
        // 4K source (height=2160) — all five renditions are valid (no upscaling)
        $labels = $this->makePipeline(2160)->getApplicableLabels();
        $this->assertSame(['1080p', '720p', '540p', '480p', '360p'], $labels);
    }

    public function testSourceExactlyAt720pBoundary(): void
    {
        $labels = $this->makePipeline(721)->getApplicableLabels();
        $this->assertSame(['720p', '540p', '480p', '360p'], $labels);
    }

    public function testSourceExactlyAt1080pBoundary(): void
    {
        $labels = $this->makePipeline(1081)->getApplicableLabels();
        $this->assertSame(['1080p', '720p', '540p', '480p', '360p'], $labels);
    }

    public function test540pRung(): void
    {
        $labels = $this->makePipeline(540)->getApplicableLabels();
        $this->assertSame(['540p', '480p', '360p'], $labels);
    }

    public function testSourceJustBelow540p(): void
    {
        $labels = $this->makePipeline(539)->getApplicableLabels();
        $this->assertSame(['480p', '360p'], $labels);
    }

    public function testLabelsAreInDescendingResolutionOrder(): void
    {
        $labels = $this->makePipeline(1080)->getApplicableLabels();

        // Verify the height values decrease (highest first)
        $heightMap = ['1080p' => 1080, '720p' => 720, '540p' => 540, '480p' => 480, '360p' => 360];
        $heights   = array_map(fn($l) => $heightMap[$l] ?? 0, $labels);
        $sorted    = $heights;
        rsort($sorted);
        $this->assertSame($sorted, $heights, 'Labels should be in descending height order');
    }

    public function testPreviewRenditionIsEncodedFirstWhen540pIsAvailable(): void
    {
        $labels = $this->makePipeline(1080)->getEncodeOrder();
        $this->assertSame(['540p', '1080p', '720p', '480p', '360p'], $labels);
    }

    public function testPreviewRenditionFallsBackToFirstApplicableSelection(): void
    {
        $pipeline = new RenditionPipeline(
            jobId:           999,
            videoId:         1,
            videoUuid:       'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            processingDir:   sys_get_temp_dir(),
            keyInfoPath:     sys_get_temp_dir() . '/enc.keyinfo',
            durationSec:     120.0,
            sourceHeight:    1080,
            sourceFps:       24.0,
            audioTrackCount: 1,
            progress:        new ProgressTracker(999, []),
            selectedLabels:  ['1080p', '720p'],
        );

        $this->assertSame(['720p', '1080p'], $pipeline->getEncodeOrder());
    }

    public function testSelectedLabelsFilterRestrictsApplicableLabels(): void
    {
        // Admin selected only 720p and 360p; source allows 1080p, 720p, 540p, 480p, 360p
        $pipeline = new RenditionPipeline(
            jobId:           999,
            videoId:         1,
            videoUuid:       'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            processingDir:   sys_get_temp_dir(),
            keyInfoPath:     sys_get_temp_dir() . '/enc.keyinfo',
            durationSec:     120.0,
            sourceHeight:    1080,
            sourceFps:       24.0,
            audioTrackCount: 1,
            progress:        new ProgressTracker(999, []),
            selectedLabels:  ['720p', '360p'],
        );
        $labels = $pipeline->getApplicableLabels();
        $this->assertSame(['720p', '360p'], $labels);
    }

    public function testSelectedLabelsCannotUpscale(): void
    {
        // Admin wants 1080p but source is only 480p — 1080p is not in applicable list
        $pipeline = new RenditionPipeline(
            jobId:           999,
            videoId:         1,
            videoUuid:       'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            processingDir:   sys_get_temp_dir(),
            keyInfoPath:     sys_get_temp_dir() . '/enc.keyinfo',
            durationSec:     120.0,
            sourceHeight:    480,
            sourceFps:       24.0,
            audioTrackCount: 1,
            progress:        new ProgressTracker(999, []),
            selectedLabels:  ['1080p', '720p', '480p'],
        );
        $labels = $pipeline->getApplicableLabels();
        // 1080p and 720p filtered out — source too low
        $this->assertSame(['480p'], $labels);
    }

    public function testEmptySelectedLabelsReturnsAllApplicable(): void
    {
        // Empty selectedLabels = no admin restriction; falls back to source-height filter only
        $pipeline = new RenditionPipeline(
            jobId:           999,
            videoId:         1,
            videoUuid:       'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            processingDir:   sys_get_temp_dir(),
            keyInfoPath:     sys_get_temp_dir() . '/enc.keyinfo',
            durationSec:     120.0,
            sourceHeight:    720,
            sourceFps:       24.0,
            audioTrackCount: 1,
            progress:        new ProgressTracker(999, []),
            selectedLabels:  [],
        );
        $labels = $pipeline->getApplicableLabels();
        $this->assertSame(['720p', '540p', '480p', '360p'], $labels);
    }
}
