<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Support;

use VideoSystem\Storage\B2ClientInterface;

/**
 * In-memory B2 client for tests.
 *
 * Stores objects in a plain PHP array so tests neither need a real B2 bucket
 * nor a local S3-compatible service.
 *
 * Usage:
 *   $fake = new FakeB2Client();
 *   B2Client::setTestOverride($fake);
 *   // ... run code under test ...
 *   B2Client::setTestOverride(null); // tear down
 *
 *   // Assertions:
 *   $this->assertTrue($fake->hasKey('videos/uuid/master.m3u8'));
 *   $this->assertSame('expected-content', $fake->read('videos/uuid/master.m3u8'));
 */
final class FakeB2Client implements B2ClientInterface
{
    /** @var array<string, string>  key => raw content */
    private array $store = [];

    /** @var array<string, array{key:string,content_type:string,parts:array<int,string>}> */
    private array $multipartUploads = [];

    /** @var list<array{method: string, key: string, args: array<mixed>}> */
    private array $callLog = [];

    // -------------------------------------------------------------------------
    // B2ClientInterface implementation
    // -------------------------------------------------------------------------

    public function put(string $key, string $localPath, string $contentType = 'application/octet-stream'): void
    {
        if (!file_exists($localPath)) {
            throw new \RuntimeException("FakeB2Client::put — local file not found: {$localPath}");
        }
        $content = file_get_contents($localPath);
        if ($content === false) {
            throw new \RuntimeException("FakeB2Client::put — cannot read: {$localPath}");
        }
        $this->store[$key] = $content;
        $this->callLog[]   = ['method' => 'put', 'key' => $key, 'args' => [$localPath, $contentType]];
    }

    public function putContent(string $key, string $content, string $contentType = 'application/octet-stream'): void
    {
        $this->store[$key] = $content;
        $this->callLog[]   = ['method' => 'putContent', 'key' => $key, 'args' => [strlen($content) . ' bytes', $contentType]];
    }

    public function getContent(string $key): string
    {
        if (!isset($this->store[$key])) {
            throw new \RuntimeException("FakeB2Client::getContent — key not found: {$key}");
        }
        $this->callLog[] = ['method' => 'getContent', 'key' => $key, 'args' => []];
        return $this->store[$key];
    }

    public function delete(string $key): void
    {
        unset($this->store[$key]);
        $this->callLog[] = ['method' => 'delete', 'key' => $key, 'args' => []];
    }

