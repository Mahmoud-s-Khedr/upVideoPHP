# System Feature Inventory

## Scope And Method

- This document inventories features implemented in the current repository, based on application wiring, controllers, worker code, player code, templates, schema migrations, and supporting tests.
- It describes observed behavior in the codebase today rather than product intent, README marketing language, or possible future extensions.
- Public APIs, admin screens, background processing, playback runtime behavior, and data model capabilities are all included because the system spans operator and end-user surfaces.
- When a capability exists in schema or admin settings but is not clearly wired into the current runtime path, it is called out as configuration-only or not fully confirmed.

## Core Platform Capabilities

- The system is a self-hosted PHP 8.4 video platform built around Slim, Twig, MySQL/MariaDB, FFmpeg, and Backblaze B2 object storage.
- Video intake, transcoding, encryption, playlist generation, and delivery are split between synchronous HTTP endpoints and asynchronous worker processes.
- Durable media assets are stored in a private B2 bucket rather than on the local web server.
- The platform delivers adaptive HLS playback with encrypted segments and separate AES key delivery.
- The platform exposes two public playback surfaces: a standalone watch page at `/watch/{uuid}` and a signed embed player at `/embed/{embedToken}`.
- The platform includes an admin back office under `/admin` for operations, moderation, analytics, and configuration.

## Upload And Intake

- Upload endpoints require bearer API-key auth with the `can_upload` permission.
- The intake flow is split across two HTTP calls so the PHP server never buffers video bytes.
- `POST /api/upload/init` validates request metadata (filename, declared `content_type`, declared `size_bytes`, optional `target_qualities`), inserts a `pending` video row, generates a presigned B2 PUT URL, and returns it to the client alongside the video UUID.
- The client streams the file body directly to Backblaze B2 using the presigned PUT URL; PHP-FPM is not involved in the data transfer.
- Files ≥5 GB use the B2 multipart API: `upload_mode` in the init response is `"multipart"` and per-part presigned PUT URLs are issued via `POST /api/upload/{uuid}/parts`.
- Multipart uploads are finalised by `POST /api/upload/{uuid}/complete-multipart`, which calls the B2 CompleteMultipartUpload API and then queues the encoding job.
- `POST /api/upload/complete` (single-part path) verifies the upload via B2 `HeadObject` (no download), reads the authoritative file size from B2, validates declared content type against the allowed video MIME set, then atomically transitions the video row to `queued` and inserts the encoding job.
- Accepted source formats are: MP4, Matroska/MKV, MPEG-TS, AVI, MOV/QuickTime, and WebM.
- The upload endpoint accepts optional `target_qualities[]` values to constrain which rendition labels are encoded for that upload.
- Original filenames are sanitized for display and are not reused as B2 object keys.
- The complete step returns `202 Accepted` immediately with video UUID and queued status; transcoding begins asynchronously.

## Encoding And Media Processing

- A long-lived worker processes queued encoding jobs outside the request path.
- The worker downloads the source file from B2 into a job-specific local processing directory before analysis.
- The pipeline runs `ffprobe` analysis early and stores duration on the `videos` record.
- Subtitle extraction runs before full HLS encoding and writes subtitle metadata plus uploaded WebVTT assets.
- Poster thumbnail generation runs as part of the worker pipeline.
- Sprite-sheet generation runs as part of thumbnail generation and stores sprite layout metadata on the video record.
- Audio tracks are extracted into separate audio-only HLS playlists before full rendition encoding.
- The worker uploads the original source file to B2 before HLS completion so playback can fall back to the original while encoding continues.
- Per-video AES-128 HLS key material is generated during processing, while the stored key material is encrypted at rest before being saved in MySQL.
- Rendition encoding uses an adaptive ladder with labels `1080p`, `720p`, `540p`, `480p`, and `360p`.
- Rendition selection is gated by source height so the pipeline does not upscale smaller sources.
- Per-video quality restrictions from `target_qualities` further limit which otherwise-applicable renditions are encoded.
- Encoding progress is tracked per job, including overall percentage and the currently active rendition label.
- After renditions complete, the worker builds and uploads a master HLS playlist.
- Once the HLS output is ready, the worker deletes the original file from B2 and records `original_deleted_at`.
- The worker removes the local processing directory after a successful completion.
- Subtitle extraction warnings and audio extraction warnings are appended to job error text without aborting the entire job.

## Storage And Delivery

