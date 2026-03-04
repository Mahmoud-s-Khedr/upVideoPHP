<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * POST /api/upload/complete
 *
 * Step 2 of the two-step B2 direct-upload flow.
 *
 * Called by the client after it has PUT the file to the presigned URL
 * returned by POST /api/upload/init. Verifies the file exists in B2,
 * then queues an encoding job.
 */
final class UploadCompleteController
{
    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->parseBody($request);

        $uuid = isset($body['video_uuid']) && is_string($body['video_uuid'])
            ? trim($body['video_uuid']) : null;

        if ($uuid === null || $uuid === '') {
            return $this->error($response, 422, 'MISSING_FIELD', "'video_uuid' is required.");
        }

        // --- Load the video row ---
        $video = Connection::fetch(
            'SELECT id, status, original_b2_key FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->error($response, 404, 'NOT_FOUND',
                "No video found with uuid '{$uuid}'.");
        }

        // Guard against double-submit or calling complete on an already-queued video
        if ($video['status'] !== 'pending') {
            return $this->error($response, 409, 'ALREADY_QUEUED',
                "Video '{$uuid}' is already in status '{$video['status']}'.");
        }

        $b2Key = (string) $video['original_b2_key'];

        // --- Verify file exists in B2 via HeadObject (no body download) ---
        try {
            $stat = B2Client::stat($b2Key);
        } catch (\Throwable $e) {
            error_log('[UploadCompleteController] B2 stat failed: ' . $e->getMessage());
            return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not verify file in storage.');
        }

        if ($stat === null) {
            return $this->error($response, 422, 'FILE_NOT_IN_B2',
                'The file has not been uploaded to storage yet. ' .
                'Complete the PUT to the presigned URL before calling /complete.');
        }

        $videoId = (int) $video['id'];

        // --- Atomically transition pending → queued and insert encoding job ---
        $db = Connection::get();
        $db->beginTransaction();

        try {
            // Update size_bytes from the actual B2 object size — more accurate
            // than the client-declared value provided in /init.
            Connection::execute(
                "UPDATE videos SET status = 'queued', size_bytes = :size WHERE id = :id",
                [':size' => $stat['size'], ':id' => $videoId]
            );

            Connection::execute(
                "INSERT INTO encoding_jobs (video_id, status) VALUES (:vid, 'queued')",
                [':vid' => $videoId]
            );

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[UploadCompleteController] DB transaction failed: ' . $e->getMessage());
            return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not queue encoding job.');
        }

        $payload = json_encode([
            'video_uuid' => $uuid,
            'video_id'   => $videoId,
            'status'     => 'queued',
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response
            ->withStatus(202)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function error(
        ResponseInterface $response,
        int $status,
        string $code,
        string $message
    ): ResponseInterface {
        $body = json_encode(['error' => $code, 'message' => $message], JSON_THROW_ON_ERROR);
        $response->getBody()->write($body);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