    /** @param list<string> $keys */
    public function deleteObjects(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }
        $this->callLog[] = ['method' => 'deleteObjects', 'key' => implode(',', $keys), 'args' => []];
    }

    public function listObjects(string $prefix): array
    {
        $keys = array_values(
            array_filter(array_keys($this->store), static fn($k) => str_starts_with($k, $prefix))
        );
        $this->callLog[] = ['method' => 'listObjects', 'key' => $prefix, 'args' => []];
        return $keys;
    }

    public function deletePrefix(string $prefix): void
    {
        foreach (array_keys($this->store) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->store[$key]);
            }
        }
        $this->callLog[] = ['method' => 'deletePrefix', 'key' => $prefix, 'args' => []];
    }

    public function exists(string $key): bool
    {
        return isset($this->store[$key]);
    }

    public function presignUrl(string $key, int $ttlSeconds = 300): string
    {
        $this->callLog[] = ['method' => 'presignUrl', 'key' => $key, 'args' => [$ttlSeconds]];
        return "https://fake-b2.test/{$key}?ttl={$ttlSeconds}";
    }

    public function presignPutUrl(string $key, string $contentType, int $ttlSeconds): string
    {
        $this->callLog[] = ['method' => 'presignPutUrl', 'key' => $key, 'args' => [$contentType, $ttlSeconds]];
        return "https://fake-b2.test/put/{$key}?content_type=" . urlencode($contentType) . "&ttl={$ttlSeconds}";
    }

    public function createMultipartUpload(string $key, string $contentType): string
    {
        $uploadId = 'fake-mpu-' . bin2hex(random_bytes(8));
        $this->multipartUploads[$uploadId] = [
            'key'          => $key,
            'content_type' => $contentType,
            'parts'        => [],
        ];
        $this->callLog[] = ['method' => 'createMultipartUpload', 'key' => $key, 'args' => [$contentType, $uploadId]];
        return $uploadId;
    }

    public function presignMultipartPartUrl(
        string $key,
        string $uploadId,
        int $partNumber,
        int $ttlSeconds
    ): string {
        $this->callLog[] = [
            'method' => 'presignMultipartPartUrl',
            'key'    => $key,
            'args'   => [$uploadId, $partNumber, $ttlSeconds],
        ];

        return "https://fake-b2.test/multipart/{$key}?uploadId=" . urlencode($uploadId)
            . "&partNumber={$partNumber}&ttl={$ttlSeconds}";
    }

    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        if (!isset($this->multipartUploads[$uploadId])) {
            throw new \RuntimeException("FakeB2Client::completeMultipartUpload — upload not found: {$uploadId}");
        }

        if ($parts === []) {
            throw new \RuntimeException('FakeB2Client::completeMultipartUpload — no parts provided.');
        }

        $this->store[$key] = str_repeat('x', count($parts));
        unset($this->multipartUploads[$uploadId]);

        $this->callLog[] = [
            'method' => 'completeMultipartUpload',
            'key'    => $key,
            'args'   => [$uploadId, $parts],
        ];
    }

    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        unset($this->multipartUploads[$uploadId]);
        $this->callLog[] = ['method' => 'abortMultipartUpload', 'key' => $key, 'args' => [$uploadId]];
    }

    public function download(string $key, string $localPath, ?callable $progressFn = null): void
    {
        if (!isset($this->store[$key])) {
            throw new \RuntimeException("FakeB2Client::download — key not found: {$key}");
        }
        $payload = $this->store[$key];
        $result = file_put_contents($localPath, $payload);
        if ($result === false) {
            throw new \RuntimeException("FakeB2Client::download — cannot write to: {$localPath}");
        }
        if ($progressFn !== null) {
            $size = strlen($payload);
            $progressFn($size, $size);
        }
        $this->callLog[] = ['method' => 'download', 'key' => $key, 'args' => [$localPath]];
    }

    public function stat(string $key): ?array
    {
        if (!isset($this->store[$key])) {
            return null;
        }
        $this->callLog[] = ['method' => 'stat', 'key' => $key, 'args' => []];
        return [
            'size'         => strlen($this->store[$key]),
            'content_type' => 'application/octet-stream',
        ];
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    /** Return the entire in-memory store (useful for debugging assertions). */
    public function getStore(): array
    {
        return $this->store;
    }

    /** Return the log of all method calls. */
    public function getCallLog(): array
    {
        return $this->callLog;
    }

    /** Return the number of calls to a specific method. */
    public function countCalls(string $method): int
    {
        return count(array_filter($this->callLog, static fn($e) => $e['method'] === $method));
    }

    /** Seed the store with a pre-existing object (e.g. to simulate "already uploaded"). */
    public function seed(string $key, string $content = ''): void
    {
        $this->store[$key] = $content;
    }

    /** Return true if the given key exists in the store. */
    public function hasKey(string $key): bool
    {
        return isset($this->store[$key]);
    }

    /** Return the content stored under $key, or throw if absent. */
    public function read(string $key): string
    {
        if (!isset($this->store[$key])) {
            throw new \RuntimeException("FakeB2Client::read — key '{$key}' not found in store.");
        }
        return $this->store[$key];
    }

    /** Number of objects currently in the store. */
    public function count(): int
    {
        return count($this->store);
    }

    /** Clear the store and call log (call in tearDown). */
    public function clear(): void
    {
        $this->store   = [];
        $this->multipartUploads = [];
        $this->callLog = [];
    }
}