- B2 is used for uploading source files, renditions, audio playlists, subtitles, posters, sprites, and master playlists.
- The system uses presigned B2 URLs for selected direct-download cases instead of exposing bucket objects anonymously.
- Normal HLS playlist requests are served by PHP after fetching playlist content from B2 and rewriting internal URIs.
- Segment requests are redirected to short-lived presigned B2 URLs rather than streamed through PHP-FPM.
- Audio-segment requests are also redirected to short-lived presigned B2 URLs.
- Original-file playback fallback uses a presigned B2 URL returned from `GET /api/videos/{uuid}/original`.
- Poster URLs are presigned when returned from metadata and playlist APIs.
- Subtitle track URLs in bootstrap and original-playback responses are presigned per track.
- Sprite assets are presigned when exposed through the playback bootstrap payload.
- Delete and retry flows use prefix-based B2 cleanup for a video or sub-path rather than enumerating individual files in application code.

## Streaming And Playback Security

- Management endpoints use bearer API-key authentication via `Authorization: Bearer <token>`.
- API keys support separate permission flags for upload and stream-related usage.
- API keys are stored as bcrypt hashes rather than plaintext tokens.
- HLS playlists, segments, and AES key endpoints are protected by short-lived HMAC-signed stream tokens.
- Stream tokens can be delivered in browser mode via an HttpOnly cookie or in non-browser mode via a query parameter.
- Browser-mode stream token issuance can bind the token to the client IP address, with `X-Forwarded-For` only trusted when the direct peer is a configured trusted proxy.
- Non-browser stream token issuance intentionally skips IP binding to tolerate network changes.
- `GET /api/keys/{uuid}/{keyIndex}` only serves keys for ready videos and returns raw 16-byte AES key material with `Cache-Control: no-store`.
- Embed playback uses a separate signed token format that binds the embed session to a specific video UUID and parent origin.
- `POST /api/videos/{uuid}/embed-sessions` validates `parent_origin`, clamps TTL to 5 minutes through 12 hours, and returns a signed embed URL.
- `GET /embed/{embedToken}` sets a `Content-Security-Policy` `frame-ancestors` directive derived from the embed token’s `parent_origin`.
- Embed pages also set `Cache-Control: no-store`, `Referrer-Policy: strict-origin-when-cross-origin`, and `X-Content-Type-Options: nosniff`.
- Token verification failures on protected playback routes return explicit 403-style JSON responses rather than silently redirecting.

## Public Playback Surfaces

- `GET /watch/{uuid}` renders a standalone public watch page with the shared custom player and server-rendered bootstrap data.
- `GET /embed/{embedToken}` renders a signed public iframe player with origin restrictions derived from the embed token.
- `GET /embed/{embedToken}/bootstrap.json` returns playback bootstrap JSON for embed clients.
- Playback bootstrap resolves one of four runtime modes: `pending`, `original`, `hls`, or `error`.
- Videos in `ready` state bootstrap into encrypted HLS playback mode.
- Non-ready videos with an uploaded original file bootstrap into original-file fallback mode.
- Non-ready videos without a playable original bootstrap into a pending state with polling instructions.
- Videos in `error` state bootstrap into an unavailable/error mode.
- Pending and original-fallback playback modes both include poll intervals so clients can retry bootstrap until HLS becomes available.
- The embed bootstrap payload includes title, duration, poster, sprite metadata, subtitle tracks, playback mode, expiry time, and merged embed settings.
- The watch page and embed page share the same player partial and runtime script rather than implementing separate playback stacks.

## Player Runtime Features

