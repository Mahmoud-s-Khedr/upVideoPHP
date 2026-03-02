<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Queue\JobQueue;

/**
 * Pixel-weighted encoding progress calculator (S6).
 *
 * Equal-weight per rendition looks broken: 1080p takes ~4× longer than 360p,
 * so with equal weights the bar crawls to 25% then races to 100%.
 *
 * Weights are proportional to pixel count (width × height):
 *   1080p: 1920 × 1080 = 2,073,600
 *   720p:  1280 × 720  =   921,600
 *   480p:   854 × 480  =   409,920
 *   360p:   640 × 360  =   230,400
 */
final class ProgressTracker
{
    private const PIXEL_WEIGHTS = [
        '1080p' => 2073600,
        '720p'  => 921600,
        '540p'  => 518400,
        '480p'  => 409920,
        '360p'  => 230400,
    ];

    private int   $jobId;
    private array $applicableLabels; // ordered list of rendition labels for this video
    private float $totalWeight;
    private float $completedWeight = 0.0;
    private int   $lastWrittenPct  = 0;
    private float $lastWriteTime   = 0.0;
    private const WRITE_INTERVAL_SEC = 2.0;

    /** @var (callable(int $jobId, int $pct, string $label): void)|null — overrides DB write in tests. */
    private static $testDbWriter = null;

    public static function setTestDbWriter(?callable $fn): void
    {
        self::$testDbWriter = $fn;
    }

    /**
     * @param list<string> $applicableLabels  Labels in encode order (highest to lowest)
     */
    public function __construct(int $jobId, array $applicableLabels)
    {
        $this->jobId           = $jobId;
        $this->applicableLabels = $applicableLabels;
        $this->totalWeight     = array_sum(
            array_intersect_key(self::PIXEL_WEIGHTS, array_flip($applicableLabels))
        );

        if ($this->totalWeight <= 0) {
            $this->totalWeight = 1; // safety guard
        }
    }

    /**
     * Called during a rendition's FFmpeg output loop with the current rendition progress.
     *
     * @param string $currentLabel       Current rendition being encoded
     * @param float  $currentPct         Progress within the current rendition (0–100)
     * @param bool   $force              Write to DB even if interval has not elapsed
     */
    public function update(string $currentLabel, float $currentPct, bool $force = false): void
    {
        $now = microtime(as_float: true);
        if (!$force && ($now - $this->lastWriteTime) < self::WRITE_INTERVAL_SEC) {
            return;
        }

        $currentWeight  = (self::PIXEL_WEIGHTS[$currentLabel] ?? 0) * ($currentPct / 100.0);
        $totalCompleted = $this->completedWeight + $currentWeight;
        $pct            = (int) round($totalCompleted / $this->totalWeight * 100.0);
        $pct            = max(0, min(100, $pct));

        if ($pct !== $this->lastWrittenPct || $force) {
            if (self::$testDbWriter !== null) {
                (self::$testDbWriter)($this->jobId, $pct, $currentLabel);
            } else {
                JobQueue::updateProgress($this->jobId, $pct, $currentLabel);
            }
            $this->lastWrittenPct = $pct;
        }

        $this->lastWriteTime = $now;
    }

    /**
     * Mark a rendition as fully complete. Call after each successful rendition encode.
     */
    public function renditionComplete(string $label): void
    {
        // Call update() first (with completedWeight = previous renditions only),
        // so the DB receives the correct weighted 100% for this rendition.
        // Then increment completedWeight so subsequent calls account for this rendition.
        $this->update($label, 100.0, force: true);
        $this->completedWeight += self::PIXEL_WEIGHTS[$label] ?? 0;
    }
}
