<?php

declare(strict_types=1);

namespace VideoSystem\Storage;

use Aws\S3\ObjectUploader as AwsObjectUploader;
use Aws\Exception\AwsException;
use VideoSystem\Config\Config;

/**
 * Wraps the AWS SDK ObjectUploader with:
 *   - Automatic multipart upload for files >= 100 MB (M2)
 *   - Exponential backoff on transient failures (1s, 2s, 4s) before re-throwing (S3.3)
 */
final class ObjectUploader
{
    private const MULTIPART_THRESHOLD = 104857600; // 100 MB
    private const RETRY_DELAYS        = [1, 2, 4];  // seconds

    /**
     * Upload a local file to B2.
     *
     * @throws \RuntimeException after all retry attempts are exhausted
     */
    public function upload(string $key, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        $lastException = null;

        foreach (array_merge([0], self::RETRY_DELAYS) as $delaySec) {
            if ($delaySec > 0) {
                sleep($delaySec);
            }

            try {
                $handle = fopen($localPath, 'rb');
                if ($handle === false) {
                    throw new \RuntimeException("Cannot open file for upload: {$localPath}");
                }

                $uploader = new AwsObjectUploader(
                    B2Client::client(),
                    Config::b2Bucket(),
                    $key,
                    $handle,
                    'private',
                    [
                        'mup_threshold'  => self::MULTIPART_THRESHOLD,
                        'ContentType'    => $contentType,
                    ]
                );

                $uploader->upload();
                fclose($handle);
                return; // success
            } catch (AwsException $e) {
                fclose($handle);
                $lastException = new \RuntimeException(
                    "B2 upload failed for key '{$key}': " . $e->getMessage(),
                    0,
                    $e
                );
            } catch (\Throwable $e) {
                // Non-AWS exceptions (e.g. file read error) — don't retry
                throw new \RuntimeException("Upload aborted for key '{$key}': " . $e->getMessage(), 0, $e);
            }
        }

        throw $lastException ?? new \RuntimeException("B2 upload failed for key '{$key}' after retries.");
    }
}
