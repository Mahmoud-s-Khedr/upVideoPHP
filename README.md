# PHP HLS Streaming System

A self-hosted PHP 8.2+ platform for **video upload, async transcoding, and secure HLS delivery**. All durable storage lives in Backblaze B2. All encoding runs locally on the VPS via FFmpeg. No Node.js, no Redis, no microservices.

---

## Features

- **Chunked upload endpoint** — validates MIME type, magic bytes, and ffprobe format before accepting
- **Asynchronous encoding queue** — DB-backed poll queue with `SELECT … FOR UPDATE SKIP LOCKED`; no Redis required
- **Adaptive HLS output** — 5-rung rendition ladder (1080p / 720p / 540p / 480p / 360p) with automatic upscale skip
- **AES-128 segment encryption** — keys generated per-video, stored AES-256 encrypted at rest, never cached on disk
- **Private B2 delivery** — players never receive a direct B2 URL; all delivery is proxied or pre-signed-redirected
- **Signed stream tokens** — HMAC-SHA256, IP-bindable, configurable TTL; cookie or query-param mode
- **WebVTT subtitle extraction** — all text-based tracks extracted, uploaded to B2, served via the key endpoint
- **Thumbnail + sprite generation** — poster at midpoint, seek-preview sprite sheet, both stored in B2
- **Progressive playback while encoding** — original accessible via 15-min pre-signed redirect until HLS is ready
- **Graceful worker shutdown** — SIGTERM completes the current rendition then exits cleanly; stale-job reaper recovers crashed workers
- **Health check endpoint** — DB ping + work-dir write test; suitable for load-balancer probes

---

## Requirements

| Dependency | Minimum version |
|---|---|
| PHP | 8.2 (extensions: `pdo_mysql`, `pcntl`, `openssl`, `json`, `mbstring`) |
| MySQL / MariaDB | 8.0+ / 10.6+ (requires `SKIP LOCKED` support) |
| FFmpeg | 6.0+ (with `libx264` and native AAC encoder) |
| Nginx | any recent stable |
| Supervisor | any recent stable |
| Composer | 2.x |
| Backblaze B2 | private bucket with S3-compatible API |

---

## Quick Start (development)

```bash
# 1. Clone and install dependencies
git clone <repo-url> && cd php-hls-streaming
composer install

# 2. Configure environment
# Copy the template below into a .env file and fill in the required values
cat > .env << 'EOF'
APP_BASE_URL=https://example.com
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=videosystem
DB_USER=videosystem
DB_PASS=changeme
B2_KEY_ID=
B2_APP_KEY=
B2_BUCKET=
B2_ENDPOINT=
B2_REGION=us-west-004
STREAM_TOKEN_SECRET=$(openssl rand -base64 48)
KEY_ENCRYPTION_SECRET=$(openssl rand -hex 32)
CORS_ALLOWED_ORIGIN=
EOF
# Then edit .env and supply the required B2 and DB credentials

# 3. Create the database schema (run all 5 migrations in order)
for f in database/migrations/*.sql; do
  echo "Running $f ..."
  mysql -u root -p videosystem < "$f"
done

# 4. Seed your first admin user and API key
php bin/seed.php

# 5. Create local work directories
sudo mkdir -p /var/video-work/incoming /var/video-work/processing
sudo chown -R www-data:www-data /var/video-work

# 6. Start a worker manually (or via Supervisor — see docs/deployment.md)
sudo -u www-data php bin/worker.php
```

> For a full production deployment (Nginx, PHP-FPM pools, Supervisor), see **[docs/deployment.md](docs/deployment.md)**.

---

## API Reference

All endpoints require `Authorization: Bearer <api_key>` unless noted.  
Stream and key endpoints use short-lived signed tokens (see token issuance below).

