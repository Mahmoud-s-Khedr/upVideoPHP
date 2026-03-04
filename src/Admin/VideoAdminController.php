<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Encoding\MasterPlaylistBuilder;
use VideoSystem\Encoding\RenditionLadder;
use VideoSystem\Queue\JobQueue;
use VideoSystem\Storage\B2Client;
use VideoSystem\Upload\VideoUploadService;
use VideoSystem\Worker\CrashRecovery;

/**
 * Admin video management.
 *
 * GET  /admin/videos                                    — paginated list
 * GET  /admin/videos/upload                             — upload form
 * POST /admin/videos/upload/init                        — B2 presign init (JSON)
 * POST /admin/videos/upload/complete                    — queue after B2 PUT (JSON)
 * GET  /admin/videos/{uuid}                             — detail view
 * POST /admin/videos/{uuid}/delete                      — delete video and all related B2 objects
 * POST /admin/videos/{uuid}/metadata                    — update original_name
 * POST /admin/videos/{uuid}/audio-tracks/{index}/label  — update audio track label
 * POST /admin/videos/{uuid}/subtitles/upload            — upload an external subtitle file
 * POST /admin/videos/{uuid}/subtitles/{index}/label     — update subtitle track label
 * POST /admin/videos/{uuid}/subtitles/{index}/delete    — remove a subtitle track by track index
 * POST /admin/videos/{uuid}/subtitles/{lang}/delete     — remove a subtitle track
 */
final class VideoAdminController
{
    private const PAGE_SIZE = 25;

