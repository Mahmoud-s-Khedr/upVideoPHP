<?php

declare(strict_types=1);

namespace VideoSystem\Storage;

/**
 * Contract for Backblaze B2 (S3-compatible) storage operations.
 *
 * Having this interface enables:
 *   - Fast in-memory fakes in unit and integration tests (FakeB2Client)
 *   - Future swap-out of the storage backend without touching callers
 *
 * All methods operate on object *keys* (paths within the bucket).
 * The bucket name is an implementation detail not exposed here.
 */
interface B2ClientInterface
{
    /**
     * Upload a local file to B2.
     *
     * @throws \RuntimeException on failure
     */
    public function put(string $key, string $localPath, string $contentType = 'application/octet-stream'): void;

    /**
     * Upload raw string content to B2.
     *
     * @throws \RuntimeException on failure
     */
    public function putContent(string $key, string $content, string $contentType = 'application/octet-stream'): void;

    /**
     * Download an object and return its full content.
     *
     * @throws \RuntimeException if the key does not exist or download fails
     */
    public function getContent(string $key): string;

    /**
     * Delete a single object. Silently succeeds if the object does not exist.
     */
    public function delete(string $key): void;

    /**
     * Delete multiple objects in batches.
     *
     * @param list<string> $keys
     */
    public function deleteObjects(array $keys): void;

    /**
     * List all object keys whose names begin with $prefix.
     *
     * @return list<string>
     */
    public function listObjects(string $prefix): array;

    /**
     * Delete every object whose key begins with $prefix.
     */
    public function deletePrefix(string $prefix): void;

    /**
     * Return true if the object exists, false otherwise.
     */
    public function exists(string $key): bool;

    /**
     * Generate a pre-signed URL valid for $ttlSeconds.
     *
     * @throws \RuntimeException on failure
     */
    public function presignUrl(string $key, int $ttlSeconds = 300): string;

    /**
     * Generate a pre-signed PUT URL for direct client upload.
     *
     * B2 S3 API single-part PUT limit is 5 GB. Callers must enforce this
     * before calling this method.
     *
     * @throws \RuntimeException on failure
     */
    public function presignPutUrl(string $key, string $contentType, int $ttlSeconds): string;

    /**
     * Stream-download a B2 object to a local file path.
     * Must not load the entire object into memory.
     *
     * @throws \RuntimeException if the key does not exist or download fails
     */
    public function download(string $key, string $localPath): void;

    /**
     * Return object metadata without downloading the body.
     * Returns null if the object does not exist.
     *
     * @return array{size: int, content_type: string}|null
     */
    public function stat(string $key): ?array;
}
