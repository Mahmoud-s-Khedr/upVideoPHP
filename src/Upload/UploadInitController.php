<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Encoding\RenditionLadder;
use VideoSystem\Storage\B2Client;

/**
 * POST /api/upload/init
 *
 * Step 1 of the two-step B2 direct-upload flow.
 *
 * Returns a pre-signed B2 PUT URL that the client uses to upload the file
 * directly to B2 storage — the server never buffers the file on disk.
 *
 * After the client PUT completes, the client must call POST /api/upload/complete
 * to verify the upload and queue the encoding job.
 *
 * Files above B2's single-part 5 GB cap are switched to multipart mode.
 */
final class UploadInitController
{
    private const ALLOWED_MIMES = [
        'video/mp4',
        'video/x-matroska',
        'video/mp2t',
        'video/x-msvideo',
        'video/quicktime',
        'video/webm',
    ];

    private const EXTENSIONS_BY_MIME = [
        'video/mp4'          => 'mp4',
        'video/x-matroska'   => 'mkv',
        'video/mp2t'         => 'ts',
        'video/x-msvideo'    => 'avi',
        'video/quicktime'    => 'mov',
        'video/webm'         => 'webm',
    ];

    private const ALLOWED_EXTENSIONS = ['mp4', 'mkv', 'ts', 'avi', 'mov', 'webm'];

    /** B2 S3 API single-part upload hard cap. */
    private const B2_SINGLE_PART_MAX = 5368709120; // 5 GB

    public const QUALITY_LABELS = ['1080p', '720p', '540p', '480p', '360p'];

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->parseBody($request);

        // --- Validate required fields ---
        $filename = isset($body['filename']) && is_string($body['filename'])
            ? trim($body['filename']) : null;

        if ($filename === null || $filename === '') {
            return $this->error($response, 422, 'MISSING_FIELD', "'filename' is required.");
        }

        $sizeBytes = $this->parsePositiveInt($body['size_bytes'] ?? null);

        if ($sizeBytes === null) {
            return $this->error($response, 422, 'MISSING_FIELD', "'size_bytes' must be a positive integer.");
        }

        if (!isset($body['content_type']) || !is_string($body['content_type'])) {
            return $this->error($response, 422, 'MISSING_FIELD', "'content_type' is required.");
        }
        $contentType = strtolower(trim($body['content_type']));

        if (!in_array($contentType, self::ALLOWED_MIMES, true)) {
            return $this->error($response, 422, 'INVALID_MIME',
                sprintf("content_type '%s' is not allowed.", $contentType));
        }

        // --- Enforce size limits ---
        $maxAllowed = Config::maxUploadBytes();
        if ($sizeBytes > $maxAllowed) {
            return $this->error($response, 413, 'FILE_TOO_LARGE', sprintf(
                'size_bytes %d exceeds limit of %d bytes.',
                $sizeBytes,
                $maxAllowed
            ));
        }

        // --- Normalize optional target_qualities ---
        $rawQualities    = isset($body['target_qualities']) && is_array($body['target_qualities'])
            ? $body['target_qualities'] : [];
        $targetQualities = array_values(array_filter(
            RenditionLadder::getLabels(),
            static fn(string $q): bool => in_array($q, $rawQualities, true)
        ));

        // --- Derive storage extension ---
        $ext = self::EXTENSIONS_BY_MIME[$contentType]
            ?? strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            $ext = 'mp4';
        }

        // --- Generate UUID and B2 key ---
        $uuid     = $this->generateUuid();
        $b2Key    = "videos/{$uuid}/original.{$ext}";
        $origName = mb_substr(basename($filename), 0, 512);
        $qualJson = !empty($targetQualities)
            ? json_encode($targetQualities, JSON_THROW_ON_ERROR)
            : null;

        $uploadMode = $sizeBytes > self::B2_SINGLE_PART_MAX ? 'multipart' : 'single';
        $multipartUploadId = null;

        // --- Insert videos row with status='pending' ---
        // original_b2_key is set NOW (before the upload) so orphan cleanup
        // knows the B2 key even if /complete is never called.
        try {
            Connection::execute(
                'INSERT INTO videos
                 (uuid, original_name, size_bytes, original_b2_key, target_qualities, status, original_upload_mode)
                 VALUES (:uuid, :name, :size, :b2key, :tq, \'pending\', :mode)',
                [
                    ':uuid'  => $uuid,
                    ':name'  => $origName,
                    ':size'  => $sizeBytes,
                    ':b2key' => $b2Key,
                    ':tq'    => $qualJson,
                    ':mode'  => $uploadMode,
                ]
            );
        } catch (\Throwable $e) {
            error_log('[UploadInitController] DB insert failed: ' . $e->getMessage());
            return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not create video record.');
        }

        // --- Generate upload session ---
        $ttl = Config::b2UploadPresignTtlSeconds();
        $uploadUrl = null;

        try {
            if ($uploadMode === 'single') {
                $uploadUrl = B2Client::presignPutUrl($b2Key, $contentType, $ttl);
            } else {
                $multipartUploadId = B2Client::createMultipartUpload($b2Key, $contentType);
                Connection::execute(
                    'UPDATE videos
                     SET multipart_upload_id = :upload_id,
                         multipart_parts_json = :parts
                     WHERE uuid = :uuid',
                    [
                        ':upload_id' => $multipartUploadId,
                        ':parts'     => json_encode([], JSON_THROW_ON_ERROR),
                        ':uuid'      => $uuid,
                    ]
                );
            }
        } catch (\Throwable $e) {
            // Roll back the DB row so we don't leave a dangling pending record.
            Connection::execute('DELETE FROM videos WHERE uuid = :uuid', [':uuid' => $uuid]);
            error_log('[UploadInitController] upload session init failed: ' . $e->getMessage());
            return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not initialise upload session.');
        }

        $partSize = Config::multipartPartSizeBytes();
        $payload = json_encode([
            'video_uuid'        => $uuid,
            'upload_mode'       => $uploadMode,
            'upload_url'        => $uploadUrl,
            'b2_key'            => $b2Key,
            'expires_in'        => $ttl,
            'part_size_bytes'   => $uploadMode === 'multipart' ? $partSize : null,
            'total_parts'       => $uploadMode === 'multipart' ? (int) ceil($sizeBytes / $partSize) : null,
            'created_at'        => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response
            ->withStatus(201)
            ->withHeader('Content-Type', 'application/json');
    }

    /**
     * Parse JSON body from request (handles both parsed and raw body).
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

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param mixed $value
     */
    private function parsePositiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value)) {
            return (int) $value;
        }

        return null;
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