    public function uploadForm(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $twig = TwigFactory::create();
        $html = $twig->render('video-upload.twig', [
            'all_quality_labels' => RenditionLadder::getLabels(),
            'max_upload_bytes'   => Config::maxUploadBytes(),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function uploadInitAdmin(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = $this->parseJsonBody($request);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            return $this->jsonResponse($response, 403, ['error' => 'INVALID_CSRF', 'message' => 'Invalid CSRF token.']);
        }

        $filename = isset($body['filename']) && is_string($body['filename'])
            ? trim($body['filename']) : null;
        if ($filename === null || $filename === '') {
            return $this->jsonResponse($response, 422, ['error' => 'MISSING_FIELD', 'message' => "'filename' is required."]);
        }

        if (!isset($body['size_bytes']) || !is_int($body['size_bytes'])) {
            return $this->jsonResponse($response, 422, ['error' => 'MISSING_FIELD', 'message' => "'size_bytes' must be an integer."]);
        }
        $sizeBytes = (int) $body['size_bytes'];
        if ($sizeBytes <= 0) {
            return $this->jsonResponse($response, 400, ['error' => 'INVALID_SIZE', 'message' => "'size_bytes' must be greater than zero."]);
        }

        if (!isset($body['content_type']) || !is_string($body['content_type'])) {
            return $this->jsonResponse($response, 422, ['error' => 'MISSING_FIELD', 'message' => "'content_type' is required."]);
        }
        $contentType = strtolower(trim($body['content_type']));

        $allowedMimes = [
            'video/mp4', 'video/x-matroska', 'video/mp2t',
            'video/x-msvideo', 'video/vnd.avi', 'video/quicktime', 'video/webm',
            // .ts files misidentified by some browsers/OS (Linux Firefox, Qt tools)
            'text/vnd.trolltech.linguist',
        ];
        if (!in_array($contentType, $allowedMimes, true)) {
            return $this->jsonResponse($response, 422, ['error' => 'INVALID_MIME', 'message' => "content_type '{$contentType}' is not allowed."]);
        }

        $maxAllowed = min(Config::maxUploadBytes(), 5_368_709_120);
        if ($sizeBytes > $maxAllowed) {
            return $this->jsonResponse($response, 413, ['error' => 'FILE_TOO_LARGE', 'message' => "size_bytes {$sizeBytes} exceeds limit of {$maxAllowed} bytes."]);
        }

        $rawQualities = isset($body['target_qualities']) && is_array($body['target_qualities'])
            ? $body['target_qualities'] : [];
        $targetQualities = array_values(array_filter(
            RenditionLadder::getLabels(),
            static fn(string $q): bool => in_array($q, $rawQualities, true)
        ));

        $mimeToExt = [
            'video/mp4'                       => 'mp4',
            'video/x-matroska'                => 'mkv',
            'video/mp2t'                      => 'ts',
            'video/x-msvideo'                 => 'avi',
            'video/vnd.avi'                   => 'avi',
            'video/quicktime'                 => 'mov',
            'video/webm'                      => 'webm',
            // .ts files misidentified by some browsers/OS (Linux Firefox, Qt tools)
            'text/vnd.trolltech.linguist'     => 'ts',
        ];
        $ext      = $mimeToExt[$contentType] ?? 'mp4';
        $uuid     = $this->generateUuid();
        $b2Key    = "videos/{$uuid}/original.{$ext}";
        $origName = mb_substr(basename($filename), 0, 512);
        $qualJson = !empty($targetQualities)
            ? json_encode($targetQualities, JSON_THROW_ON_ERROR)
            : null;

        try {
            Connection::execute(
                "INSERT INTO videos (uuid, original_name, size_bytes, original_b2_key, target_qualities, status)
                 VALUES (:uuid, :name, :size, :b2key, :tq, 'pending')",
                [':uuid' => $uuid, ':name' => $origName, ':size' => $sizeBytes, ':b2key' => $b2Key, ':tq' => $qualJson]
            );
        } catch (\Throwable $e) {
            error_log('[uploadInitAdmin] DB insert failed: ' . $e->getMessage());
            return $this->jsonResponse($response, 500, ['error' => 'INTERNAL_ERROR', 'message' => 'Could not create video record.']);
        }

        $ttl = Config::b2UploadPresignTtlSeconds();
        try {
            $uploadUrl = B2Client::presignPutUrl($b2Key, $contentType, $ttl);
        } catch (\Throwable $e) {
            Connection::execute('DELETE FROM videos WHERE uuid = :uuid', [':uuid' => $uuid]);
            error_log('[uploadInitAdmin] presignPutUrl failed: ' . $e->getMessage());
            return $this->jsonResponse($response, 500, ['error' => 'INTERNAL_ERROR', 'message' => 'Could not generate upload URL.']);
        }

        return $this->jsonResponse($response, 201, [
            'video_uuid' => $uuid,
            'upload_url' => $uploadUrl,
            'b2_key'     => $b2Key,
            'expires_in' => $ttl,
        ]);
    }

    public function uploadCompleteAdmin(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = $this->parseJsonBody($request);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            return $this->jsonResponse($response, 403, ['error' => 'INVALID_CSRF', 'message' => 'Invalid CSRF token.']);
        }

        $uuid = isset($body['video_uuid']) && is_string($body['video_uuid'])
            ? trim($body['video_uuid']) : null;
        if ($uuid === null || $uuid === '') {
            return $this->jsonResponse($response, 422, ['error' => 'MISSING_FIELD', 'message' => "'video_uuid' is required."]);
        }

        $video = Connection::fetch(
            'SELECT id, status, original_b2_key FROM videos WHERE uuid = :uuid',
            [':uuid' => $uuid]
        );
        if ($video === null) {
            return $this->jsonResponse($response, 404, ['error' => 'NOT_FOUND', 'message' => "No video found with uuid '{$uuid}'."]);
        }
        if ($video['status'] !== 'pending') {
            return $this->jsonResponse($response, 409, ['error' => 'ALREADY_QUEUED', 'message' => "Video '{$uuid}' is already in status '{$video['status']}'."]);
        }

        $b2Key = (string) $video['original_b2_key'];
        try {
            $stat = B2Client::stat($b2Key);
        } catch (\Throwable $e) {
            error_log('[uploadCompleteAdmin] B2 stat failed: ' . $e->getMessage());
            return $this->jsonResponse($response, 500, ['error' => 'INTERNAL_ERROR', 'message' => 'Could not verify file in storage.']);
        }
        if ($stat === null) {
            return $this->jsonResponse($response, 422, ['error' => 'FILE_NOT_IN_B2', 'message' => 'The file has not been uploaded to storage yet.']);
        }

        $videoId = (int) $video['id'];
        $db = Connection::get();
        $db->beginTransaction();
        try {
            Connection::execute(
                "UPDATE videos SET status = 'queued', size_bytes = :size WHERE id = :id",
                [':size' => $stat['size'], ':id' => $videoId]
            );
            Connection::execute(
                "INSERT INTO encoding_jobs (video_id, status) VALUES (:vid, 'queued')",
                [':vid' => $videoId]
            );
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log('[uploadCompleteAdmin] DB transaction failed: ' . $e->getMessage());
            return $this->jsonResponse($response, 500, ['error' => 'INTERNAL_ERROR', 'message' => 'Could not queue encoding job.']);
        }

        return $this->jsonResponse($response, 202, [
            'video_uuid' => $uuid,
            'video_id'   => $videoId,
            'status'     => 'queued',
            'redirect'   => "/admin/videos/{$uuid}",
        ]);
    }

    private function jsonResponse(ResponseInterface $response, int $status, array $payload): ResponseInterface
    {
        $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $status  = $params['status'] ?? '';
        $search  = trim((string) ($params['search'] ?? ''));
        $offset  = ($page - 1) * self::PAGE_SIZE;

        /** @var array<string,string> $sortMap */
        $sortMap = [
            'name'     => 'v.original_name',
            'status'   => 'v.status',
            'size'     => 'v.size_bytes',
            'duration' => 'v.duration_sec',
            'created'  => 'v.created_at',
        ];
        $sort    = isset($params['sort'], $sortMap[$params['sort']]) ? $params['sort'] : 'created';
        $dirRaw  = isset($params['dir']) && strtolower((string) $params['dir']) === 'asc' ? 'ASC' : 'DESC';
        $orderBy = $sortMap[$sort] . ' ' . $dirRaw;

        $conditions = [];
        $bind       = [];
        if ($status !== '' && in_array($status, ['pending','queued','processing','uploading','ready','error'], true)) {
            $conditions[] = 'v.status = :status';
            $bind['status'] = $status;
        }
        if ($search !== '') {
            $conditions[] = 'v.original_name LIKE :search';
            $bind['search'] = '%' . $search . '%';
        }
        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $total = (int) (Connection::fetch(
            "SELECT COUNT(*) AS cnt FROM videos v {$where}",
            $bind
        )['cnt'] ?? 0);

        $videos = Connection::fetchAll(
            "SELECT v.id, v.uuid, v.original_name, v.status, v.duration_sec,
                    v.size_bytes, v.source_height, v.target_qualities,
                    v.created_at, v.updated_at,
                    ej.progress_pct, ej.current_rendition, ej.current_stage, ej.status AS job_status
             FROM videos v
             LEFT JOIN encoding_jobs ej ON ej.video_id = v.id
             {$where}
             ORDER BY {$orderBy}
             LIMIT :limit OFFSET :offset",
            array_merge($bind, ['limit' => self::PAGE_SIZE, 'offset' => $offset])
        );

        // Per-row warning: all saved target qualities are above the source height.
        $qualityHeights = [];
        foreach (RenditionLadder::getLadder() as $ql => $qp) {
            $qualityHeights[$ql] = $qp['height'];
        }
        foreach ($videos as &$v) {
            $v['height_warning'] = false;
            $sh = isset($v['source_height']) ? (int) $v['source_height'] : null;
            if ($sh !== null && !empty($v['target_qualities'])) {
                $tq = json_decode((string) $v['target_qualities'], true);
                if (is_array($tq) && count($tq) > 0) {
                    $allAbove = true;
                    foreach ($tq as $ql) {
                        if (isset($qualityHeights[$ql]) && $sh >= $qualityHeights[$ql]) {
                            $allAbove = false;
                            break;
                        }
                    }
                    $v['height_warning'] = $allAbove;
                }
            }
        }
        unset($v);

        $totalPages = (int) ceil($total / self::PAGE_SIZE);

        $twig = TwigFactory::create();
        $html = $twig->render('videos.twig', [
            'videos'        => $videos,
            'page'          => $page,
            'total_pages'   => $totalPages,
            'total'         => $total,
            'status_filter' => $status,
            'search'        => $search,
            'sort'          => $sort,
            'dir'           => strtolower($dirRaw),
            'base_url'      => \VideoSystem\Config\Config::appBaseUrl(),
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function progress(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid  = $args['uuid'] ?? '';
        $video = Connection::fetch('SELECT id, uuid, status FROM videos WHERE uuid = :uuid', [':uuid' => $uuid]);

        if ($video === null) {
            return $this->jsonResponse($response, 404, [
                'error' => 'NOT_FOUND',
                'message' => 'Video not found.',
            ]);
        }

        $job = JobQueue::findByVideoId((int) $video['id']);

        return $this->jsonResponse($response, 200, [
            'video_uuid'        => $video['uuid'],
            'status'            => $video['status'],
            'progress_pct'      => $job ? (int) $job['progress_pct'] : 0,
            'current_rendition' => $job['current_rendition'] ?? null,
            'current_stage'     => $job['current_stage'] ?? JobQueue::fallbackStageForVideoStatus($video['status']),
        ]);
    }

    public function detail(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';

        $video = Connection::fetch(
            'SELECT * FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            $response->getBody()->write('<h1>404 — Video not found</h1>');
            return $response->withStatus(404)->withHeader('Content-Type', 'text/html; charset=UTF-8');
        }

        $job = Connection::fetch(
            'SELECT * FROM encoding_jobs WHERE video_id = :id ORDER BY id DESC LIMIT 1',
            ['id' => $video['id']]
        );

        $renditions = Connection::fetchAll(
            'SELECT * FROM renditions WHERE video_id = :id ORDER BY height DESC',
            ['id' => $video['id']]
        );

        $audioTracks = Connection::fetchAll(
            'SELECT * FROM audio_tracks WHERE video_id = :id ORDER BY track_index ASC',
            ['id' => $video['id']]
        );

        $subtitles = Connection::fetchAll(
            'SELECT * FROM subtitles WHERE video_id = :id ORDER BY track_index ASC',
            ['id' => $video['id']]
        );

        // Quality selection helper data for the template
        $targetQualities = null;
        if (!empty($video['target_qualities'])) {
            $decoded = json_decode((string) $video['target_qualities'], true);
            $targetQualities = is_array($decoded) ? $decoded : null;
        }

        // Compute human-readable processing time for completed/failed jobs.
        if ($job !== null && $job['claimed_at'] !== null
            && in_array($job['status'], ['done', 'failed'], true)) {
            $start   = new \DateTime($job['claimed_at']);
            $end     = new \DateTime($job['updated_at']);
            $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
            $h = (int) floor($seconds / 3600);
            $m = (int) floor(($seconds % 3600) / 60);
            $s = $seconds % 60;
            $job['processing_time'] = $h > 0
                ? sprintf('%dh %dm %ds', $h, $m, $s)
                : ($m > 0 ? sprintf('%dm %ds', $m, $s) : sprintf('%ds', $s));
        } elseif ($job !== null) {
            $job['processing_time'] = null;
        }

        // Build label → height map from the live ladder so Twig never needs
        // a hardcoded dict (works correctly with custom rendition labels too).
        $qualityHeights = [];
        foreach (RenditionLadder::getLadder() as $label => $params) {
            $qualityHeights[$label] = $params['height'];
        }

        $twig = TwigFactory::create();
        $html = $twig->render('video-detail.twig', [
            'video'              => $video,
            'job'                => $job,
            'renditions'         => $renditions,
            'audio_tracks'       => $audioTracks,
            'subtitles'          => $subtitles,
            'target_qualities'   => $targetQualities,
            'all_quality_labels' => RenditionLadder::getLabels(),
            'quality_heights'    => $qualityHeights,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function updateMetadata(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        $originalName = $this->validateRequiredText((string) ($body['original_name'] ?? ''));
        if ($originalName === null) {
            TwigFactory::flash('error', 'Original name is required and must be 512 characters or fewer.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        Connection::execute(
            'UPDATE videos SET original_name = :name WHERE id = :id',
            [
                ':name' => $originalName,
                ':id'   => (int) $video['id'],
            ]
        );

        TwigFactory::flash('success', 'Video metadata updated.');
        return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
    }

    public function delete(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid, status FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        $videoId = (int) $video['id'];

        // Cancel in-flight job if processing
        $job = Connection::fetch(
            'SELECT id FROM encoding_jobs WHERE video_id = :id AND status IN (\'queued\',\'claimed\')',
            ['id' => $videoId]
        );
        if ($job !== null) {
            JobQueue::requestCancel((int) $job['id']);
        }

        // Clean up local work dirs
        $workDir = $_ENV['WORK_DIR'] ?? '/var/video-work';
        $incomingDir = "{$workDir}/incoming/{$uuid}";
        if (is_dir($incomingDir)) {
            CrashRecovery::deleteDirectory($incomingDir);
        }

        // Delete all B2 objects
        try {
            B2Client::deletePrefix("videos/{$uuid}/");
        } catch (\Throwable $e) {
            // Log but continue — DB cleanup still proceeds
            error_log("[admin] B2 deletePrefix failed for {$uuid}: " . $e->getMessage());
        }

        // Hard delete (FK cascade cleans child tables)
        Connection::execute('DELETE FROM videos WHERE id = :id', ['id' => $videoId]);

        TwigFactory::flash('success', "Video {$uuid} deleted.");
        return $response->withStatus(302)->withHeader('Location', '/admin/videos');
    }

    // -------------------------------------------------------------------------
    // Quality selection
    // -------------------------------------------------------------------------

    public function setQualities(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid, status FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        if (!in_array($video['status'], ['pending', 'queued'], true)) {
            TwigFactory::flash('error', 'Target qualities can only be changed before encoding starts.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $submitted = isset($body['qualities']) && is_array($body['qualities'])
            ? $body['qualities']
            : [];

        // Filter to known labels only, preserve ladder order
        $ordered = array_values(
            array_filter(RenditionLadder::getLabels(), fn($q) => in_array($q, $submitted, true))
        );

        if (empty($ordered)) {
            TwigFactory::flash('error', 'Please select at least one quality level.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $videoId    = (int) $video['id'];
        $wasPending = $video['status'] === 'pending';

        $db = Connection::get();
        $db->beginTransaction();

        try {
            Connection::execute(
                'UPDATE videos SET target_qualities = :tq WHERE id = :id',
                [
                    ':tq' => json_encode($ordered, JSON_THROW_ON_ERROR),
                    ':id' => $videoId,
                ]
            );

            if ($wasPending) {
                // First time qualities are set — create the encoding job and queue the video
                Connection::execute(
                    "UPDATE videos SET status = 'queued' WHERE id = :id",
                    [':id' => $videoId]
                );
                Connection::execute(
                    'INSERT INTO encoding_jobs (video_id, status) VALUES (:vid, :status)',
                    [':vid' => $videoId, ':status' => 'queued']
                );
            }

            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            TwigFactory::flash('error', 'Database error: ' . $e->getMessage());
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $msg = $wasPending
            ? 'Target qualities saved. Video queued for encoding.'
            : 'Target qualities updated.';
        TwigFactory::flash('success', $msg);
        return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
    }

    // -------------------------------------------------------------------------
    // Audio track management
    // -------------------------------------------------------------------------

    public function updateAudioLabel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid, status FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        $index = isset($args['index']) ? (int) $args['index'] : -1;
        $track = Connection::fetch(
            'SELECT track_index FROM audio_tracks WHERE video_id = :vid AND track_index = :idx',
            [':vid' => (int) $video['id'], ':idx' => $index]
        );

        if ($track === null) {
            TwigFactory::flash('error', 'Audio track not found.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $label = $this->validateRequiredText((string) ($body['label'] ?? ''));
        if ($label === null) {
            TwigFactory::flash('error', 'Audio track label is required and must be 512 characters or fewer.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        Connection::execute(
            'UPDATE audio_tracks SET label = :label WHERE video_id = :vid AND track_index = :idx',
            [
                ':label' => $label,
                ':vid'   => (int) $video['id'],
                ':idx'   => $index,
            ]
        );

        if ($video['status'] === 'ready') {
            $this->rebuildMasterPlaylist((int) $video['id'], $uuid);
        }

        TwigFactory::flash('success', "Audio track {$index} updated.");
        return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
    }

    // -------------------------------------------------------------------------
    // Subtitle management
    // -------------------------------------------------------------------------

    public function updateSubtitleLabel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid, status FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        $index = isset($args['index']) ? (int) $args['index'] : -1;
        $subtitle = Connection::fetch(
            'SELECT track_index FROM subtitles WHERE video_id = :vid AND track_index = :idx',
            [':vid' => (int) $video['id'], ':idx' => $index]
        );

        if ($subtitle === null) {
            TwigFactory::flash('error', 'Subtitle track not found.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $label = $this->validateRequiredText((string) ($body['label'] ?? ''));
        if ($label === null) {
            TwigFactory::flash('error', 'Subtitle label is required and must be 512 characters or fewer.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        Connection::execute(
            'UPDATE subtitles SET label = :label WHERE video_id = :vid AND track_index = :idx',
            [
                ':label' => $label,
                ':vid'   => (int) $video['id'],
                ':idx'   => $index,
            ]
        );

        if ($video['status'] === 'ready') {
            $this->rebuildMasterPlaylist((int) $video['id'], $uuid);
        }

        TwigFactory::flash('success', "Subtitle track {$index} updated.");
        return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
    }

    public function uploadSubtitle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid, status FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        // --- Validate uploaded file ---
        $files    = $request->getUploadedFiles();
        $uploaded = $files['subtitle_file'] ?? null;

        if ($uploaded === null || $uploaded->getError() !== UPLOAD_ERR_OK) {
            TwigFactory::flash('error', 'No file uploaded or upload error.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        if ($uploaded->getSize() > 5 * 1024 * 1024) {
            TwigFactory::flash('error', 'Subtitle file exceeds the 5 MB limit.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        // --- Validate form fields ---
        $lang = strtolower(trim((string) ($body['language_code'] ?? '')));
        if (!preg_match('/^[a-z0-9]{2,8}$/', $lang)) {
            TwigFactory::flash('error', 'Invalid language code. Use 2–8 lowercase alphanumeric characters (e.g. eng, spa).');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $labelInput = trim((string) ($body['label'] ?? ''));
        if (mb_strlen($labelInput) > 512) {
            TwigFactory::flash('error', 'Subtitle label must be 512 characters or fewer.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }
        $label      = $labelInput !== '' ? $labelInput : (self::LANGUAGE_LABELS[$lang] ?? ucfirst($lang));
        $isForced   = !empty($body['is_forced']);

        // --- Write uploaded file to a temp path ---
        $tmpDir   = sys_get_temp_dir() . '/sub_upload_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0750, true);
        $tmpInput = $tmpDir . '/input.' . pathinfo((string) $uploaded->getClientFilename(), PATHINFO_EXTENSION);
        $tmpVtt   = $tmpDir . '/output.vtt';

        try {
            $uploaded->moveTo($tmpInput);

            // --- Convert to WebVTT via ffmpeg ---
            $cmd      = sprintf(
                '%s -y -i %s -c:s webvtt %s 2>&1',
                escapeshellarg(Config::ffmpegBin()),
                escapeshellarg($tmpInput),
                escapeshellarg($tmpVtt)
            );
            exec($cmd, $cmdOutput, $exitCode);

            if ($exitCode !== 0 || !file_exists($tmpVtt) || filesize($tmpVtt) === 0) {
                $detail = implode("\n", array_slice($cmdOutput, -5));
                TwigFactory::flash('error', "Subtitle conversion failed. Make sure the file is a valid subtitle format. (ffmpeg: {$detail})");
                return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
            }

            // --- Upload to B2 ---
            $b2Key = "videos/{$uuid}/subs/{$lang}.vtt";
            B2Client::put($b2Key, $tmpVtt, 'text/vtt');

            // --- Upsert into subtitles table ---
            $videoId  = (int) $video['id'];
            $existing = Connection::fetch(
                'SELECT id FROM subtitles WHERE video_id = :vid AND language_code = :lang',
                [':vid' => $videoId, ':lang' => $lang]
            );

            Connection::execute(
                'INSERT INTO subtitles (video_id, language_code, label, is_forced, source, b2_vtt_key)
                 VALUES (:vid, :lang, :label, :forced, \'uploaded\', :key)
                 ON DUPLICATE KEY UPDATE
                     label      = VALUES(label),
                     is_forced  = VALUES(is_forced),
                     source     = \'uploaded\',
                     b2_vtt_key = VALUES(b2_vtt_key)',
                [
                    ':vid'    => $videoId,
                    ':lang'   => $lang,
                    ':label'  => $label,
                    ':forced' => $isForced ? 1 : 0,
                    ':key'    => $b2Key,
                ]
            );

            // --- Rebuild master.m3u8 if video is fully encoded ---
            if ($video['status'] === 'ready') {
                $this->rebuildMasterPlaylist($videoId, $uuid);
            }

            $msg = $existing !== null
                ? "Subtitle '{$lang}' uploaded and existing track overwritten."
                : "Subtitle '{$lang}' uploaded successfully.";

            TwigFactory::flash('success', $msg);

        } finally {
            @unlink($tmpInput);
            @unlink($tmpVtt);
            @rmdir($tmpDir);
        }

        return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
    }

    public function deleteSubtitle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $lang = strtolower((string) ($args['lang'] ?? ''));
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid, status FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        $videoId  = (int) $video['id'];
        $subtitle = Connection::fetch(
            'SELECT id, b2_vtt_key FROM subtitles WHERE video_id = :vid AND language_code = :lang',
            [':vid' => $videoId, ':lang' => $lang]
        );

        if ($subtitle === null) {
            TwigFactory::flash('error', "Subtitle track '{$lang}' not found.");
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        // Delete from B2
        try {
            B2Client::delete((string) $subtitle['b2_vtt_key']);
        } catch (\Throwable $e) {
            error_log("[admin] B2 delete subtitle failed ({$subtitle['b2_vtt_key']}): " . $e->getMessage());
        }

        // Remove from DB
        Connection::execute(
            'DELETE FROM subtitles WHERE video_id = :vid AND language_code = :lang',
            [':vid' => $videoId, ':lang' => $lang]
        );

        // Rebuild master.m3u8 if video is fully encoded
        if ($video['status'] === 'ready') {
            $this->rebuildMasterPlaylist($videoId, $uuid);
        }

        TwigFactory::flash('success', "Subtitle track '{$lang}' deleted.");
        return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
    }

    public function deleteSubtitleByIndex(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $uuid = $args['uuid'] ?? '';
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        $video = Connection::fetch(
            'SELECT id, uuid, status FROM videos WHERE uuid = :uuid',
            ['uuid' => $uuid]
        );

        if ($video === null) {
            TwigFactory::flash('error', 'Video not found.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos');
        }

        $index = isset($args['index']) ? (int) $args['index'] : -1;
        $subtitle = Connection::fetch(
            'SELECT track_index, language_code, b2_vtt_key
             FROM subtitles
             WHERE video_id = :vid AND track_index = :idx',
            [':vid' => (int) $video['id'], ':idx' => $index]
        );

        if ($subtitle === null) {
            TwigFactory::flash('error', "Subtitle track '{$index}' not found.");
            return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
        }

        try {
            B2Client::delete((string) $subtitle['b2_vtt_key']);
        } catch (\Throwable $e) {
            error_log("[admin] B2 delete subtitle failed ({$subtitle['b2_vtt_key']}): " . $e->getMessage());
        }

        Connection::execute(
            'DELETE FROM subtitles WHERE video_id = :vid AND track_index = :idx',
            [':vid' => (int) $video['id'], ':idx' => $index]
        );

        if ($video['status'] === 'ready') {
            $this->rebuildMasterPlaylist((int) $video['id'], $uuid);
        }

        TwigFactory::flash('success', "Subtitle track '{$index}' deleted.");
        return $response->withStatus(302)->withHeader('Location', "/admin/videos/{$uuid}");
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Parse a JSON request body, falling back to form-parsed body.
     * @return array<string, mixed>
     */
    private function parseJsonBody(ServerRequestInterface $request): array
    {
        $parsed = $request->getParsedBody();
        if (is_array($parsed) && !empty($parsed)) {
            return $parsed;
        }
        $raw = (string) $request->getBody();
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }

    private function generateUuid(): string
    {
        $data    = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Rebuild and re-upload master.m3u8 after a subtitle change.
     * No-op when the video has no renditions (never encoded).
     */
    private function rebuildMasterPlaylist(int $videoId, string $uuid): void
    {
        $renditions = Connection::fetchAll(
            'SELECT label FROM renditions WHERE video_id = :id ORDER BY height DESC',
            ['id' => $videoId]
        );

        if (empty($renditions)) {
            return;
        }

        $renditionLabels = array_column($renditions, 'label');

        $tmpDir = sys_get_temp_dir() . '/master_rebuild_' . bin2hex(random_bytes(8));
        mkdir($tmpDir, 0750, true);

        try {
            (new MasterPlaylistBuilder($videoId, $uuid))->build($tmpDir, $renditionLabels);
        } finally {
            @unlink($tmpDir . '/master.m3u8');
            @rmdir($tmpDir);
        }
    }

    private function validateRequiredText(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '' || mb_strlen($trimmed) > 512) {
            return null;
        }

        return $trimmed;
    }

    /** Language code → display label (mirrors SubtitleExtractor::LANGUAGE_LABELS). */
    private const LANGUAGE_LABELS = [
        'eng' => 'English',
        'spa' => 'Spanish',
        'fra' => 'French',
        'deu' => 'German',
        'ita' => 'Italian',
        'jpn' => 'Japanese',
        'por' => 'Portuguese',
        'rus' => 'Russian',
        'chi' => 'Chinese',
        'ara' => 'Arabic',
        'kor' => 'Korean',
        'und' => 'Unknown',
    ];
}