| Method | Path | Auth | Description |
|--------|------|------|-------------|
| `GET` | `/health` | — | Database ping + disk check |
| `POST` | `/api/upload` | API key (`can_upload`) | Upload a video file (multipart or raw body) |
| `GET` | `/api/videos/{uuid}` | API key | Video metadata, status, poster URL |
| `GET` | `/api/videos/{uuid}/progress` | API key | Encoding progress 0–100 and current rendition |
| `POST` | `/api/videos/{uuid}/token` | API key | Issue a stream token for embedding |
| `GET` | `/api/videos/{uuid}/original` | Stream token | Stream original (while encoding; 410 once deleted) |
| `DELETE` | `/api/videos/{uuid}` | API key | Delete video and all B2 objects |
| `DELETE` | `/api/videos/{uuid}/audio-tracks/{index}` | API key | Remove one audio track; rebuilds master.m3u8 for ready videos |
| `GET` | `/api/stream/{uuid}/master.m3u8` | Stream token | Rewritten master HLS playlist |
| `GET` | `/api/stream/{uuid}/{label}/index.m3u8` | Stream token | Rendition playlist |
| `GET` | `/api/stream/{uuid}/{label}/{segment}.ts` | Stream token | 302 redirect to pre-signed B2 segment |
| `GET` | `/api/keys/{uuid}/{key_index}` | Stream token | Raw 16-byte AES-128 decryption key |

### Upload parameters

| Field | Required | Description |
|---|---|---|
| `file` | ✅ | Video file (multipart form field) |
| `target_qualities[]` | | Optional array of quality labels to encode. Valid values: `1080p`, `720p`, `540p`, `480p`, `360p`. When omitted all applicable rungs are encoded (upscaling always prevented regardless). Example: `target_qualities[]=720p&target_qualities[]=480p` |

### Upload response

```json
{
  "video_uuid": "550e8400-e29b-41d4-a716-446655440000",
  "status": "queued",
  "created_at": "2026-03-01T10:00:00Z"
}
```

### Progress response

```json
{
  "video_uuid": "550e8400-...",
  "status": "processing",
  "progress_pct": 47,
  "current_rendition": "720p"
}
```

### Token issuance

```bash
# Browser clients — returns HttpOnly cookie + playlist URL
curl -X POST https://yourdomain.com/api/videos/{uuid}/token \
     -H "Authorization: Bearer <api_key>"

# Non-browser clients — returns token in JSON body
curl -X POST "https://yourdomain.com/api/videos/{uuid}/token?format=token" \
     -H "Authorization: Bearer <api_key>"
```

---

## Configuration Reference

All settings live in `.env` (never committed to VCS).

| Variable | Required | Default | Description |
|---|---|---|---|
| `DB_HOST` | ✅ | — | MySQL host |
| `DB_PORT` | | `3306` | MySQL port |
| `DB_NAME` | ✅ | — | Database name |
| `DB_USER` | ✅ | — | Database user |
| `DB_PASS` | | `''` | Database password |
| `B2_KEY_ID` | ✅ | — | B2 application key ID |
| `B2_APP_KEY` | ✅ | — | B2 application key secret |
| `B2_BUCKET` | ✅ | — | B2 bucket name |
| `B2_ENDPOINT` | ✅ | — | B2 S3-compatible endpoint URL |
| `B2_REGION` | ✅ | — | B2 region (e.g. `us-west-004`) |
| `STREAM_TOKEN_SECRET` | ✅ | — | HMAC key for stream tokens (`openssl rand -base64 48`) |
| `STREAM_TOKEN_TTL_SECONDS` | | `14400` | Token lifetime (4 h default) |
| `KEY_ENCRYPTION_SECRET` | ✅ | — | 64-char hex (32 bytes) — AES-256 key for encrypting HLS keys at rest (`openssl rand -hex 32`) |
| `APP_BASE_URL` | ✅ | — | Public HTTPS base URL — embedded in HLS playlists; wrong value = playback failure |
| `CORS_ALLOWED_ORIGIN` | | `''` | Browser CORS origin; empty = CORS disabled |
| `FFMPEG_BIN` | | `/usr/bin/ffmpeg` | FFmpeg binary path |
| `FFPROBE_BIN` | | `/usr/bin/ffprobe` | ffprobe binary path |
| `WORK_DIR` | | `/var/video-work` | Local encoding work directory |
| `MAX_UPLOAD_BYTES` | | `8589934592` | 8 GB upload limit |
| `WORKER_POLL_INTERVAL` | | `5` | Seconds between queue poll loops |
| `STALE_JOB_TIMEOUT_MINUTES` | | `30` | Reaper resets jobs stuck in `claimed` longer than this |
| `MIN_DISK_FREE_BYTES` | | `21474836480` | Workers pause below this free-space threshold (20 GB) |

