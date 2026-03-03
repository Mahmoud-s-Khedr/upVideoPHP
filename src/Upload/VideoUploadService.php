<?php

declare(strict_types=1);

namespace VideoSystem\Upload;

use Psr\Http\Message\UploadedFileInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;

final class VideoUploadService
{
    /** Recognised quality labels in ladder order (highest -> lowest). */
    public const QUALITY_LABELS = ['1080p', '720p', '540p', '480p', '360p'];

    private const EXTENSIONS_BY_MIME = [
        'video/mp4'          => 'mp4',
        'video/x-matroska'   => 'mkv',
        'video/mp2t'         => 'ts',
        'video/x-msvideo'    => 'avi',
        'video/quicktime'    => 'mov',
        'video/webm'         => 'webm',
    ];

    private const ALLOWED_EXTENSIONS = [
        'mp4',
        'mkv',
        'ts',
        'avi',
        'mov',
        'webm',
    ];

    /**
     * @param array<int, string> $targetQualities
     * @return array{video_uuid:string,video_id:int,status:string,qualities_set:bool,created_at:string}
     */
    public function uploadSlimFile(UploadedFileInterface $uploaded, array $targetQualities = []): array
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vup_');
        if ($tmpPath === false) {
            error_log('[VideoUploadService] tempnam() failed; unable to create temp file.');
            throw new \RuntimeException('Could not create temporary file for upload.');
        }

        try {
            $uploaded->moveTo($tmpPath);
        } catch (\Throwable $e) {
            @unlink($tmpPath);
            throw new \RuntimeException('Could not move uploaded file.', 0, $e);
        }

        $fileEntry = [
            'name'     => $uploaded->getClientFilename() ?? 'upload',
            'type'     => $uploaded->getClientMediaType() ?? '',
            'tmp_name' => $tmpPath,
            'error'    => $uploaded->getError(),
            'size'     => $uploaded->getSize() ?? 0,
        ];

        return $this->processFileEntry($fileEntry, ownsTmp: true, targetQualities: $targetQualities);
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $fileEntry
     * @param array<int, string> $targetQualities
     * @return array{video_uuid:string,video_id:int,status:string,qualities_set:bool,created_at:string}
     */
    public function uploadRawFile(array $fileEntry, array $targetQualities = []): array
    {
        return $this->processFileEntry($fileEntry, ownsTmp: false, targetQualities: $targetQualities);
    }

    /**
     * @param array<int, string> $targetQualities
     * @return array<int, string>
     */
    public function normalizeQualities(array $targetQualities): array
    {
        return array_values(
            array_filter(
                self::QUALITY_LABELS,
                static fn(string $quality): bool => in_array($quality, $targetQualities, true)
            )
        );
    }

    /**
     * @param array{name?:string,type?:string,tmp_name?:string,error?:int,size?:int} $fileEntry
     * @param array<int, string> $targetQualities
     * @return array{video_uuid:string,video_id:int,status:string,qualities_set:bool,created_at:string}
     */
    private function processFileEntry(array $fileEntry, bool $ownsTmp, array $targetQualities = []): array
    {
        $validator = new FileValidator(Config::ffprobeBin());
        $targetQualities = $this->normalizeQualities($targetQualities);

        try {
            $validator->validate($fileEntry);
        } catch (ValidationException $e) {
            if ($ownsTmp && isset($fileEntry['tmp_name']) && file_exists((string) $fileEntry['tmp_name'])) {
                @unlink((string) $fileEntry['tmp_name']);
            }
            throw $e;
        }

        $tmpName = (string) ($fileEntry['tmp_name'] ?? '');
        $sourceHeight = $this->ffprobeSourceHeight($tmpName);
        $uuid = $this->generateUuid();
        $mimeType = strtolower((string) ($fileEntry['type'] ?? ''));
        $originalFilename = (string) ($fileEntry['name'] ?? 'upload');
        $extension = $this->resolveStorageExtension($mimeType, $originalFilename);
        $incomingDir = Config::workDir() . '/incoming/' . $uuid;

        if (!@mkdir($incomingDir, 0750, recursive: true) && !is_dir($incomingDir)) {
            if ($ownsTmp && file_exists($tmpName)) {
                @unlink($tmpName);
            }
            throw new \RuntimeException('Could not create incoming directory.');
        }

        $incomingPath = $incomingDir . '/original.' . $extension;

        if ($ownsTmp) {
            if (!rename($tmpName, $incomingPath)) {
                @unlink($tmpName);
                @rmdir($incomingDir);
                throw new \RuntimeException('Could not move uploaded file.');
            }
        } else {
            if (!move_uploaded_file($tmpName, $incomingPath)) {
                @rmdir($incomingDir);
                throw new \RuntimeException('Could not move uploaded file.');
            }
        }

        $originalName = $this->sanitiseFilename($originalFilename);
        $initialStatus = 'queued';
        $qualitiesJson = !empty($targetQualities)
            ? json_encode($targetQualities, JSON_THROW_ON_ERROR)
            : null;

        $db = Connection::get();
        $db->beginTransaction();

        try {
            Connection::execute(
                'INSERT INTO videos (uuid, original_name, size_bytes, source_height, target_qualities, status)
                 VALUES (:uuid, :name, :size, :sh, :tq, :status)',
                [
                    ':uuid'   => $uuid,
                    ':name'   => $originalName,
                    ':size'   => (int) ($fileEntry['size'] ?? 0),
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
            throw new \RuntimeException('Could not create database records.', 0, $e);
        }

        return [
            'video_uuid'    => $uuid,
            'video_id'      => $videoId,
            'status'        => $initialStatus,
            'qualities_set' => $qualitiesJson !== null,
            'created_at'    => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    private function sanitiseFilename(string $filename): string
    {
        return mb_substr(basename($filename), 0, 512);
    }

    private function resolveStorageExtension(string $mimeType, string $filename): string
    {
        if (isset(self::EXTENSIONS_BY_MIME[$mimeType])) {
            return self::EXTENSIONS_BY_MIME[$mimeType];
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return $extension;
        }

        return 'mp4';
    }

    private function ffprobeSourceHeight(string $filePath): ?int
    {
        $cmd = sprintf(
            '%s -v error -select_streams v:0 -show_entries stream=height -of csv=p=0 %s 2>/dev/null',
            escapeshellarg(Config::ffprobeBin()),
            escapeshellarg($filePath)
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        if ($exitCode !== 0 || empty($output)) {
            return null;
        }

        $height = (int) trim($output[0]);
        return $height > 0 ? $height : null;
    }
}
