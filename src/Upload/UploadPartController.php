<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * POST /api/upload/{uuid}/parts
 *
 * Multipart helper endpoint used by browser clients:
 *   - Sign one part URL (part_number only)
 *   - Persist one uploaded part ETag (part_number + etag)
 */
final class UploadPartController
{
    public function handle(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $uuid = (string) ($args['uuid'] ?? $request->getAttribute('uuid') ?? '');
        if ($uuid === '') {
            return $this->error($response, 422, 'MISSING_FIELD', "'video_uuid' route parameter is required.");
        }

        $body = $this->parseBody($request);
        $partNumber = $this->parsePositiveInt($body['part_number'] ?? null);
        if ($partNumber === null) {
            return $this->error($response, 422, 'MISSING_FIELD', "'part_number' must be a positive integer.");
        }

        $video = Connection::fetch(
            'SELECT id, status, original_b2_key, original_upload_mode, multipart_upload_id, multipart_parts_json
             FROM videos
             WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );

        if ($video === null) {
            return $this->error($response, 404, 'NOT_FOUND', "No video found with uuid '{$uuid}'.");
        }

        if ((string) $video['status'] !== 'pending') {
            return $this->error(
                $response,
                409,
                'ALREADY_QUEUED',
                "Video '{$uuid}' is already in status '{$video['status']}'."
            );
        }

        if ((string) ($video['original_upload_mode'] ?? 'single') !== 'multipart') {
            return $this->error($response, 409, 'NOT_MULTIPART', 'This upload does not use multipart mode.');
        }

        $uploadId = (string) ($video['multipart_upload_id'] ?? '');
        if ($uploadId === '') {
            return $this->error($response, 409, 'UPLOAD_ID_MISSING', 'Multipart upload ID is missing or already finalized.');
        }

        $parts = $this->decodePartsJson((string) ($video['multipart_parts_json'] ?? ''));

        $etag = isset($body['etag']) && is_string($body['etag'])
            ? trim($body['etag'])
            : '';

        if ($etag !== '') {
            $videoId = (int) $video['id'];
            $db = Connection::get();
            $db->beginTransaction();

            try {
                // Lock the row before re-reading to prevent concurrent lost updates.
                $locked = Connection::fetch(
                    'SELECT multipart_parts_json FROM videos WHERE id = :id FOR UPDATE',
                    [':id' => $videoId]
                );

                $freshParts = $this->decodePartsJson((string) ($locked['multipart_parts_json'] ?? ''));
                $freshParts = $this->upsertPart($freshParts, $partNumber, $etag);

                Connection::execute(
                    'UPDATE videos SET multipart_parts_json = :parts WHERE id = :id',
                    [
                        ':parts' => json_encode($freshParts, JSON_THROW_ON_ERROR),
                        ':id'    => $videoId,
                    ]
                );

                $db->commit();
            } catch (\Throwable $e) {
                $db->rollBack();
                error_log('[UploadPartController] DB transaction failed: ' . $e->getMessage());
                return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not record part ETag.');
            }

            return $this->json($response, 200, [
                'video_uuid'   => $uuid,
                'part_number'  => $partNumber,
                'recorded'     => true,
                'parts_stored' => count($freshParts),
            ]);
        }

        $ttl = Config::b2UploadPresignTtlSeconds();
        try {
            $uploadUrl = B2Client::presignMultipartPartUrl(
                (string) $video['original_b2_key'],
                $uploadId,
                $partNumber,
                $ttl
            );
        } catch (\Throwable $e) {
            error_log('[UploadPartController] presignMultipartPartUrl failed: ' . $e->getMessage());
            return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not generate multipart part URL.');
        }

        $existing = null;
        foreach ($parts as $part) {
            if ((int) $part['part_number'] === $partNumber) {
                $existing = (string) $part['etag'];
                break;
            }
        }

        return $this->json($response, 200, [
            'video_uuid'   => $uuid,
            'part_number'  => $partNumber,
            'upload_url'   => $uploadUrl,
            'expires_in'   => $ttl,
            'etag'         => $existing,
        ]);
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

    /**
     * @return list<array{part_number:int,etag:string}>
     */
    private function decodePartsJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $parts = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $partNumber = isset($item['part_number']) ? (int) $item['part_number'] : 0;
            $etag       = isset($item['etag']) && is_string($item['etag']) ? trim($item['etag']) : '';
            if ($partNumber > 0 && $etag !== '') {
                $parts[] = ['part_number' => $partNumber, 'etag' => $etag];
            }
        }

        usort($parts, static fn(array $a, array $b): int => $a['part_number'] <=> $b['part_number']);
        return $parts;
    }

    /**
     * @param list<array{part_number:int,etag:string}> $parts
     * @return list<array{part_number:int,etag:string}>
     */
    private function upsertPart(array $parts, int $partNumber, string $etag): array
    {
        $updated = false;
        foreach ($parts as &$part) {
            if ($part['part_number'] === $partNumber) {
                $part['etag'] = $etag;
                $updated = true;
                break;
            }
        }
        unset($part);

        if (!$updated) {
            $parts[] = ['part_number' => $partNumber, 'etag' => $etag];
        }

        usort($parts, static fn(array $a, array $b): int => $a['part_number'] <=> $b['part_number']);
        return $parts;
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
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function error(ResponseInterface $response, int $status, string $code, string $message): ResponseInterface
    {
        return $this->json($response, $status, ['error' => $code, 'message' => $message]);
    }
}
