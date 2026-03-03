<?php

declare(strict_types=1);

namespace VideoSystem\Api;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Encoding\MasterPlaylistBuilder;
use VideoSystem\Queue\JobQueue;
use VideoSystem\Storage\B2Client;
use VideoSystem\Worker\CrashRecovery;
use VideoSystem\Config\Config;

/**
 * Video management API endpoints.
 *
 * GET    /api/videos/{uuid}                           — Video metadata + renditions
 * GET    /api/videos/{uuid}/progress                  — Encoding progress (0–100)
 * DELETE /api/videos/{uuid}                           — Delete video, B2 files, and DB records
 * DELETE /api/videos/{uuid}/audio-tracks/{index}      — Remove one audio track from B2/DB and rebuild master.m3u8
 */
final class VideoController
{
    // -------------------------------------------------------------------------
    // GET /api/videos/{uuid}
    // -------------------------------------------------------------------------

    public function getMetadata(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid  = $request->getAttribute('uuid');
        $video = Connection::fetch('SELECT * FROM videos WHERE uuid = :uuid', [':uuid' => $uuid]);

        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        $renditions = Connection::fetchAll(
            'SELECT label, width, height, bitrate_kbps FROM renditions WHERE video_id = :vid ORDER BY height DESC',
            [':vid' => $video['id']]
        );

        $subtitles = Connection::fetchAll(
            'SELECT language_code, label, is_forced FROM subtitles WHERE video_id = :vid',
            [':vid' => $video['id']]
        );

        return $this->json($response, 200, [
            'video_uuid'   => $video['uuid'],
            'status'       => $video['status'],
            'original_name' => $video['original_name'],
            'duration_sec' => $video['duration_sec'],
            'size_bytes'   => $video['size_bytes'],
            'created_at'   => $video['created_at'],
            'updated_at'   => $video['updated_at'],
            'renditions'   => $renditions,
            'subtitles'    => $subtitles,
            'poster_url'   => $this->presignPoster($video['poster_b2_key'] ?? null),
        ]);
    }

    // -------------------------------------------------------------------------
    // GET /api/videos/{uuid}/progress
    // -------------------------------------------------------------------------

    public function getProgress(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid  = $request->getAttribute('uuid');
        $video = Connection::fetch('SELECT id, uuid, status FROM videos WHERE uuid = :uuid', [':uuid' => $uuid]);

        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        $job = JobQueue::findByVideoId($video['id']);

        return $this->json($response, 200, [
            'video_uuid'        => $video['uuid'],
            'status'            => $video['status'],
            'progress_pct'      => $job ? (int) $job['progress_pct'] : 0,
            'current_rendition' => $job ? $job['current_rendition'] : null,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/videos/{uuid}
    // -------------------------------------------------------------------------

    public function delete(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uuid  = $request->getAttribute('uuid');
        $video = Connection::fetch('SELECT * FROM videos WHERE uuid = :uuid', [':uuid' => $uuid]);

        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        $videoId = (int) $video['id'];
        $status  = $video['status'];

        // ---- Cancel in-progress job ----------------------------------------
        if (in_array($status, ['processing', 'uploading'], true)) {
            $job = JobQueue::findByVideoId($videoId);
            if ($job !== null) {
                JobQueue::requestCancel((int) $job['id']);
            }
        }

        // ---- Delete local working directories --------------------------------
        $workDir       = Config::workDir();
        $incomingDir   = $workDir . '/incoming/' . $uuid;
        $job           = $job ?? JobQueue::findByVideoId($videoId);
        $processingDir = $job ? $workDir . '/processing/' . $job['id'] : null;

        CrashRecovery::deleteDirectory($incomingDir);
        if ($processingDir) {
            CrashRecovery::deleteDirectory($processingDir);
        }

        // ---- Delete all B2 objects ------------------------------------------
        // We delete synchronously here (object count is bounded per video).
        // For very large rendition sets this can be async — 202 is returned
        // immediately as per the spec.
        try {
            B2Client::deletePrefix("videos/{$uuid}/");
        } catch (\RuntimeException $e) {
            // Log but don't fail the response — records will be cleaned
            error_log("B2 cleanup failed for {$uuid}: " . $e->getMessage());
        }

        // ---- Delete all DB records (cascades via FK) ------------------------
        Connection::execute('DELETE FROM videos WHERE id = :id', [':id' => $videoId]);

        return $this->json($response, 202, [
            'video_uuid' => $uuid,
            'deleted'    => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/videos/{uuid}/audio-tracks/{index}
    // -------------------------------------------------------------------------

    public function deleteAudioTrack(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $uuid      = $request->getAttribute('uuid');
        $rawIndex  = $request->getAttribute('index');

        if (!is_numeric($rawIndex) || (int) $rawIndex < 0) {
            return $this->json($response, 400, ['error' => 'BAD_REQUEST', 'message' => 'Track index must be a non-negative integer.']);
        }
        $index = (int) $rawIndex;

        $video = Connection::fetch('SELECT * FROM videos WHERE uuid = :uuid', [':uuid' => $uuid]);
        if ($video === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Video not found.']);
        }

        $videoId = (int) $video['id'];

        $track = Connection::fetch(
            'SELECT track_index FROM audio_tracks WHERE video_id = :vid AND track_index = :idx',
            [':vid' => $videoId, ':idx' => $index]
        );
        if ($track === null) {
            return $this->json($response, 404, ['error' => 'NOT_FOUND', 'message' => 'Audio track not found.']);
        }

        // Delete all B2 objects for this track (segments + playlist)
        try {
            B2Client::deletePrefix("videos/{$uuid}/audio_{$index}/");
        } catch (\RuntimeException $e) {
            error_log("B2 audio track cleanup failed for {$uuid}/audio_{$index}: " . $e->getMessage());
        }

        // Remove DB row
        Connection::execute(
            'DELETE FROM audio_tracks WHERE video_id = :vid AND track_index = :idx',
            [':vid' => $videoId, ':idx' => $index]
        );

        // Rebuild master.m3u8 if video is fully encoded
        if ($video['status'] === 'ready') {
            $renditions = Connection::fetchAll(
                'SELECT label FROM renditions WHERE video_id = :id ORDER BY height DESC',
                [':id' => $videoId]
            );
            if (!empty($renditions)) {
                $tmpDir = sys_get_temp_dir() . '/master_rebuild_' . bin2hex(random_bytes(8));
                mkdir($tmpDir, 0750, true);
                try {
                    (new MasterPlaylistBuilder($videoId, $uuid))
                        ->build($tmpDir, array_column($renditions, 'label'));
                } finally {
                    // Recursively remove all files and subdirectories under $tmpDir
                    foreach (new \RecursiveIteratorIterator(
                        new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
                        \RecursiveIteratorIterator::CHILD_FIRST
                    ) as $item) {
                        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                    }
                    @rmdir($tmpDir);
                }
            }
        }

        return $this->json($response, 202, [
            'video_uuid'  => $uuid,
            'track_index' => $index,
            'deleted'     => true,
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function presignPoster(?string $b2Key): ?string
    {
        if ($b2Key === null || $b2Key === '') {
            return null;
        }
        try {
            if (!B2Client::exists($b2Key)) {
                return null;
            }
            return B2Client::presignUrl($b2Key, 3600);
        } catch (\RuntimeException) {
            return null;
        }
    }

    private function json(ResponseInterface $response, int $status, array $data): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response->withStatus($status)->withHeader('Content-Type', 'application/json');
    }
}
