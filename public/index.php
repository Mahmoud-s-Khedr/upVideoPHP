<?php

declare(strict_types=1);

use Slim\Factory\AppFactory;
use Slim\Exception\HttpNotFoundException;
use Slim\Routing\RouteCollectorProxy;
use VideoSystem\Auth\ApiKeyAuth;
use VideoSystem\Auth\StreamTokenAuth;
use VideoSystem\Api\VideoController;
use VideoSystem\Api\HealthController;
use VideoSystem\Api\PlaylistController as ApiPlaylistController;
use VideoSystem\Upload\UploadController;
use VideoSystem\Streaming\TokenController;
use VideoSystem\Streaming\PlaylistController;
use VideoSystem\Streaming\SegmentController;
use VideoSystem\Streaming\KeyController;
use VideoSystem\Streaming\AudioPlaylistController;
use VideoSystem\Streaming\OriginalController;
use VideoSystem\Streaming\SubtitleController;
use VideoSystem\Player\EmbedSessionController;
use VideoSystem\Player\EmbedPlayerController;
use VideoSystem\Player\PlayerEventController;
use VideoSystem\Player\WatchController;
use VideoSystem\Api\AdImpressionController;
use VideoSystem\Error\NotFoundController;

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Session must be started before any output or response body is written.
// The SessionMiddleware for admin routes also calls session_start() but
// doing it here ensures it is available globally if needed.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---------------------------------------------------------------------------
// App factory
// ---------------------------------------------------------------------------
$app = AppFactory::create();
$app->addRoutingMiddleware();

// ---------------------------------------------------------------------------
// CORS middleware
// ---------------------------------------------------------------------------
$app->add(function ($request, $handler) {
    $origin = $_ENV['CORS_ALLOWED_ORIGIN'] ?? '';
    $response = $handler->handle($request);

    if ($origin === '') {
        return $response;
    }

    // OPTIONS preflight
    if ($request->getMethod() === 'OPTIONS') {
        return $response
            ->withStatus(204)
            ->withHeader('Access-Control-Allow-Origin', $origin)
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withHeader('Access-Control-Allow-Credentials', 'true')
            ->withHeader('Access-Control-Max-Age', '86400');
    }

    return $response
        ->withHeader('Access-Control-Allow-Origin', $origin)
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
        ->withHeader('Access-Control-Allow-Credentials', 'true');
});

// Global error middleware (must be last added / outermost)
$errorMiddleware = $app->addErrorMiddleware(false, false, false);
$errorMiddleware->setDefaultErrorHandler(function ($request, \Throwable $exception, bool $displayDetails) use ($app) {
    if ($exception instanceof HttpNotFoundException) {
        $path = $request->getUri()->getPath();
        $controller = new NotFoundController();

        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return $controller->json($request, $app->getResponseFactory()->createResponse());
        }

        return $controller->html($request, $app->getResponseFactory()->createResponse());
    }

    $payload = ['error' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.'];
    $response = $app->getResponseFactory()->createResponse(500);
    $response->getBody()->write(json_encode($payload, JSON_THROW_ON_ERROR));
    return $response->withHeader('Content-Type', 'application/json');
});

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

// --- Health (no auth) ---
$app->get('/health', [HealthController::class, 'handle']);
$app->map(['GET', 'HEAD'], '/favicon.ico', [NotFoundController::class, 'favicon']);

// --- Upload (API key, can_upload) ---
$app->post('/api/upload', [UploadController::class, 'handle'])
    ->add(new ApiKeyAuth(requireUpload: true));

// --- Video management (API key) ---
$app->group('/api/videos/{uuid}', function (RouteCollectorProxy $group) {
    $group->get('',                                        [VideoController::class, 'getMetadata']);
    $group->get('/progress',                               [VideoController::class, 'getProgress']);
    $group->delete('',                                     [VideoController::class, 'delete']);
    $group->post('/token',                                 [TokenController::class, 'issue']);
    $group->get('/original',                               [OriginalController::class, 'handle']);
    $group->delete('/audio-tracks/{index:[0-9]+}',         [VideoController::class, 'deleteAudioTrack']);
    $group->post('/embed-sessions',                          [EmbedSessionController::class, 'create']);
})->add(new ApiKeyAuth(requireUpload: false));

// --- Playlist API (API key) ---
$app->get('/api/playlists/{uuid}', [ApiPlaylistController::class, 'get'])
    ->add(new ApiKeyAuth(requireUpload: false));

// --- HLS streaming (stream token) ---
$app->group('/api/stream/{uuid}', function (RouteCollectorProxy $group) {
    $group->get('/master.m3u8',                              [PlaylistController::class, 'master']);
    $group->get('/subtitles/{trackIndex:[0-9]+}.vtt',       [SubtitleController::class, 'handle']);
    $group->get('/audio_{audioIndex:[0-9]+}/index.m3u8',     [AudioPlaylistController::class, 'playlist']);
    $group->get('/audio_{audioIndex:[0-9]+}/{segment}.ts',   [AudioPlaylistController::class, 'segment']);
    $group->get('/{label}/index.m3u8',                       [PlaylistController::class, 'rendition']);
    $group->get('/{label}/{segment}.ts',                     [SegmentController::class, 'handle']);
})->add(new StreamTokenAuth());

// --- AES key delivery (stream token) ---
$app->get('/api/keys/{uuid}/{keyIndex}', [KeyController::class, 'handle'])
    ->add(new StreamTokenAuth());

// --- Public embed player (signed embed token in path) ---
$app->get('/embed/{embedToken}/bootstrap.json', [EmbedPlayerController::class, 'bootstrap']);
$app->get('/embed/{embedToken}',                [EmbedPlayerController::class, 'page']);
$app->get('/embed/video/{uuid}/bootstrap.json', [EmbedPlayerController::class, 'stableBootstrap']);
$app->get('/embed/video/{uuid}',                [EmbedPlayerController::class, 'stablePage']);

// --- Public watch page (no auth) ---
$app->get('/watch/{uuid}/bootstrap.json', [WatchController::class, 'bootstrap']);
$app->get('/watch/{uuid}', [WatchController::class, 'page']);

// --- Public player session events ---
$app->post('/api/player-events', [PlayerEventController::class, 'create']);

// --- Ad impression tracking (public, fire-and-forget) ---
$app->post('/api/ad-event', [AdImpressionController::class, 'handle']);

// --- Admin dashboard ---
require __DIR__ . '/../src/Admin/routes.php';

// --- Not found fallbacks ---
$app->map(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], '/api', [NotFoundController::class, 'json']);
$app->map(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], '/api/{routes:.+}', [NotFoundController::class, 'json']);
$app->map(['GET', 'HEAD'], '/', [NotFoundController::class, 'html']);
$app->map(['GET', 'HEAD'], '/{routes:.+}', [NotFoundController::class, 'html']);

// OPTIONS preflight catch-all
$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->run();