---

## Architecture Overview

```
Client
  │
  │  POST /api/upload (multipart)
  ▼
Nginx ──► PHP-FPM upload pool (no timeout)
  │         │
  │         ├─ 5-stage validation (size, MIME, magic bytes, ffprobe format, video stream)
  │         ├─ move file to /var/video-work/incoming/{uuid}/
  │         └─ INSERT videos + encoding_jobs  ──────────────────────────────────────────┐
  │                                                                                      │
  │  202 { video_uuid }  ◄──────────────────────────────────────────────────────────────┘
  ▼
Worker (Supervisor, 2 processes)
  │
  ├─ Poll encoding_jobs (SELECT … FOR UPDATE SKIP LOCKED every 5 s)
  ├─ Upload original to B2 → set original_b2_key (enables progressive playback)
  ├─ ffprobe analysis
  ├─ Generate AES-128 key + IV → encrypt + store in encryption_keys
  ├─ Extract WebVTT subtitles → upload to B2
  ├─ Encode renditions one-by-one (1080p → 720p → 540p → 480p → 360p)
  │    └─ FFmpeg HLS output with AES-128 encryption per rendition
  │    └─ Upload each rendition to B2 after encode completes
  ├─ Generate poster.jpg + sprite.jpg → upload to B2
  ├─ Build master.m3u8 → upload to B2
  ├─ Delete B2 original + local files
  └─ SET status = 'ready'

Reaper (Supervisor, 1 process)
  └─ Every 5 min: reset encoding_jobs WHERE status='claimed' AND claimed_at < NOW() - 30 min

Player
  │
  │  POST /api/videos/{uuid}/token  →  stream_token cookie (browser) or JSON (non-browser)
  │  GET  /api/stream/{uuid}/master.m3u8   (token-validated; URIs rewritten to delivery endpoint)
  │  GET  /api/stream/{uuid}/720p/index.m3u8
  │  GET  /api/stream/{uuid}/720p/seg00001.ts  →  302 to pre-signed B2 URL
  │  GET  /api/keys/{uuid}/0  →  raw 16-byte AES-128 key (token-validated)
  ▼
Backblaze B2 (private bucket — direct access only via pre-signed URLs)
```

---

## Admin Dashboard

The system includes a full web-based admin UI accessible at `/admin`.

### First-time setup

```bash
# Create admin users and a seed API key interactively
php bin/seed.php
```

`bin/seed.php` will prompt for a username and password, hash the password with bcrypt, and insert a row into `admin_users`. Run it once per environment after running migrations.

### Dashboard features

| Section | Path | Description |
|---|---|---|
| Dashboard | `/admin` | Summary stats: total videos, jobs by status, disk / DB health |
| Videos | `/admin/videos` | Browse all videos, set target quality ladder, upload extra subtitles, delete |
| Encoding jobs | `/admin/jobs` | Monitor active and queued jobs, send cancel signals |
| API keys | `/admin/api-keys` | Create, list, and revoke bearer API keys |
| Access log | `/admin/access-log` | HLS key-delivery access log (IP, video, stream key index) |
| Users | `/admin/users` | Manage admin accounts |
| Playlists | `/admin/playlists` | Curated video playlists served via `GET /api/playlists/{uuid}` |
| Health | `/admin/health` | Live DB ping + disk-space check |

All admin routes (except `/admin/login`) are protected by `SessionMiddleware`; unauthenticated requests are redirected to the login page.

---

## Running Tests

