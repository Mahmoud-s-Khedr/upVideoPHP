<?php

declare(strict_types=1);

/**
 * Admin route definitions.
 *
 * Included by public/index.php (and tests/Support/SlimAppFactory.php).
 * Expects $app to be an instance of Slim\App.
 *
 * All /admin/* routes are wrapped with SessionMiddleware which:
 *   - starts the PHP session
 *   - redirects unauthenticated requests to /admin/login
 */

use Slim\Routing\RouteCollectorProxy;
use VideoSystem\Admin\SessionMiddleware;
use VideoSystem\Admin\AuthController;
use VideoSystem\Admin\DashboardController;
use VideoSystem\Admin\VideoAdminController;
use VideoSystem\Admin\JobAdminController;
use VideoSystem\Admin\ApiKeyAdminController;
use VideoSystem\Admin\AccessLogController;
use VideoSystem\Admin\HealthAdminController;
use VideoSystem\Admin\UserAdminController;
use VideoSystem\Admin\PlaylistAdminController;
use VideoSystem\Admin\EmbedSettingsController;

$app->group('/admin', function (RouteCollectorProxy $group) {

    // --- Auth (no session guard inside; SessionMiddleware skips /admin/login) ---
    $group->get('/login',  [AuthController::class, 'loginForm']);
    $group->post('/login', [AuthController::class, 'loginSubmit']);
    $group->post('/logout',[AuthController::class, 'logout']);

    // --- Dashboard ---
    $group->get('',        [DashboardController::class, 'index']);
    $group->get('/',       [DashboardController::class, 'index']);

    // --- Videos ---
    $group->get('/videos',                                                [VideoAdminController::class, 'list']);
    $group->get('/videos/upload',                                         [VideoAdminController::class, 'uploadForm']);
    $group->post('/videos/upload',                                        [VideoAdminController::class, 'uploadSubmit']);
    $group->get('/videos/{uuid}/embed',                                   [EmbedSettingsController::class, 'videoForm']);
    $group->post('/videos/{uuid}/embed',                                  [EmbedSettingsController::class, 'videoSave']);
    $group->post('/videos/{uuid}/embed/delete-override',                  [EmbedSettingsController::class, 'videoDelete']);
    $group->get('/videos/{uuid}/progress.json',                           [VideoAdminController::class, 'progress']);
    $group->get('/videos/{uuid}',                                         [VideoAdminController::class, 'detail']);
    $group->post('/videos/{uuid}/delete',                                 [VideoAdminController::class, 'delete']);
    $group->post('/videos/{uuid}/qualities',                              [VideoAdminController::class, 'setQualities']);
    $group->post('/videos/{uuid}/subtitles/upload',                       [VideoAdminController::class, 'uploadSubtitle']);
    $group->post('/videos/{uuid}/subtitles/{lang:[a-z0-9]+}/delete',      [VideoAdminController::class, 'deleteSubtitle']);

    // --- Encoding jobs ---
    $group->get('/jobs',                           [JobAdminController::class, 'list']);
    $group->post('/jobs/{id:[0-9]+}/cancel',       [JobAdminController::class, 'cancel']);

    // --- API keys ---
    $group->get('/api-keys',                       [ApiKeyAdminController::class, 'list']);
    $group->post('/api-keys/create',               [ApiKeyAdminController::class, 'create']);
    $group->post('/api-keys/{id:[0-9]+}/revoke',   [ApiKeyAdminController::class, 'revoke']);

    // --- Access log ---
    $group->get('/access-log',                     [AccessLogController::class, 'list']);

    // --- Health ---
    $group->get('/health',                         [HealthAdminController::class, 'index']);

    // --- Users ---
    $group->get('/users',                          [UserAdminController::class, 'list']);
    $group->post('/users/create',                  [UserAdminController::class, 'create']);
    $group->post('/users/{id:[0-9]+}/delete',      [UserAdminController::class, 'delete']);

    // --- Embed settings ---
    $group->get('/embed-settings',                       [EmbedSettingsController::class, 'globalForm']);
    $group->post('/embed-settings',                      [EmbedSettingsController::class, 'globalSave']);

    // --- Ad analytics ---
    $group->get('/ad-analytics',                         [EmbedSettingsController::class, 'analyticsView']);

    // --- Playlists ---
    $group->get('/playlists',                                                      [PlaylistAdminController::class, 'list']);
    $group->get('/playlists/create',                                               [PlaylistAdminController::class, 'createForm']);
    $group->post('/playlists/create',                                              [PlaylistAdminController::class, 'create']);
    $group->get('/playlists/{id:[0-9]+}',                                          [PlaylistAdminController::class, 'detail']);
    $group->post('/playlists/{id:[0-9]+}/update',                                  [PlaylistAdminController::class, 'update']);
    $group->post('/playlists/{id:[0-9]+}/delete',                                  [PlaylistAdminController::class, 'delete']);
    $group->post('/playlists/{id:[0-9]+}/videos/add',                              [PlaylistAdminController::class, 'addVideo']);
    $group->post('/playlists/{id:[0-9]+}/videos/{videoId:[0-9]+}/remove',          [PlaylistAdminController::class, 'removeVideo']);
    $group->post('/playlists/{id:[0-9]+}/videos/reorder',                         [PlaylistAdminController::class, 'reorderVideos']);

})->add(new SessionMiddleware());
