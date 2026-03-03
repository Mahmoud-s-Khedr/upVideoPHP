<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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
    /** Recognised quality labels in ladder order (highest -> lowest). */
    public const QUALITY_LABELS = VideoUploadService::QUALITY_LABELS;

    public function handle(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $uploadedFiles = $request->getUploadedFiles();
        $service = new VideoUploadService();

        $body        = (array) ($request->getParsedBody() ?? []);
        $targetQualities = $service->normalizeQualities(isset($body['target_qualities']) && is_array($body['target_qualities'])
            ? $body['target_qualities']
            : []);

        try {
            if (isset($uploadedFiles['file'])) {
                $result = $service->uploadSlimFile($uploadedFiles['file'], $targetQualities);
            } elseif (isset($_FILES['file'])) {
                $result = $service->uploadRawFile($_FILES['file'], $targetQualities);
            } else {
                return $this->errorResponse($response, 422, 'MISSING_FILE', "No 'file' field found in the request.");
            }
        } catch (ValidationException $e) {
            return $this->errorResponse($response, $e->getHttpStatus(), $e->getErrorCode(), $e->getMessage());
        } catch (\RuntimeException $e) {
            error_log('[UploadController] ' . $e->getMessage());
            return $this->errorResponse($response, 500, 'INTERNAL_ERROR', $e->getMessage());
        }

        $payload = json_encode($result, JSON_THROW_ON_ERROR);

        $response->getBody()->write($payload);
        return $response
            ->withStatus(202)
            ->withHeader('Content-Type', 'application/json');
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
