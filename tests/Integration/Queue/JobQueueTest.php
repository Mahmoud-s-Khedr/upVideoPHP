<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Queue;

use PHPUnit\Framework\Attributes\CoversClass;
use VideoSystem\Database\Connection;
use VideoSystem\Queue\JobQueue;
use VideoSystem\Tests\Integration\IntegrationTestCase;

#[CoversClass(JobQueue::class)]
final class JobQueueTest extends IntegrationTestCase
{
    private array $video = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateTables('encoding_jobs', 'renditions', 'encryption_keys', 'subtitles', 'videos');

        $this->video = $this->insertVideo();
    }

    // -------------------------------------------------------------------------
    // claim()
    // -------------------------------------------------------------------------

    public function testClaimReturnsNullWhenQueueIsEmpty(): void
    {
        self::assertNull(JobQueue::claim(getmypid()));
    }

    public function testClaimReturnsJobWhenAvailable(): void
    {
        $job = $this->insertJob($this->video['id']);

        $claimed = JobQueue::claim(getmypid());

        self::assertIsArray($claimed);
        self::assertArrayHasKey('id', $claimed);
        self::assertArrayHasKey('video_id', $claimed);
        self::assertSame((int) $job['id'], $claimed['id']);
        self::assertSame($this->video['id'], $claimed['video_id']);
    }

    public function testClaimIncreasesAttempts(): void
    {
        $job = $this->insertJob($this->video['id']);

        JobQueue::claim(getmypid());

        $updated = Connection::fetch('SELECT attempts FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame(1, (int) $updated['attempts']);
    }

    public function testClaimSetsWorkerPid(): void
    {
        $job = $this->insertJob($this->video['id']);
        $pid = getmypid();

        JobQueue::claim($pid);

        $updated = Connection::fetch('SELECT worker_pid FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame($pid, (int) $updated['worker_pid']);
    }

    public function testClaimSetsStatusToClaimed(): void
    {
        $job = $this->insertJob($this->video['id']);

        JobQueue::claim(getmypid());

        $updated = Connection::fetch('SELECT status FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame('claimed', $updated['status']);
    }

    public function testClaimReturnsNullWhenAllJobsAreClaimed(): void
    {
        $this->insertJob($this->video['id'], 'claimed');

        self::assertNull(JobQueue::claim(getmypid()));
    }

    public function testClaimSkipsJobsWithFutureRetryAfter(): void
    {
        $this->insertJob($this->video['id']);

        // Push retry_after into the future
        Connection::execute(
            "UPDATE encoding_jobs SET retry_after = NOW() + INTERVAL 1 HOUR WHERE video_id = :vid",
            [':vid' => $this->video['id']]
        );

        self::assertNull(JobQueue::claim(getmypid()));
    }

    public function testClaimPicksUpJobWithPastRetryAfter(): void
    {
        $this->insertJob($this->video['id']);

        // Set retry_after to the past
        Connection::execute(
            "UPDATE encoding_jobs SET retry_after = NOW() - INTERVAL 1 HOUR WHERE video_id = :vid",
            [':vid' => $this->video['id']]
        );

        $claimed = JobQueue::claim(getmypid());

        self::assertNotNull($claimed);
    }

    public function testClaimSkipsJobThatExceedsMaxAttempts(): void
    {
        $this->insertJob($this->video['id']);

        // Artificially set attempts = max_attempts
        Connection::execute(
            "UPDATE encoding_jobs SET attempts = max_attempts WHERE video_id = :vid",
            [':vid' => $this->video['id']]
        );

        self::assertNull(JobQueue::claim(getmypid()));
    }

    // -------------------------------------------------------------------------
    // markDone()
    // -------------------------------------------------------------------------

    public function testMarkDoneSetsStatusAndProgress(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');

        JobQueue::markDone($job['id']);

        $updated = Connection::fetch('SELECT status, progress_pct, current_stage FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame('done', $updated['status']);
        self::assertSame(100, (int) $updated['progress_pct']);
        self::assertSame('done', $updated['current_stage']);
    }

    public function testMarkDoneClearsWorkerPid(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');
        Connection::execute("UPDATE encoding_jobs SET worker_pid = 999 WHERE id = :id", [':id' => $job['id']]);

        JobQueue::markDone($job['id']);

        $updated = Connection::fetch('SELECT worker_pid FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertNull($updated['worker_pid']);
    }

    // -------------------------------------------------------------------------
    // markFailed()
    // -------------------------------------------------------------------------

    public function testMarkFailedSetsStatusToFailed(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');

        JobQueue::markFailed($job['id'], 'something went wrong');

        $updated = Connection::fetch('SELECT status, last_error, current_stage FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame('failed', $updated['status']);
        self::assertStringContainsString('something went wrong', $updated['last_error']);
        self::assertSame('failed', $updated['current_stage']);
    }

    // -------------------------------------------------------------------------
    // requeueForRetry()
    // -------------------------------------------------------------------------

    public function testRequeueForRetryFirstAttemptHasNoDelay(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');

        // attempts = 1 → delay = 0 (immediate)
        Connection::execute("UPDATE encoding_jobs SET attempts = 1 WHERE id = :id", [':id' => $job['id']]);

        JobQueue::requeueForRetry($job['id'], 'first error');

        $updated = Connection::fetch('SELECT status, retry_after FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame('queued', $updated['status']);
        self::assertNull($updated['retry_after']);
    }

    public function testRequeueForRetrySecondAttemptAdds60Seconds(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');
        Connection::execute("UPDATE encoding_jobs SET attempts = 2 WHERE id = :id", [':id' => $job['id']]);

        JobQueue::requeueForRetry($job['id'], 'second error');

        $updated = Connection::fetch('SELECT retry_after FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertNotNull($updated['retry_after'], 'retry_after should be set for attempt 2');
    }

    public function testRequeueForRetryAtMaxAttemptsMarksFailed(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');
        // max_attempts defaults to 3; set attempts = max_attempts
        Connection::execute("UPDATE encoding_jobs SET attempts = max_attempts WHERE id = :id", [':id' => $job['id']]);

        JobQueue::requeueForRetry($job['id'], 'terminal error');

        $updated = Connection::fetch('SELECT status FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame('failed', $updated['status']);
    }

    // -------------------------------------------------------------------------
    // isCancelRequested()
    // -------------------------------------------------------------------------

    public function testIsCancelRequestedReturnsFalseByDefault(): void
    {
        $job = $this->insertJob($this->video['id']);

        self::assertFalse(JobQueue::isCancelRequested($job['id']));
    }

    public function testIsCancelRequestedReturnsTrueAfterRequestCancel(): void
    {
        $job = $this->insertJob($this->video['id']);

        JobQueue::requestCancel($job['id']);

        self::assertTrue(JobQueue::isCancelRequested($job['id']));
    }

    // -------------------------------------------------------------------------
    // updateProgress()
    // -------------------------------------------------------------------------

    public function testUpdateProgressPersistsValues(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');

        JobQueue::updateProgress($job['id'], 47, '720p');

        $updated = Connection::fetch(
            'SELECT progress_pct, current_rendition, current_stage FROM encoding_jobs WHERE id = :id',
            [':id' => $job['id']]
        );
        self::assertSame(JobQueue::normalizeEncodingProgress(47), (int) $updated['progress_pct']);
        self::assertSame('720p', $updated['current_rendition']);
        self::assertSame('encoding', $updated['current_stage']);
    }

    public function testUpdateProgressClampsAbove100(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');

        JobQueue::updateProgress($job['id'], 150, '1080p');

        $updated = Connection::fetch('SELECT progress_pct FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame(95, (int) $updated['progress_pct']);
    }

    public function testUpdateProgressClampsBelow0(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');

        JobQueue::updateProgress($job['id'], -5, '480p');

        $updated = Connection::fetch('SELECT progress_pct FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertSame(30, (int) $updated['progress_pct']);
    }

    public function testTouchHeartbeatUpdatesHeartbeatAt(): void
    {
        $job = $this->insertJob($this->video['id'], 'claimed');
        Connection::execute('UPDATE encoding_jobs SET heartbeat_at = NULL WHERE id = :id', [':id' => $job['id']]);

        JobQueue::touchHeartbeat($job['id']);

        $updated = Connection::fetch('SELECT heartbeat_at FROM encoding_jobs WHERE id = :id', [':id' => $job['id']]);
        self::assertNotNull($updated['heartbeat_at']);
    }

    public function testReleaseForCapacityRequeuesWithoutConsumingAttempt(): void
    {
        $job = $this->insertJob($this->video['id']);
        JobQueue::claim(getmypid());

        JobQueue::releaseForCapacity($job['id'], 60, 'capacity deferred');

        $updated = Connection::fetch(
            'SELECT status, attempts, retry_after, current_stage, progress_pct FROM encoding_jobs WHERE id = :id',
            [':id' => $job['id']]
        );

        self::assertSame('queued', $updated['status']);
        self::assertSame(0, (int) $updated['attempts']);
        self::assertSame('queued', $updated['current_stage']);
        self::assertSame(0, (int) $updated['progress_pct']);
        self::assertNotNull($updated['retry_after']);
    }

    // -------------------------------------------------------------------------
    // findByVideoId()
    // -------------------------------------------------------------------------

    public function testFindByVideoIdReturnsRow(): void
    {
        $job = $this->insertJob($this->video['id']);

        $found = JobQueue::findByVideoId($this->video['id']);

        self::assertIsArray($found);
        self::assertSame((int) $job['id'], (int) $found['id']);
    }

    public function testFindByVideoIdReturnsNullForUnknownVideo(): void
    {
        self::assertNull(JobQueue::findByVideoId(999999));
    }

    public function testFindByVideoIdReturnsLatestJobWhenMultipleExist(): void
    {
        $video2 = $this->insertVideo();
        $job1   = $this->insertJob($video2['id']);
        $job2   = $this->insertJob($video2['id']);

        $found = JobQueue::findByVideoId($video2['id']);

        self::assertSame((int) $job2['id'], (int) $found['id']);
    }
}
