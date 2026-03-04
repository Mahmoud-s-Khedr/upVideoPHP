<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Storage\B2Client;

/**
 * POST /api/upload/{uuid}/complete-multipart
 *
 * Finalizes a multipart upload using persisted and/or client-supplied part ETags.
 */
final class UploadMultipartCompleteController
{
    public function handle(ServerRequestInterface $request, ResponseInterface $response, array $args = []): ResponseInterface
    {
        $uuid = (string) ($args['uuid'] ?? $request->getAttribute('uuid') ?? '');
        if ($uuid === '') {
            return $this->error($response, 422, 'MISSING_FIELD', "'video_uuid' route parameter is required.");
        }

        $body = $this->parseBody($request);

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

        $storedParts = $this->decodePartsJson((string) ($video['multipart_parts_json'] ?? ''));
        $inputParts  = $this->decodePartsArray($body['parts'] ?? null);

        $parts = $storedParts;
        foreach ($inputParts as $inputPart) {
            $parts = $this->upsertPart($parts, $inputPart['part_number'], $inputPart['etag']);
        }

        if ($parts === []) {
            return $this->error($response, 422, 'MISSING_PARTS', 'No multipart part ETags were provided.');
        }

        try {
            B2Client::completeMultipartUpload((string) $video['original_b2_key'], $uploadId, $parts);
        } catch (\Throwable $e) {
            error_log('[UploadMultipartCompleteController] completeMultipartUpload failed: ' . $e->getMessage());
            return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not finalize multipart upload.');
        }

        $videoId = (int) $video['id'];

        // --- Atomically transition pending → queued and insert encoding job ---
        $db = Connection::get();
        $db->beginTransaction();

        try {
            Connection::execute(
                "UPDATE videos
                 SET status = 'queued',
                     multipart_upload_id  = NULL,
                     multipart_parts_json = :parts
                 WHERE id = :id",
                [
                    ':parts' => json_encode($parts, JSON_THROW_ON_ERROR),
                    ':id'    => $videoId,
                ]
            );

            Connection::execute(
                "INSERT INTO encoding_jobs (video_id, status) VALUES (:vid, 'queued')",
                [':vid' => $videoId]
            );

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[UploadMultipartCompleteController] DB transaction failed: ' . $e->getMessage());
            return $this->error($response, 500, 'INTERNAL_ERROR', 'Could not queue encoding job.');
        }

        return $this->json($response, 202, [
            'video_uuid'   => $uuid,
            'video_id'     => $videoId,
            'status'       => 'queued',
            'parts_total'  => count($parts),
            'finalized_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
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

        return $this->decodePartsArray($decoded);
    }

    /**
     * @param mixed $value
     * @return list<array{part_number:int,etag:string}>
     */
    private function decodePartsArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $parts = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }

            $partNumber = isset($item['part_number']) ? (int) $item['part_number'] : 0;
            $etag       = isset($item['etag']) && is_string($item['etag']) ? trim($item['etag']) : '';

            if ($partNumber <= 0 || $etag === '') {
                continue;
            }

            $parts[] = ['part_number' => $partNumber, 'etag' => $etag];
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
