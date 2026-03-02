<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Unit\Encoding;

use PHPUnit\Framework\TestCase;
use VideoSystem\Encoding\ProgressTracker;

/**
 * ProgressTracker — pixel-weight progress math.
 *
 * Uses the static $testDbWriter seam so no live DB is needed.
 */
final class ProgressTrackerTest extends TestCase
{
    private int    $writtenPct    = -1;
    private string $writtenLabel  = '';

    protected function setUp(): void
    {
        ProgressTracker::setTestDbWriter(function (int $jobId, int $pct, string $label): void {
            $this->writtenPct   = $pct;
            $this->writtenLabel = $label;
        });
    }

    protected function tearDown(): void
    {
        ProgressTracker::setTestDbWriter(null);
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private function tracker(array $labels): ProgressTracker
    {
        return new ProgressTracker(1, $labels);
    }

    // =========================================================================
    // Single-rendition tests
    // =========================================================================

    public function testSingleRenditionAt100Percent(): void
    {
        $t = $this->tracker(['360p']);
        $t->update('360p', 100.0, force: true);
        $this->assertSame(100, $this->writtenPct);
    }

    public function testSingleRenditionAt50Percent(): void
    {
        $t = $this->tracker(['360p']);
        $t->update('360p', 50.0, force: true);
        $this->assertSame(50, $this->writtenPct);
    }

    public function testSingleRenditionAt0Percent(): void
    {
        $t = $this->tracker(['360p']);
        $t->update('360p', 0.0, force: true);
        $this->assertSame(0, $this->writtenPct);
    }

    // =========================================================================
    // Pixel-weighted multi-rendition math
    // =========================================================================

    public function testFirstRenditionOf4At100IsAbout57Percent(): void
    {
        // Pixel weights: 1080p=2073600, 720p=921600, 480p=409920, 360p=230400  → total=3635520
        // 2073600 / 3635520 ≈ 0.5703 → round to 57
        $t = $this->tracker(['1080p', '720p', '480p', '360p']);
        $t->renditionComplete('1080p');
        $this->assertSame(57, $this->writtenPct);
    }

    public function testAfter1080pAnd720pAt82Percent(): void
    {
        // (2073600 + 921600) / 3635520 ≈ 0.824 → 82
        $t = $this->tracker(['1080p', '720p', '480p', '360p']);
        $t->renditionComplete('1080p');
        $t->renditionComplete('720p');
        $this->assertSame(82, $this->writtenPct);
    }

    public function testAllFourRenditionsReach100(): void
    {
        $t = $this->tracker(['1080p', '720p', '480p', '360p']);
        $t->renditionComplete('1080p');
        $t->renditionComplete('720p');
        $t->renditionComplete('480p');
        $t->renditionComplete('360p');
        $this->assertSame(100, $this->writtenPct);
    }

    public function testPartialProgressDuringFirstOf2Renditions(): void
    {
        // Two-rung ladder: 1080p + 720p  → total=2995200
        // At 50% through 1080p: currentWeight = 2073600*0.5 = 1036800
        // pct = round(1036800/2995200*100) = 35
        $t = $this->tracker(['1080p', '720p']);
        $t->update('1080p', 50.0, force: true);
        $this->assertSame(35, $this->writtenPct);
    }

    public function testSingleLadder360pAt50(): void
    {
        $t = $this->tracker(['360p']);
        $t->update('360p', 50.0, force: true);
        $this->assertSame(50, $this->writtenPct);
    }

    // =========================================================================
    // Boundary / edge cases
    // =========================================================================

    public function testProgressDoesNotExceed100(): void
    {
        $t = $this->tracker(['720p']);
        $t->update('720p', 150.0, force: true);
        $this->assertLessThanOrEqual(100, $this->writtenPct);
    }

    public function testProgressIsNeverNegative(): void
    {
        $t = $this->tracker(['720p']);
        $t->update('720p', -10.0, force: true);
        $this->assertGreaterThanOrEqual(0, $this->writtenPct);
    }

    public function testUnknownLabelWeightIsZeroAndDoesNotCrash(): void
    {
        $t = $this->tracker(['720p']);
        $t->update('8k', 50.0, force: true);
        // unknown label has pixel weight 0 → overall progress stays 0
        $this->assertSame(0, $this->writtenPct);
    }

    public function testLabelPassedToDbIsCurrentRendition(): void
    {
        $t = $this->tracker(['1080p', '720p']);
        $t->update('720p', 25.0, force: true);
        $this->assertSame('720p', $this->writtenLabel);
    }

    public function testRenditionCompleteWritesCorrectLabel(): void
    {
        $t = $this->tracker(['480p', '360p']);
        $t->renditionComplete('360p');
        $this->assertSame('360p', $this->writtenLabel);
    }

    public function testEmptyLabelListDoesNotCrash(): void
    {
        // Edge case: videoHeight so small all renditions are skipped
        $t = $this->tracker([]);
        $t->update('360p', 0.0, force: true);
        // With no applicable labels, totalWeight is guarded at 1; 0/1*100 = 0
        $this->assertSame(0, $this->writtenPct);
    }

    public function testWriteIsSkippedWhenPctUnchangedAndNotForced(): void
    {
        $t = $this->tracker(['360p']);
        $t->update('360p', 50.0, force: true);   // writes 50
        $this->writtenPct = -99;                  // reset sentinel
        $t->update('360p', 50.0, force: false);  // same value, no force → should skip (debounce)
        // The pct didn't change AND force=false, so the last-write-time guard
        // blocks the write within WRITE_INTERVAL_SEC. Sentinel stays at -99.
        $this->assertSame(-99, $this->writtenPct);
    }

    public function testForceBypassesDebounce(): void
    {
        $t = $this->tracker(['360p']);
        $t->update('360p', 50.0, force: true);
        $this->writtenPct = -99;
        // Same pct but force=true AND pct is same → 'force' flag wins only for time, not for same pct
        // Actually looking at the source: force only bypasses time gate, but pct === lastWrittenPct
        // check is separate. Let's use a different pct.
        $t->update('360p', 75.0, force: true);
        $this->assertSame(75, $this->writtenPct);
    }
}