- The public player is a custom JavaScript video player in `public/assets/player/player.js`.
- The player supports HLS playback through `hls.js` when native playback is not available.
- The player falls back to native Safari HLS when the browser can play HLS directly and `hls.js` is not needed.
- The player supports play and pause controls through both the poster/big-play overlay and the control bar.
- The player supports mute and volume controls.
- The player supports seek controls with both buffered-progress and played-progress visualization.
- The player supports manual quality selection plus an auto-quality mode for adaptive playback.
- The player supports playback speed selection.
- The player supports subtitle track injection from bootstrap data and allows toggling captions off or selecting a specific track.
- The player supports fullscreen mode through the browser Fullscreen API.
- The player renders a poster image when one is available.
- The player can display a title bar when `title_visible` is enabled in embed settings.
- The player can display a positioned logo/watermark from embed settings.
- The player stores resume position in `localStorage` per video UUID.
- The player offers a resume prompt when a saved position exists and the saved point is meaningfully inside the video.
- The player clears saved resume state when playback reaches the end.
- The player shows a pending-processing state screen for videos that are not yet watchable.
- The player shows a banner when it is temporarily using original-file fallback instead of the optimized HLS stream.
- The player auto-polls the embed bootstrap endpoint while in pending or original-fallback mode.
- The player posts events to the parent frame in embed mode, including ready, play, pause, ended, and error notifications.
- The embed player also accepts parent-frame commands for play, pause, seek, and mute when the message origin matches the signed `parent_origin`.
- The player suppresses the context menu on the player container as a basic anti-download measure.
- The player supports keyboard shortcuts for play/pause, fullscreen, mute, seek, and volume changes.
- The player auto-hides controls while video is playing and restores them on pointer interaction.

## Ads And Monetization

- Global embed settings provide the main ad and monetization configuration surface for the public player and watch page.
- Per-video overrides are intentionally limited to video-ad fields such as adblock enforcement, preroll, postroll, and midroll cue configuration.
- The player supports preroll video ads before first HLS playback.
- The player supports postroll video ads after main-content completion.
- The player supports midroll cue points driven by either absolute seconds or playback percentage triggers.
- Ad slots support configurable skip delays, including fully unskippable ads when the delay is `0`.
- Ad slots support click-through URLs through a full-overlay clickable layer.
- Ad slot configuration supports both `mp4` and `vast` source kinds.
- VAST handling supports wrapper resolution up to a bounded depth and extracts a playable media file plus optional click-through URL.
- When multiple VAST media files are present, the player prefers MP4, then falls back to the first browser-playable alternative.
- Ad events are recorded through the public `POST /api/ad-event` endpoint.
- Ad event tracking records start, skip, complete, and click events for preroll, midroll, and postroll positions.
- Ad event tracking stores a sanitized client session identifier when provided and a SHA-256 hash of the remote IP for privacy-safe aggregation.
- The admin back office includes an ad analytics screen at `/admin/ad-analytics` that aggregates counts by video, position, and event.
- The watch page supports globally configured top and bottom banner HTML blocks.
- The embed page supports a globally configured embed-banner HTML block.
- Both watch and embed pages can inject a globally configured general script URL.
- Both watch and embed pages can inject globally configured raw general HTML code.
- The player supports a first-play direct action via `direct_play_url` before HLS playback begins.
- Direct-play behavior supports `popup`, `redirect`, and `iframe` modes in the runtime player.
- When popup mode is selected and the popup is blocked, the player can fall back to an iframe overlay if `direct_popup_bypass_iframe` is enabled.
- The schema and admin settings include `direct_download_url` and `direct_download_mode`, but current player runtime code does not surface a confirmed direct-download flow; this is configuration/data-model support without confirmed active player behavior.
- The player can detect ad blockers through a bait-element check.
- When `force_disable_adblock` is enabled and at least one video ad is configured, the player can block playback behind an adblock overlay until the condition is cleared.

## Playlists

- The system has an admin-managed playlist model with stable UUID addressing.
- Playlists support title and description metadata.
- Admin users can create playlists from `/admin/playlists/create`.
- Admin users can update playlist title and description from the playlist detail screen.
- Admin users can delete playlists from the playlist detail screen.
- Admin users can add videos to playlists from the playlist detail screen.
- Admin add-video flows only offer videos currently in `ready` status.
- Admin users can remove a video from a playlist.
- Admin users can reorder playlist videos through an explicit position-based ordering flow.
- The public/API playlist endpoint `GET /api/playlists/{uuid}` returns playlist metadata plus ordered ready videos only.
- The public/API playlist response excludes videos that are not ready, preventing partially processed items from leaking into curated playback flows.
- Playlist API responses can include presigned poster URLs for member videos.

## Admin Back Office

