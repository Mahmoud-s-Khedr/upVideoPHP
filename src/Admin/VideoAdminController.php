<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Config\Config;
use VideoSystem\Database\Connection;
use VideoSystem\Encoding\MasterPlaylistBuilder;
use VideoSystem\Queue\JobQueue;
use VideoSystem\Storage\B2Client;
use VideoSystem\Upload\UploadController;
use VideoSystem\Upload\ValidationException;
use VideoSystem\Upload\VideoUploadService;
use VideoSystem\Worker\CrashRecovery;

/**
 * Admin video management.
 *
 * GET  /admin/videos                                    — paginated list
 * GET  /admin/videos/upload                             — upload form
 * POST /admin/videos/upload                             — upload action
 * GET  /admin/videos/{uuid}                             — detail view
 * POST /admin/videos/{uuid}/delete                      — delete video and all related B2 objects
 * POST /admin/videos/{uuid}/subtitles/upload            — upload an external subtitle file
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
            'all_quality_labels' => UploadController::QUALITY_LABELS,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function uploadSubmit(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = (array) ($request->getParsedBody() ?? []);
        $csrf = (string) ($body['_csrf'] ?? '');

        if (!TwigFactory::validateCsrf($csrf)) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos/upload');
        }

        $files = $request->getUploadedFiles();
        $uploaded = $files['file'] ?? null;

        if ($uploaded === null) {
            TwigFactory::flash('error', 'Please choose a video file to upload.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos/upload');
        }

        $service = new VideoUploadService();
        $targetQualities = isset($body['target_qualities']) && is_array($body['target_qualities'])
            ? $body['target_qualities']
            : [];

        try {
            $result = $service->uploadSlimFile($uploaded, $targetQualities);
        } catch (ValidationException $e) {
            TwigFactory::flash('error', $e->getMessage());
            return $response->withStatus(302)->withHeader('Location', '/admin/videos/upload');
        } catch (\RuntimeException $e) {
            error_log('[admin upload] ' . $e->getMessage());
            TwigFactory::flash('error', 'Upload failed due to a server error. Please try again.');
            return $response->withStatus(302)->withHeader('Location', '/admin/videos/upload');
        }

        TwigFactory::flash(
            'success',
            sprintf('Video uploaded successfully. UUID: %s. Status: %s.', $result['video_uuid'], $result['status'])
        );
        return $response
            ->withStatus(302)
            ->withHeader('Location', '/admin/videos/' . $result['video_uuid']);
    }

    public function list(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $params  = $request->getQueryParams();
        $page    = max(1, (int) ($params['page'] ?? 1));
        $status  = $params['status'] ?? '';
        $offset  = ($page - 1) * self::PAGE_SIZE;

        $where  = '';
        $bind   = [];
        if ($status !== '' && in_array($status, ['pending','queued','processing','uploading','ready','error'], true)) {
            $where = 'WHERE v.status = :status';
            $bind  = ['status' => $status];
        }

        $total = (int) (Connection::fetch(
            "SELECT COUNT(*) AS cnt FROM videos v {$where}",
            $bind
        )['cnt'] ?? 0);

        $videos = Connection::fetchAll(
            "SELECT v.id, v.uuid, v.original_name, v.status, v.duration_sec,
                    v.size_bytes, v.created_at, v.updated_at,
                    ej.progress_pct, ej.current_rendition, ej.status AS job_status
             FROM videos v
             LEFT JOIN encoding_jobs ej ON ej.video_id = v.id
             {$where}
             ORDER BY v.created_at DESC
             LIMIT :limit OFFSET :offset",
            array_merge($bind, ['limit' => self::PAGE_SIZE, 'offset' => $offset])
        );

        $totalPages = (int) ceil($total / self::PAGE_SIZE);

        $twig = TwigFactory::create();
        $html = $twig->render('videos.twig', [
            'videos'       => $videos,
            'page'         => $page,
            'total_pages'  => $totalPages,
            'total'        => $total,
            'status_filter'=> $status,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
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

        $subtitles = Connection::fetchAll(
            'SELECT * FROM subtitles WHERE video_id = :id',
            ['id' => $video['id']]
        );

        // Quality selection helper data for the template
        $targetQualities = null;
        if (!empty($video['target_qualities'])) {
            $decoded = json_decode((string) $video['target_qualities'], true);
            $targetQualities = is_array($decoded) ? $decoded : null;
        }

        $twig = TwigFactory::create();
        $html = $twig->render('video-detail.twig', [
            'video'              => $video,
            'job'                => $job,
            'renditions'         => $renditions,
            'subtitles'          => $subtitles,
            'target_qualities'   => $targetQualities,
            'all_quality_labels' => UploadController::QUALITY_LABELS,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
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
            array_filter(UploadController::QUALITY_LABELS, fn($q) => in_array($q, $submitted, true))
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
    // Subtitle management
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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
