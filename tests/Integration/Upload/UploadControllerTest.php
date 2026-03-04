<?php

declare(strict_types=1);

namespace VideoSystem\Tests\Integration\Upload;

use VideoSystem\Database\Connection;
use VideoSystem\Tests\Integration\HttpIntegrationTestCase;

/**
 * Integration tests for direct-upload endpoints:
 *   POST /api/upload/init
 *   POST /api/upload/{uuid}/parts
 *   POST /api/upload/{uuid}/complete-multipart
 *   POST /api/upload/complete
 */
final class UploadControllerTest extends HttpIntegrationTestCase
{
    private const UPLOAD_KEY = 'test-upload-key-abcdef';
    private const READ_ONLY_KEY = 'test-read-only-key-xyz';

    private ?string $originalMaxUploadBytes = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$dbAvailable) {
            return;
        }

        $this->truncateTables('encoding_jobs', 'videos', 'api_keys');
        $this->insertApiKey('uploader', self::UPLOAD_KEY, canUpload: true, canStream: true);
        $this->insertApiKey('reader', self::READ_ONLY_KEY, canUpload: false, canStream: true);

        $this->originalMaxUploadBytes = $_ENV['MAX_UPLOAD_BYTES'] ?? null;
        $_ENV['MAX_UPLOAD_BYTES'] = (string) (20 * 1024 * 1024 * 1024); // 20 GB for multipart tests
    }

    protected function tearDown(): void
    {
        if ($this->originalMaxUploadBytes === null) {
            unset($_ENV['MAX_UPLOAD_BYTES']);
        } else {
            $_ENV['MAX_UPLOAD_BYTES'] = $this->originalMaxUploadBytes;
        }

        if (self::$dbAvailable) {
            $this->truncateTables('encoding_jobs', 'videos', 'api_keys');
        }
        parent::tearDown();
    }

    public function testInitRequiresUploadPermission(): void
    {
        $response = $this->apiPost(
            '/api/upload/init',
            self::READ_ONLY_KEY,
            json_encode([
                'filename' => 'video.mp4',
                'size_bytes' => 1024,
                'content_type' => 'video/mp4',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(403, $response);
        $body = $this->json($response);
        self::assertSame('FORBIDDEN', $body['error']);
    }

    public function testInitUsesSingleUploadModeForFourGigabytes(): void
    {
        $response = $this->apiPost(
            '/api/upload/init',
            self::UPLOAD_KEY,
            json_encode([
                'filename' => 'source.mp4',
                'size_bytes' => 4 * 1024 * 1024 * 1024,
                'content_type' => 'video/mp4',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(201, $response);
        $data = $this->json($response);

        self::assertSame('single', $data['upload_mode']);
        self::assertNotEmpty($data['upload_url']);
        self::assertNull($data['part_size_bytes']);
        self::assertNull($data['total_parts']);

        $row = Connection::fetch(
            'SELECT original_upload_mode, multipart_upload_id FROM videos WHERE uuid = :uuid',
            [':uuid' => $data['video_uuid']]
        );
        self::assertSame('single', $row['original_upload_mode']);
        self::assertNull($row['multipart_upload_id']);
    }

    public function testInitUsesMultipartUploadModeForTwelveGigabytes(): void
    {
        $sizeBytes = 12 * 1024 * 1024 * 1024;

        $response = $this->apiPost(
            '/api/upload/init',
            self::UPLOAD_KEY,
            json_encode([
                'filename' => 'movie.mkv',
                'size_bytes' => $sizeBytes,
                'content_type' => 'video/x-matroska',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(201, $response);
        $data = $this->json($response);

        self::assertSame('multipart', $data['upload_mode']);
        self::assertNull($data['upload_url']);
        self::assertSame(67108864, (int) $data['part_size_bytes']);
        self::assertSame((int) ceil($sizeBytes / 67108864), (int) $data['total_parts']);

        $row = Connection::fetch(
            'SELECT original_upload_mode, multipart_upload_id, multipart_parts_json FROM videos WHERE uuid = :uuid',
            [':uuid' => $data['video_uuid']]
        );
        self::assertSame('multipart', $row['original_upload_mode']);
        self::assertNotEmpty($row['multipart_upload_id']);
        self::assertSame('[]', $row['multipart_parts_json']);
    }

    public function testMultipartPartSigningAndRecordingWorks(): void
    {
        $init = $this->createMultipartUploadInit();
        $uuid = $init['video_uuid'];

        $signRes = $this->apiPost(
            '/api/upload/' . $uuid . '/parts',
            self::UPLOAD_KEY,
            json_encode(['part_number' => 1], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(200, $signRes);
        $signed = $this->json($signRes);
        self::assertNotEmpty($signed['upload_url']);
        self::assertNull($signed['etag']);

        $recordRes = $this->apiPost(
            '/api/upload/' . $uuid . '/parts',
            self::UPLOAD_KEY,
            json_encode(['part_number' => 1, 'etag' => 'etag-1'], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(200, $recordRes);
        $recorded = $this->json($recordRes);
        self::assertTrue($recorded['recorded']);
        self::assertSame(1, (int) $recorded['parts_stored']);

        $reSignRes = $this->apiPost(
            '/api/upload/' . $uuid . '/parts',
            self::UPLOAD_KEY,
            json_encode(['part_number' => 1], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(200, $reSignRes);
        $reSigned = $this->json($reSignRes);
        self::assertSame('etag-1', trim((string) $reSigned['etag'], '"'));
    }

    public function testMultipartCompleteFailsWhenPartsMissingOrInvalid(): void
    {
        $init = $this->createMultipartUploadInit();
        $uuid = $init['video_uuid'];

        $emptyRes = $this->apiPost(
            '/api/upload/' . $uuid . '/complete-multipart',
            self::UPLOAD_KEY,
            json_encode(['parts' => []], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(422, $emptyRes);
        $emptyBody = $this->json($emptyRes);
        self::assertSame('MISSING_PARTS', $emptyBody['error']);

        $invalidRes = $this->apiPost(
            '/api/upload/' . $uuid . '/complete-multipart',
            self::UPLOAD_KEY,
            json_encode(['parts' => [['part_number' => 1]]], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(422, $invalidRes);
        $invalidBody = $this->json($invalidRes);
        self::assertSame('MISSING_PARTS', $invalidBody['error']);
    }

    public function testMultipartUploadCanFinalizeThenQueue(): void
    {
        $init = $this->createMultipartUploadInit();
        $uuid = $init['video_uuid'];

        $this->apiPost(
            '/api/upload/' . $uuid . '/parts',
            self::UPLOAD_KEY,
            json_encode(['part_number' => 1, 'etag' => 'etag-1'], JSON_THROW_ON_ERROR)
        );

        $finalizeRes = $this->apiPost(
            '/api/upload/' . $uuid . '/complete-multipart',
            self::UPLOAD_KEY,
            json_encode(['parts' => [['part_number' => 2, 'etag' => 'etag-2']]], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(200, $finalizeRes);
        $finalize = $this->json($finalizeRes);
        self::assertSame('uploaded', $finalize['status']);
        self::assertSame(2, (int) $finalize['parts_total']);

        $video = Connection::fetch(
            'SELECT id, original_b2_key, multipart_upload_id FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );
        self::assertNull($video['multipart_upload_id']);
        self::assertTrue($this->b2->hasKey((string) $video['original_b2_key']));

        $completeRes = $this->apiPost(
            '/api/upload/complete',
            self::UPLOAD_KEY,
            json_encode(['video_uuid' => $uuid], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(202, $completeRes);
        $complete = $this->json($completeRes);
        self::assertSame('queued', $complete['status']);

        $job = Connection::fetch(
            'SELECT status FROM encoding_jobs WHERE video_id = :vid ORDER BY id DESC LIMIT 1',
            [':vid' => $video['id']]
        );
        self::assertNotNull($job);
        self::assertSame('queued', $job['status']);
    }

    public function testCompleteFailsIfObjectMissingFromStorage(): void
    {
        $init = $this->apiPost(
            '/api/upload/init',
            self::UPLOAD_KEY,
            json_encode([
                'filename' => 'single.mp4',
                'size_bytes' => 1024 * 1024,
                'content_type' => 'video/mp4',
            ], JSON_THROW_ON_ERROR)
        );
        $this->assertStatus(201, $init);
        $payload = $this->json($init);

        $completeRes = $this->apiPost(
            '/api/upload/complete',
            self::UPLOAD_KEY,
            json_encode(['video_uuid' => $payload['video_uuid']], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(422, $completeRes);
        $body = $this->json($completeRes);
        self::assertSame('FILE_NOT_IN_B2', $body['error']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createMultipartUploadInit(): array
    {
        $response = $this->apiPost(
            '/api/upload/init',
            self::UPLOAD_KEY,
            json_encode([
                'filename' => 'large.mp4',
                'size_bytes' => 6 * 1024 * 1024 * 1024,
                'content_type' => 'video/mp4',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertStatus(201, $response);
        $payload = $this->json($response);
        self::assertSame('multipart', $payload['upload_mode']);

        return $payload;
    }
}