- `/admin/login` provides session-based admin authentication rather than API-key auth.
- `/admin` provides a dashboard with video counts by status, job counts by status, active workers, recent failures, and disk usage hints.
- `/admin/videos` provides a paginated video list with optional status filtering.
- `/admin/videos/{uuid}` provides a detail screen with video metadata, latest job state, renditions, subtitles, and quality selection context.
- Admin users can delete videos from the video detail flow, including cleanup of related B2 objects and local work directories.
- Admin users can set or update target qualities before encoding starts.
- Admin users can manually upload subtitle files for a video.
- Admin users can delete subtitle tracks for a video.
- `/admin/jobs` provides a paginated encoding-job list with optional status filtering.
- Admin users can request cancellation for queued or claimed jobs.
- `/admin/api-keys` lists all API keys with permission flags and revocation state.
- Admin users can create API keys and receive the raw token once via session-backed flash display.
- Admin users can revoke API keys.
- `/admin/access-log` shows a paginated stream access log with UUID and action filtering.
- `/admin/health` shows DB health, disk health, queue depth, active jobs, stale jobs, and recent failed jobs.
- `/admin/users` lists admin users.
- Admin users can create additional admin accounts with password confirmation and username validation.
- Admin users can delete admin accounts subject to safeguards against self-deletion and deleting the last remaining admin.
- `/admin/embed-settings` provides a global embed/ad settings editor.
- `/admin/videos/{uuid}/embed` provides a per-video override editor plus embed-code context for that specific video.
- `/admin/ad-analytics` exposes aggregated ad impression/event reporting.
- `/admin/playlists` and related routes provide playlist list/create/detail/update/delete/add/remove/reorder flows.

## Authentication And Access Control

- Public management APIs use bearer API keys stored as bcrypt hashes and verified server-side.
- API keys carry independent `can_upload` and `can_stream` flags.
- Upload endpoints require API keys with upload permission.
- Other management endpoints use API-key auth without requiring upload permission, allowing read/manage flows to operate under non-upload keys.
- Admin authentication uses username/password credentials stored in the `admin_users` table with bcrypt password hashes.
- Successful admin login regenerates the PHP session ID to reduce session-fixation risk.
- Session middleware protects `/admin/*` routes and redirects unauthenticated users to `/admin/login`.
- Session middleware explicitly allows the login and logout routes through without an existing admin session.
- Admin form handlers use CSRF validation through the Twig factory helper before mutating state.
- Stream-token auth rejects missing, expired, tampered, mismatched, or IP-mismatched tokens on protected playback routes.
- Embed-token verification rejects tampered or expired embed URLs and rejects access when the token cannot be validated.

## Observability And Health

- `GET /health` performs a database connectivity check and a work-directory disk check suitable for service probes.
- The admin health page gives operators a richer operational view than the public `/health` endpoint.
- Playback delivery requests log best-effort access events into `access_log`.
- Access logging covers HLS playlist requests, segment redirects, key requests, and original-file fallback requests.
- The admin access-log screen can filter by video UUID and action type.
- `GET /api/videos/{uuid}/progress` exposes video status, encoding progress percentage, and current rendition.
- `GET /api/videos/{uuid}` exposes current status, renditions, subtitles, timing, size, and poster availability.
- Error state is surfaced across API and playback flows, including metadata, token issuance refusal for error videos, and bootstrap error mode.
- Ad event aggregation is available to operators through `/admin/ad-analytics`.
- Worker failures are surfaced operationally through job failure states, stored last-error text, dashboard summaries, and the admin health page.

## Background Processing And Recovery

- `bin/worker.php` runs a long-lived worker loop instead of spawning a new process per job.
- Queue claiming is DB-backed and uses `SELECT ... FOR UPDATE SKIP LOCKED` semantics through the job-queue layer.
- Workers poll for new jobs on a configurable interval when idle.
- Workers refuse to claim work when free disk space drops below a configured threshold and sleep before retrying.
- Workers support graceful shutdown through `SIGTERM` and `SIGINT`, finishing the current safe stopping point rather than hard-exiting immediately.
- Shutdown state is shared through a static shutdown flag that other pipeline code can observe.
- The worker performs startup cleanup for stale key files left behind by previous crashes.
- Before retrying a claimed job, the worker pre-cleans partial B2 uploads associated with that job.
- Retry behavior distinguishes retryable failures from non-retryable encoding failures.
- Retryable failures are requeued with backoff rather than immediately marked terminal.
- Non-retryable encoding failures mark the job failed and set the video status to `error`.
- Cancellation is cooperative through a `cancel_requested` flag rather than immediate process termination.
- Cancelled jobs are marked failed/error after local cleanup if processing was already underway.
- A separate reaper path exists in the deployment layout (`bin/reaper.php` and Supervisor config) to recover stale claimed jobs after crashes or interrupted workers.
- Crash-recovery helpers are also used during delete flows to remove processing and intake directories safely.

