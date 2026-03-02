<?php

declare(strict_types=1);

namespace VideoSystem\Storage;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use VideoSystem\Config\Config;

/**
 * Backblaze B2 storage client using the AWS SDK S3-compatible API.
 *
 * All methods operate on object keys (paths within the B2 bucket).
 * The bucket name is read from the config; callers only pass keys.
 *
 * A test-override slot allows integration and unit tests to inject a
 * FakeB2Client without touching any call site:
 *
 *   B2Client::setTestOverride(new FakeB2Client());
 *   // ... run test ...
 *   B2Client::setTestOverride(null); // restore real implementation
 */
final class B2Client
{
    private static ?S3Client $client = null;

    /** @var B2ClientInterface|null Swapped-in during tests; null = use the real S3 client. */
    private static ?B2ClientInterface $testOverride = null;

    // -------------------------------------------------------------------------
    // Test seam
    // -------------------------------------------------------------------------

    /**
     * Replace the implementation for the duration of a test.
     * Always call with null in tearDown to restore the real client.
     */
    public static function setTestOverride(?B2ClientInterface $override): void
    {
        self::$testOverride = $override;
    }

    /** Return the currently active override (useful for assertions in tests). */
    public static function getTestOverride(): ?B2ClientInterface
    {
        return self::$testOverride;
    }

    // -------------------------------------------------------------------------
    // Client factory
    // -------------------------------------------------------------------------

    public static function client(): S3Client
    {
        if (self::$client === null) {
            self::$client = new S3Client([
                'version'                 => 'latest',
                'region'                  => Config::b2Region(),
                'endpoint'                => Config::b2Endpoint(),
                'credentials'             => [
                    'key'    => Config::b2KeyId(),
                    'secret' => Config::b2AppKey(),
                ],
                'use_path_style_endpoint' => true,
            ]);
        }

        return self::$client;
    }

    // -------------------------------------------------------------------------
    // Object operations
    // -------------------------------------------------------------------------

    /**
     * Upload a local file to B2.
     * Delegates to ObjectUploader for automatic multipart handling.
     *
     * @throws \RuntimeException on upload failure
     */
    public static function put(string $key, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        if (self::$testOverride !== null) {
            self::$testOverride->put($key, $localPath, $contentType);
            return;
        }
        $uploader = new ObjectUploader();
        $uploader->upload($key, $localPath, $contentType);
    }

    /**
     * Upload raw string content to B2.
     */
    public static function putContent(string $key, string $content, string $contentType = 'application/octet-stream'): void
    {
        if (self::$testOverride !== null) {
            self::$testOverride->putContent($key, $content, $contentType);
            return;
        }
        $tmpFile = tempnam(sys_get_temp_dir(), 'b2put_');
        file_put_contents($tmpFile, $content);
        try {
            self::put($key, $tmpFile, $contentType);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * Download an object and return its content as a string.
     * For large objects, consider using getStream() instead.
     *
     * @throws \RuntimeException if object not found or download fails
     */
    public static function getContent(string $key): string
    {
        if (self::$testOverride !== null) {
            return self::$testOverride->getContent($key);
        }
        try {
            $result = self::client()->getObject([
                'Bucket' => Config::b2Bucket(),
                'Key'    => $key,
            ]);
            return (string) $result['Body'];
        } catch (S3Exception $e) {
            throw new \RuntimeException("B2 get failed for key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Delete a single object. Silently succeeds if the object doesn't exist.
     */
    public static function delete(string $key): void
    {
        if (self::$testOverride !== null) {
            self::$testOverride->delete($key);
            return;
        }
        try {
            self::client()->deleteObject([
                'Bucket' => Config::b2Bucket(),
                'Key'    => $key,
            ]);
        } catch (S3Exception $e) {
            // 404 is not an error here — object was already gone
            if ($e->getStatusCode() !== 404) {
                throw new \RuntimeException("B2 delete failed for key '{$key}': " . $e->getMessage(), 0, $e);
            }
        }
    }

    /**
     * Delete multiple objects in batches of 1000.
     *
     * @param list<string> $keys
     */
    public static function deleteObjects(array $keys): void
    {
        if (empty($keys)) {
            return;
        }

        if (self::$testOverride !== null) {
            self::$testOverride->deleteObjects($keys);
            return;
        }

        foreach (array_chunk($keys, 1000) as $batch) {
            self::client()->deleteObjects([
                'Bucket' => Config::b2Bucket(),
                'Delete' => [
                    'Objects' => array_map(fn($k) => ['Key' => $k], $batch),
                    'Quiet'   => true,
                ],
            ]);
        }
    }

    /**
     * List all object keys under a given prefix.
     *
     * @return list<string>
     */
    public static function listObjects(string $prefix): array
    {
        if (self::$testOverride !== null) {
            return self::$testOverride->listObjects($prefix);
        }

        $keys   = [];
        $params = [
            'Bucket' => Config::b2Bucket(),
            'Prefix' => $prefix,
        ];

        do {
            $result = self::client()->listObjectsV2($params);

            foreach ($result['Contents'] ?? [] as $object) {
                $keys[] = $object['Key'];
            }

            $params['ContinuationToken'] = $result['NextContinuationToken'] ?? null;
        } while ($result['IsTruncated'] ?? false);

        return $keys;
    }

    /**
     * Delete all objects under a prefix (used for full video cleanup).
     */
    public static function deletePrefix(string $prefix): void
    {
        if (self::$testOverride !== null) {
            self::$testOverride->deletePrefix($prefix);
            return;
        }
        $keys = self::listObjects($prefix);
        if (!empty($keys)) {
            self::deleteObjects($keys);
        }
    }

    /**
     * Check if an object exists.
     */
    public static function exists(string $key): bool
    {
        if (self::$testOverride !== null) {
            return self::$testOverride->exists($key);
        }
        try {
            self::client()->headObject([
                'Bucket' => Config::b2Bucket(),
                'Key'    => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            if ($e->getStatusCode() === 404) {
                return false;
            }
            throw new \RuntimeException("B2 headObject failed for key '{$key}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate a pre-signed URL for temporary public access.
     *
     * @param int $ttlSeconds  How long the URL is valid (e.g. 300 for 5 minutes)
     */
    public static function presignUrl(string $key, int $ttlSeconds = 300): string
    {
        if (self::$testOverride !== null) {
            return self::$testOverride->presignUrl($key, $ttlSeconds);
        }
        $cmd = self::client()->getCommand('GetObject', [
            'Bucket' => Config::b2Bucket(),
            'Key'    => $key,
        ]);

        $request = self::client()->createPresignedRequest($cmd, '+' . $ttlSeconds . ' seconds');
        return (string) $request->getUri();
    }

    /**
     * Reset the S3 client singleton (useful after credential rotation).
     * Does not clear the test override — call setTestOverride(null) separately.
     */
    public static function reset(): void
    {
        self::$client = null;
    }
}
