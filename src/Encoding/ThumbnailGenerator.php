<?php

declare(strict_types=1);

namespace VideoSystem\Encoding;

use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * Generates poster (single frame) and sprite sheet (seek preview grid) from a video.
 *
 * Sprite sheet height is capped to prevent GPU texture overflow (S5):
 *   - Mobile GPUs max out at 4096px; desktop at 16384px
 *   - We cap at 8192px (90px per row × rows ≤ 91 rows = 8190px max)
 *   - If more rows are needed, we reduce frame interval (sparser coverage)
 */
final class ThumbnailGenerator
{
    private const MAX_SPRITE_HEIGHT_PX = 8192;
    private const FRAME_HEIGHT_PX      = 90;
    private const FRAME_WIDTH_PX       = 160;
    private const DEFAULT_COLUMNS      = 10;
    private const DEFAULT_INTERVAL_SEC = 10;

    public function __construct(
        private readonly int     $videoId,
        private readonly string  $videoUuid,
        private readonly string  $inputFile,
        private readonly string  $processingDir,
        private readonly float   $durationSec,
        private readonly ?\Closure $execFn = null,
        private readonly ?\Closure $heartbeatFn = null,
    ) {}

    /** @var (callable(int $videoId, string $field, string $b2Key): void)|null */
    private static $testDbWriter = null;

    public static function setTestDbWriter(?callable $fn): void
    {
        self::$testDbWriter = $fn;
    }

    /**
     * Generate and upload poster + sprite. Updates videos table with B2 keys.
     */
    public function generate(): void
    {
        $this->generatePoster();

        if ($this->durationSec > 60) {
            $this->generateSprite();
        }
    }

    // -------------------------------------------------------------------------
    // Poster
    // -------------------------------------------------------------------------

    private function generatePoster(): void
    {
        $offset     = max(0, $this->durationSec / 2);
        $posterPath = $this->processingDir . '/poster.jpg';

        $cmd = sprintf(
            '%s -y -ss %s -i %s -frames:v 1 -q:v 2 %s',
            escapeshellarg(Config::ffmpegBin()),
            number_format($offset, 3, '.', ''),
            escapeshellarg($this->inputFile),
            escapeshellarg($posterPath)
        );

        $this->runExec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($posterPath)) {
            return; // Non-fatal: poster failure doesn't block the encode
        }

        $b2Key = "videos/{$this->videoUuid}/poster.jpg";
        B2Client::put($b2Key, $posterPath, 'image/jpeg');

        if (self::$testDbWriter !== null) {
            (self::$testDbWriter)($this->videoId, 'poster_b2_key', $b2Key);
        } else {
            Connection::execute(
                'UPDATE videos SET poster_b2_key = :key WHERE id = :id',
                [':key' => $b2Key, ':id' => $this->videoId]
            );
        }
    }

    // -------------------------------------------------------------------------
    // Sprite sheet
    // -------------------------------------------------------------------------

    private function generateSprite(): void
    {
        [$interval, $columns, $rows, $frameCount] = $this->calculateTileLayout();

        $spritePath = $this->processingDir . '/sprite.jpg';

        $cmd = sprintf(
            '%s -y -i %s -vf %s -frames:v 1 -q:v 2 %s',
            escapeshellarg(Config::ffmpegBin()),
            escapeshellarg($this->inputFile),
            escapeshellarg(sprintf(
                'fps=1/%d,scale=%d:%d,tile=%dx%d',
                $interval,
                self::FRAME_WIDTH_PX,
                self::FRAME_HEIGHT_PX,
                $columns,
                $rows
            )),
            escapeshellarg($spritePath)
        );

        $this->runExec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || !file_exists($spritePath)) {
            return; // Non-fatal
        }

        $b2Key = "videos/{$this->videoUuid}/sprite.jpg";
        B2Client::put($b2Key, $spritePath, 'image/jpeg');

        if (self::$testDbWriter !== null) {
            (self::$testDbWriter)($this->videoId, 'sprite_b2_key', $b2Key);
        } else {
            Connection::execute(
                'UPDATE videos
                 SET    sprite_b2_key    = :key,
                        sprite_columns   = :cols,
                        sprite_rows      = :rows,
                        sprite_frame_count = :frames
                 WHERE  id = :id',
                [
                    ':key'    => $b2Key,
                    ':cols'   => $columns,
                    ':rows'   => $rows,
                    ':frames' => $frameCount,
                    ':id'     => $this->videoId,
                ]
            );
        }
    }

    /**
     * Calculate tile grid dimensions that keep the sprite height ≤ MAX_SPRITE_HEIGHT_PX.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}  [interval, columns, rows, frameCount]
     */
    private function calculateTileLayout(): array
    {
        $interval   = self::DEFAULT_INTERVAL_SEC;
        $columns    = self::DEFAULT_COLUMNS;
        $maxRows    = (int) floor(self::MAX_SPRITE_HEIGHT_PX / self::FRAME_HEIGHT_PX);

        $frameCount = (int) ceil($this->durationSec / $interval);
        $rows       = (int) ceil($frameCount / $columns);

        // If rows exceed the cap, increase the interval to reduce frame count
        while ($rows > $maxRows && $interval < 60) {
            $interval   = (int) ($interval * 1.5);
            $frameCount = (int) ceil($this->durationSec / $interval);
            $rows       = (int) ceil($frameCount / $columns);
        }

        // Ensure at least 1 frame and 1 row
        $frameCount = max(1, $frameCount);
        $rows       = max(1, $rows);

        return [$interval, $columns, $rows, $frameCount];
    }

    /**
     * Run a shell command via exec(), or use the injected $execFn in tests.
     *
     * The callable signature is: fn(string $cmd, array &$output, int &$exitCode): void
     */
    private function runExec(string $cmd, mixed &$output, mixed &$exitCode): void
    {
        $output   = [];
        $exitCode = 0;
        if ($this->execFn !== null) {
            ($this->execFn)($cmd, $output, $exitCode);
        } else {
            $this->runProcessWithHeartbeat($cmd, $output, $exitCode);
        }
    }

    private function runProcessWithHeartbeat(string $cmd, mixed &$output, mixed &$exitCode): void
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes);
        if ($process === false) {
            $output   = [];
            $exitCode = 1;
            return;
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $lastHeartbeat = microtime(true);

        while (true) {
            $read = [];
            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }
            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read === []) {
                break;
            }

            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 1, 0);

            foreach ($read as $stream) {
                $chunk = stream_get_contents($stream);
                if ($chunk !== false && $chunk !== '' && $stream === $pipes[1]) {
                    $stdout .= $chunk;
                }
            }

            if ($this->heartbeatFn !== null && (microtime(true) - $lastHeartbeat) >= 30.0) {
                ($this->heartbeatFn)();
                $lastHeartbeat = microtime(true);
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $stdout = trim($stdout);
        $output = $stdout === '' ? [] : preg_split('/\R/', $stdout);
    }
}