## API Surface Summary

- Health: `GET /health` for DB and disk probe status.
- Upload: `POST /api/upload` for authenticated video intake.
- Video management: `GET /api/videos/{uuid}`, `GET /api/videos/{uuid}/progress`, `DELETE /api/videos/{uuid}`, `POST /api/videos/{uuid}/token`, `GET /api/videos/{uuid}/original`, `DELETE /api/videos/{uuid}/audio-tracks/{index}`, `POST /api/videos/{uuid}/embed-sessions`.
- Playlist API: `GET /api/playlists/{uuid}` for curated playlist retrieval.
- HLS delivery: `GET /api/stream/{uuid}/master.m3u8`, `GET /api/stream/{uuid}/{label}/index.m3u8`, `GET /api/stream/{uuid}/{label}/{segment}.ts`.
- Alternate audio delivery: `GET /api/stream/{uuid}/audio_{audioIndex}/index.m3u8`, `GET /api/stream/{uuid}/audio_{audioIndex}/{segment}.ts`.
- Key delivery: `GET /api/keys/{uuid}/{keyIndex}` for AES decryption keys.
- Public playback: `GET /embed/{embedToken}`, `GET /embed/{embedToken}/bootstrap.json`, `GET /watch/{uuid}`.
- Ad tracking: `POST /api/ad-event`.
- Admin auth: `GET /admin/login`, `POST /admin/login`, `POST /admin/logout`.
- Admin dashboard and operations: `/admin`, `/admin/videos`, `/admin/jobs`, `/admin/api-keys`, `/admin/access-log`, `/admin/health`, `/admin/users`, `/admin/embed-settings`, `/admin/ad-analytics`, and `/admin/playlists` with their associated create/update/delete action routes.

## Data Model Summary

- `videos`: core video record including UUID, source metadata, playback status, original-file state, poster/sprite metadata, and selected target qualities.
- `encoding_jobs`: async job queue state including attempts, worker ownership, retry timing, cancellation, progress, and error text.
- `renditions`: encoded output metadata for each completed quality rung.
- `encryption_keys`: encrypted-at-rest AES key material for HLS playback.
- `subtitles`: extracted or admin-uploaded subtitle track records with language metadata and B2 VTT keys.
- `audio_tracks`: alternate audio track records and B2 prefixes for extracted audio-only HLS assets.
- `api_keys`: bearer management credentials with upload/stream permissions and revocation state.
- `access_log`: best-effort playback-delivery access records for playlists, segments, keys, and original playback.
- `admin_users`: session-authenticated operator accounts.
- `playlists`: curated playlist containers with UUID identity and descriptive metadata.
- `playlist_videos`: many-to-many playlist membership plus explicit ordering positions.
- `embed_settings`: global and per-video player/ads configuration, including branding, video ads, banners, and direct-action settings.
- `ad_impressions`: ad-event tracking records across preroll, midroll, and postroll positions.

## Notable Constraints And Behavioral Boundaries

- Target qualities are only editable before encoding starts; admin updates are rejected once a video has moved beyond `pending` or `queued`.
- The public/API playlist response only includes videos in `ready` status.
- The admin playlist add-video flow only offers ready videos.
- Per-video embed overrides only replace the subset of video-ad fields defined by `EmbedSettingsLoader`; banners, general HTML/script injections, branding, and direct-action settings remain global.
- Original-file playback is a temporary fallback; once HLS is ready, the worker deletes the original from B2 and the original endpoint returns `410 Gone`.
- Stream playlists and segments are only available for videos that are ready or otherwise pass the route-specific status checks.
- Key delivery only works for ready videos and only for keys present in the database.
- Access logging is best-effort and intentionally does not fail playback requests when logging fails.
- Subtitle delivery in the player is bootstrap-driven WebVTT, not HLS subtitle rendition playback; the playlist rewriter also strips subtitle media tags for embed-oriented playback handling.
- The schema and admin settings support `direct_download_url` and `direct_download_mode`, but current player runtime code does not confirm a visible direct-download execution path.
- The database schema includes a `pending` video status, and admin quality-setting logic can queue a pending video on first quality selection, but the upload controller currently inserts new uploads directly as `queued`.
- README and OpenAPI describe the system broadly, but this inventory intentionally reflects the implemented route/controller/runtime behavior rather than documentation promises.
