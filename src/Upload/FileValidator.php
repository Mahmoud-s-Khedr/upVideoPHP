<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use VideoSystem\Config\Config;

/**
 * 5-stage file validation pipeline for uploaded video files.
 *
 * Stage 1 — Size check        (before any disk I/O beyond PHP's own temp file)
 * Stage 2 — MIME allowlist    (declared Content-Type; not trusted alone)
 * Stage 3 — Magic bytes       (binary file signature)
 * Stage 4 — ffprobe format    (container format allowlist)
 * Stage 5 — Video stream      (file must contain at least one video stream)
 *
 * @throws ValidationException on any failed stage
 */
final class FileValidator
{
    private const ALLOWED_MIMES = [
        'video/mp4',
        'video/x-matroska',
        'video/mp2t',
        'video/x-msvideo',
        'video/quicktime',
        'video/webm',
    ];

    private const ALLOWED_FFPROBE_FORMATS = [
        'mp4',
        'mov',
        'matroska',
        'avi',
        'mpegts',
        'webm',
    ];

    public function __construct(
        private readonly string $ffprobeBin = '/usr/bin/ffprobe',
    ) {}

    /**
     * @param array{name: string, type: string, tmp_name: string, error: int, size: int} $fileEntry
     *   The entry from $_FILES['file']
     */
    public function validate(array $fileEntry): void
    {
        // ------------------------------------------------------------------
        // Stage 1: Size
        // ------------------------------------------------------------------
        if ($fileEntry['error'] === UPLOAD_ERR_INI_SIZE || $fileEntry['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new ValidationException('FILE_TOO_LARGE', 'Uploaded file exceeds the allowed size limit.', 413);
        }

        if ($fileEntry['error'] !== UPLOAD_ERR_OK) {
            throw new ValidationException('UPLOAD_ERROR', 'File upload failed with error code: ' . $fileEntry['error'], 422);
        }

        if ($fileEntry['size'] > Config::maxUploadBytes()) {
            throw new ValidationException(
                'FILE_TOO_LARGE',
                sprintf('File size %d exceeds limit of %d bytes.', $fileEntry['size'], Config::maxUploadBytes()),
                413
            );
        }

        // ------------------------------------------------------------------
        // Stage 2: MIME allowlist
        // ------------------------------------------------------------------
        $declaredMime = strtolower(trim($fileEntry['type']));
        if (!in_array($declaredMime, self::ALLOWED_MIMES, true)) {
            throw new ValidationException(
                'INVALID_MIME',
                sprintf("MIME type '%s' is not allowed.", $declaredMime),
                422
            );
        }

        // ------------------------------------------------------------------
        // Stage 3: Magic bytes
        // ------------------------------------------------------------------
        if (!MagicBytesChecker::check($fileEntry['tmp_name'])) {
            throw new ValidationException(
                'INVALID_FILE_MAGIC',
                'File binary signature does not match a recognised video container.',
                422
            );
        }

        // ------------------------------------------------------------------
        // Stage 4: ffprobe format check
        // ------------------------------------------------------------------
        $formatName = $this->ffprobeFormat($fileEntry['tmp_name']);
        if ($formatName === null) {
            throw new ValidationException(
                'INVALID_VIDEO',
                'ffprobe could not identify the file as a valid video container.',
                422
            );
        }

        // ffprobe can return comma-separated formats (e.g. "mov,mp4,m4a,3gp,3g2,mj2").
        // Some output formats may also quote the full field, so strip wrapping quotes.
        $reportedFormats = array_values(array_filter(array_map(
            static fn(string $fmt): string => trim($fmt, " \t\n\r\0\x0B\"'"),
            explode(',', trim($formatName))
        ), static fn(string $fmt): bool => $fmt !== ''));
        $matched         = false;
        foreach ($reportedFormats as $fmt) {
            if (in_array($fmt, self::ALLOWED_FFPROBE_FORMATS, true)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            throw new ValidationException(
                'INVALID_VIDEO',
                sprintf("ffprobe reported format '%s' which is not on the allowlist.", $formatName),
                422
            );
        }

        // ------------------------------------------------------------------
        // Stage 5: Video stream present
        // ------------------------------------------------------------------
        if (!$this->ffprobeHasVideoStream($fileEntry['tmp_name'])) {
            throw new ValidationException(
                'NO_VIDEO_STREAM',
                'The uploaded file contains no detectable video stream.',
                422
            );
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns the format_name string from ffprobe, or null on failure.
     * Never throws — invalid files produce a null return.
     */
    private function ffprobeFormat(string $filePath): ?string
    {
        $cmd = sprintf(
            '%s -v error -show_entries format=format_name -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($this->ffprobeBin),
            escapeshellarg($filePath)
        );

        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        return trim(implode('', $output));
    }

    /**
     * Returns true if ffprobe detects at least one video stream in the file.
     */
    private function ffprobeHasVideoStream(string $filePath): bool
    {
        $cmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=codec_type -of csv=p=0 %s 2>/dev/null',
            escapeshellarg($this->ffprobeBin),
            escapeshellarg($filePath)
        );

        $output   = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0) {
            return false;
        }

        foreach ($output as $line) {
            if (trim($line) === 'video') {
                return true;
            }
        }

        return false;
    }
}