```bash
# Install dev dependencies (only needed once)
composer install

# Run all tests (unit only when no DB is available — integration auto-skips)
composer test

# Unit tests only (no DB, no FFmpeg, no B2 required)
composer test:unit

# Integration tests only (requires MySQL at DB_HOST/DB_NAME/DB_USER/DB_PASS)
composer test:integration
```

### Test configuration

`phpunit.xml` sets safe test-only env vars so the unit suite works out of the box. Integration tests connect to the DB configured in `phpunit.xml` (default: `videosystem_test` on `127.0.0.1`). If the DB is unreachable, those tests are automatically marked **incomplete** rather than failed.

**What is tested:**
- `StreamToken` — sign/verify round-trips, expiry, tampered signatures, IP binding, malformed tokens
- `MagicBytesChecker` — binary header detection for all 5 container types + edge cases
- `PlaylistRewriter` — master and rendition playlist rewriting, token param injection
- `ValidationException` — constructor, error codes, HTTP status codes
- `EncodingException` / `CancelledException` — retryable/non-retryable flags
- `ShutdownFlag` — request/reset lifecycle
- `Config` — all getters, defaults, required-var enforcement, key decoding
- `Connection` — get, execute, fetch, fetchAll, ping, reset
- `JobQueue` — claim, markDone, markFailed, requeueForRetry (all delay tiers), cancel, progress
- `ApiKeyAuth` — 401/403 on invalid/missing/revoked/restricted keys, attribute attachment

**Not covered by this suite** (require real FFmpeg + B2):
- `RenditionPipeline`, `VideoProcessor`, `ThumbnailGenerator`, `SubtitleExtractor`, `B2Client`

---

## Security Notes

- API keys are stored as **bcrypt hashes** — plaintext is never persisted
- Stream tokens are **HMAC-SHA256 signed**, short-lived (4 h default), and optionally IP-bound
- AES-128 HLS keys are **AES-256-CBC encrypted at rest** in the DB; raw bytes exist on disk only during an active FFmpeg run
- The B2 bucket is **private** — no public access; players receive only short-lived pre-signed redirect URLs
- `/var/video-work/` is outside the Nginx web root with no alias pointing to it
- All SQL queries use **prepared statements** — no string interpolation
- Error responses **never expose** internal paths, stack traces, or FFmpeg command lines
- Worker processes run as `www-data`, not `root`

---

## Deployment

See [docs/deployment.md](docs/deployment.md) for the full step-by-step production deployment guide covering server provisioning, Nginx, PHP-FPM pool setup, Supervisor, database setup, B2 bucket configuration, and verification.

---

## Directory Structure

```
bin/
  worker.php          CLI worker — claims and processes encoding jobs
  reaper.php          CLI reaper — resets stale claimed jobs every 5 min
config/
  config.php          Typed Config accessors (reads $_ENV)
database/
  migrations/
    001_initial_schema.sql   Full DB schema (7 tables)
deploy/
  nginx/upload.conf         Nginx vhost with dedicated upload socket
  php-fpm/upload.conf       Dedicated PHP-FPM pool (no timeout)
  supervisor/video-worker.conf
  supervisor/job-reaper.conf
docs/
  deployment.md       Full deployment guide
public/
  index.php           Slim 4 bootstrap + all routes
src/
  Api/                HealthController, VideoController
  Auth/               ApiKeyAuth, StreamToken, StreamTokenAuth
  Database/           Connection (PDO singleton)
  Encoding/           FFmpeg pipeline, key management, thumbnails, subtitles
  Queue/              JobQueue
  Storage/            B2Client, ObjectUploader
  Streaming/          Playlist/segment/key/token controllers, PlaylistRewriter
  Upload/             UploadController, FileValidator, MagicBytesChecker
  Worker/             VideoProcessor, CrashRecovery, ShutdownFlag
tests/
  Unit/               Unit tests (no DB, no FFmpeg, no B2)
  Integration/        Integration tests (require MySQL)
```

---

## License

MIT — see `LICENSE`.
