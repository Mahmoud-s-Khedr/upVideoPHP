<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

/**
 * POST /api/upload
 *
 * Validates the uploaded file, moves it out of /tmp immediately (C2),
 * creates DB records, and returns 202 with the video UUID.
 *
 * No B2 upload occurs in this request — that is the worker's first step (C1).
 */
final class UploadController
{
    /** Recognised quality labels in ladder order (highest → lowest). */
    public const QUALITY_LABELS = ['1080p', '720p', '540p', '480p', '360p'];

    private const ALLOWED_EXTENSIONS = [
        'video/mp4'          => 'mp4',
        'video/x-matroska'   => 'mkv',
        'video/mp2t'         => 'ts',
        'video/x-msvideo'    => 'avi',
        'video/quicktime'    => 'mov',
        'video/webm'         => 'webm',
    ];

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();

        // Parse admin-requested target qualities from multipart body
        $body        = (array) ($request->getParsedBody() ?? []);
        $rawQ        = isset($body['target_qualities']) && is_array($body['target_qualities'])
            ? $body['target_qualities']
            : [];
        $targetQualities = array_values(
            array_filter($rawQ, fn($q) => in_array($q, self::QUALITY_LABELS, true))
        );

        // Slim 4 normalises uploaded files — but for large file handling we also accept
        // $_FILES directly via parsed body. Check both.
        if (isset($uploadedFiles['file'])) {
            return $this->handleSlimUpload($request, $response, $uploadedFiles['file'], $targetQualities);
        }

        // Fall back to raw $_FILES (when Slim's body parsing is bypassed for large files)
        if (isset($_FILES['file'])) {
            return $this->handleRawUpload($response, $_FILES['file'], $targetQualities);
        }

        return $this->errorResponse($response, 422, 'MISSING_FILE', "No 'file' field found in the request.");
    }

    // -------------------------------------------------------------------------
    // Slim UploadedFileInterface path (standard)
    // -------------------------------------------------------------------------

    private function handleSlimUpload(
        ServerRequestInterface $request,
        ResponseInterface $response,
        \Psr\Http\Message\UploadedFileInterface $uploaded,
        array $targetQualities = [],
    ): ResponseInterface {
        // Build a normalised $_FILES-style array for the validator
        $tmpPath = tempnam(sys_get_temp_dir(), 'vup_');
        if ($tmpPath === false) {
            error_log('[UploadController] tempnam() failed; unable to create temp file.');
            return $this->errorResponse($response, 500, 'SERVER_ERROR', 'Could not create temporary file for upload.');
        }
        $uploaded->moveTo($tmpPath);

        $fileEntry = [
            'name'     => $uploaded->getClientFilename() ?? 'upload',
            'type'     => $uploaded->getClientMediaType() ?? '',
            'tmp_name' => $tmpPath,
            'error'    => $uploaded->getError(),
            'size'     => $uploaded->getSize() ?? 0,
        ];

        return $this->processFileEntry($response, $fileEntry, ownsTmp: true, targetQualities: $targetQualities);
    }

    // -------------------------------------------------------------------------
    // Raw $_FILES path (large uploads bypassing Slim body parsing)
    // -------------------------------------------------------------------------

    private function handleRawUpload(
        ResponseInterface $response,
        array $fileEntry,
        array $targetQualities = [],
    ): ResponseInterface {
        return $this->processFileEntry($response, $fileEntry, ownsTmp: false, targetQualities: $targetQualities);
    }

    // -------------------------------------------------------------------------
    // Core processing
    // -------------------------------------------------------------------------

    private function processFileEntry(
        ResponseInterface $response,
        array $fileEntry,
        bool $ownsTmp,
        array $targetQualities = [],
    ): ResponseInterface {
        $validator = new FileValidator(Config::ffprobeBin());

        try {
            $validator->validate($fileEntry);
        } catch (ValidationException $e) {
            if ($ownsTmp && file_exists($fileEntry['tmp_name'])) {
                @unlink($fileEntry['tmp_name']);
            }
            return $this->errorResponse($response, $e->getHttpStatus(), $e->getErrorCode(), $e->getMessage());
        }

        // Extract source height for quality-gating (non-fatal — NULL stored on failure)
        $sourceHeight = $this->ffprobeSourceHeight($fileEntry['tmp_name']);

        // ------------------------------------------------------------------
        // Move out of /tmp immediately — critical to avoid tmpwatch deletion (C2)
        // ------------------------------------------------------------------
        $uuid      = $this->generateUuid();
        $ext       = self::ALLOWED_EXTENSIONS[strtolower($fileEntry['type'])] ?? 'mp4';
        $incomingDir = Config::workDir() . '/incoming/' . $uuid;

        if (!@mkdir($incomingDir, 0750, recursive: true) && !is_dir($incomingDir)) {
            if ($ownsTmp && file_exists($fileEntry['tmp_name'])) {
                @unlink($fileEntry['tmp_name']);
            }
            return $this->errorResponse($response, 500, 'INTERNAL_ERROR', 'Could not create incoming directory.');
        }

        $incomingPath = $incomingDir . '/original.' . $ext;

        if ($ownsTmp) {
            // File was already moved to a temp path by Slim; rename it.
            if (!rename($fileEntry['tmp_name'], $incomingPath)) {
                @unlink($fileEntry['tmp_name']);
                return $this->errorResponse($response, 500, 'INTERNAL_ERROR', 'Could not move uploaded file.');
            }
        } else {
            // PHP's own temp file — use move_uploaded_file for security.
            if (!move_uploaded_file($fileEntry['tmp_name'], $incomingPath)) {
                return $this->errorResponse($response, 500, 'INTERNAL_ERROR', 'Could not move uploaded file.');
            }
        }

        // ------------------------------------------------------------------
        // Sanitise original filename for display (never used in paths)
        // ------------------------------------------------------------------
        $originalName = $this->sanitiseFilename($fileEntry['name']);

        // ------------------------------------------------------------------
        // Always queue immediately. When target_qualities is empty, null is
        // stored meaning "encode all applicable rungs" — the pipeline's
        // default when $selectedLabels is empty. When provided, the JSON
        // array constrains encoding to those specific labels.
        // ------------------------------------------------------------------
        $initialStatus = 'queued';
        $qualitiesJson = !empty($targetQualities)
            ? json_encode($targetQualities, JSON_THROW_ON_ERROR)
            : null;

        // ------------------------------------------------------------------
        // Insert DB records
        // ------------------------------------------------------------------
        $db = Connection::get();
        $db->beginTransaction();

        try {
            Connection::execute(
                'INSERT INTO videos (uuid, original_name, size_bytes, source_height, target_qualities, status)
                 VALUES (:uuid, :name, :size, :sh, :tq, :status)',
                [
                    ':uuid'   => $uuid,
                    ':name'   => $originalName,
                    ':size'   => $fileEntry['size'],
                    ':sh'     => $sourceHeight,
                    ':tq'     => $qualitiesJson,
                    ':status' => $initialStatus,
                ]
            );

            $videoId = Connection::lastInsertId();

            Connection::execute(
                'INSERT INTO encoding_jobs (video_id, status) VALUES (:vid, :status)',
                [':vid' => $videoId, ':status' => 'queued']
            );

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            @unlink($incomingPath);
            @rmdir($incomingDir);
            return $this->errorResponse($response, 500, 'INTERNAL_ERROR', 'Could not create database records.');
        }

        // ------------------------------------------------------------------
        // Return 202 Accepted immediately — no blocking B2 work here (C1)
        // ------------------------------------------------------------------
        $payload = json_encode([
            'video_uuid'      => $uuid,
            'status'          => $initialStatus,
            'qualities_set'   => $qualitiesJson !== null,
            'created_at'      => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ], JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response
            ->withStatus(202)
            ->withHeader('Content-Type', 'application/json');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40); // version 4
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80); // variant
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function sanitiseFilename(string $filename): string
    {
        // Strip path components and limit length — display only, never used in paths
        $base = basename($filename);
        return mb_substr($base, 0, 512);
    }

    /**
     * Returns the first video stream height from the file, or null on failure.
     * Quick call — reads stream metadata only, no decoding.
     */
    private function ffprobeSourceHeight(string $filePath): ?int
    {
        $cmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 %s 2>/dev/null',
            escapeshellarg(Config::ffprobeBin()),
            escapeshellarg($filePath)
        );

        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $h = (int) trim($output[0]);
        return $h > 0 ? $h : null;
    }

    private function errorResponse(ResponseInterface $response, int $status, string $code, string $message): ResponseInterface
    {
        $body = json_encode(['error' => $code, 'message' => $message], JSON_THROW_ON_ERROR);
        $response->getBody()->write($body);
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }
}
