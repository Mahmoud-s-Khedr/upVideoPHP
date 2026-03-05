# Production Deployment Guide

This guide describes the production deployment flow for a fresh **Ubuntu 24.04 LTS** VPS. The preferred entrypoint is the repo’s bootstrap script:

```bash
sudo ./scripts/deploy-prod.sh --config /absolute/path/to/prod.env
```

The script installs the host dependencies, deploys the app, writes `.env`, applies tracked migrations, bootstraps the initial admin user and API key, configures Nginx / PHP-FPM / Supervisor, obtains TLS, and verifies the deployment.

---

## Table of Contents

1. [Requirements](#1-requirements)
2. [Production Config File](#2-production-config-file)
3. [Automated Bootstrap](#3-automated-bootstrap)
4. [What the Script Does](#4-what-the-script-does)
5. [Manual Verification](#5-manual-verification)
6. [Operational Notes](#6-operational-notes)
7. [Updating / Redeployment](#7-updating--redeployment)
8. [Log Locations](#8-log-locations)
9. [Troubleshooting](#9-troubleshooting)

---

## 1. Requirements

### Target host

- Ubuntu 24.04 LTS
- Public DNS record already pointed at the VPS
- Ports `80` and `443` open
- `sudo` or root access

### Recommended sizing

| Resource | Minimum | Recommended |
|---|---|---|
| CPU | 4 vCPUs | 8 vCPUs |
| RAM | 4 GB | 8 GB |
| Disk | 50 GB SSD | 100 GB SSD |

> Disk sizing matters. With multiple concurrent encodes, `/var/video-work` can grow quickly. Size the host for both the source files and the intermediate rendition work.

### Repository requirements

- Run the script from a checked-out copy of this repo on the target server.
- The script expects the current repo layout, including:
  - `deploy/nginx/upload.conf`
  - `deploy/php-fpm/upload.conf`
  - `deploy/supervisor/video-worker.conf`
  - `deploy/supervisor/job-reaper.conf`

---

## 2. Production Config File

Create a config file outside the repo if possible:

```bash
cp deploy/examples/prod.env.example /root/videosystem-prod.env
nano /root/videosystem-prod.env
chmod 600 /root/videosystem-prod.env
```

### Required keys

| Variable | Description |
|---|---|
| `APP_BASE_URL` | Public HTTPS base URL for the backend |
| `LETSENCRYPT_EMAIL` | Email for Certbot registration |
| `DB_NAME` | MySQL database name |
| `DB_USER` | MySQL application user |
| `DB_PASS` | MySQL application password |
| `B2_KEY_ID` | Backblaze B2 application key ID |
| `B2_APP_KEY` | Backblaze B2 application key secret |
| `B2_BUCKET` | Private B2 bucket name |
| `B2_ENDPOINT` | B2 S3-compatible endpoint URL |
| `B2_REGION` | B2 region |
| `ADMIN_USERNAME` | Initial admin dashboard username |
| `ADMIN_PASSWORD` | Initial admin dashboard password |
| `INITIAL_API_KEY_NAME` | Name for the initial bearer API key |
| `INITIAL_API_KEY_TOKEN` | Plaintext bearer token to hash and store |

### Optional overrides

| Variable | Default |
|---|---|
| `APP_ROOT` | `/var/www/html` |
| `WORK_DIR` | `/var/video-work` |
| `WORKER_COUNT` | `2` |
| `CORS_ALLOWED_ORIGIN` | `''` |
| `TRUSTED_PROXIES` | `''` |
| `STREAM_TOKEN_SECRET` | auto-generated |
| `STREAM_TOKEN_TTL_SECONDS` | `14400` |
| `EMBED_TOKEN_SECRET` | auto-generated |
| `EMBED_TOKEN_TTL_SECONDS` | `14400` |
| `KEY_ENCRYPTION_SECRET` | auto-generated |
| `MAX_UPLOAD_BYTES` | `8589934592` |
| `WORKER_POLL_INTERVAL` | `5` |
| `STALE_JOB_TIMEOUT_MINUTES` | `30` |
| `MIN_DISK_FREE_BYTES` | `21474836480` |

### `APP_BASE_URL` is critical

`APP_BASE_URL` must match the public HTTPS backend origin exactly.

Example:

```env
APP_BASE_URL=https://api.yourdomain.com
```

Use the backend origin, not the frontend site, unless both are actually served from the same origin.

> **Critical:** `APP_BASE_URL` is embedded into every HLS playlist's `#EXT-X-KEY` URI at encode time. If it is wrong, all encrypted streams will fail to play even after you correct the environment variable, because the wrong key URL is already baked into previously generated playlists. Fix the value before the first encode.

---

## 3. Automated Bootstrap

From the repo root on the VPS:

```bash
sudo ./scripts/deploy-prod.sh --config /root/videosystem-prod.env
```

The script is designed to be rerun safely on its supported target:

- OS packages are installed if needed
- app files are re-rsynced into the deploy root
- Composer dependencies are reinstalled in production mode
- the `.env` file is regenerated from the config
- the database and user are ensured to exist
- only unapplied migrations under `database/migrations/*.sql` are applied in lexical order
- the admin user and API key are upserted by name
- Nginx / PHP-FPM / Supervisor config is refreshed
- TLS certificates are created or renewed

If you only need to apply pending database migrations after the app is already deployed, use the standalone migration runner instead of the full bootstrap:

```bash
./scripts/apply-migrations.sh --target prod --config /absolute/path/to/prod.env
./scripts/apply-migrations.sh --target prod --env-file /absolute/path/to/.env
```

`--config` accepts the same shell-style production config file as `deploy-prod.sh`. `--env-file` is useful for already-deployed installs; for the default path that usually means running as a user that can read `/var/www/html/.env`.

---

## 4. What the Script Does

### 4.1 Host packages

The script installs:

- Nginx
- MySQL server
- FFmpeg
- Supervisor
- Certbot
- Composer
- PHP 8.4 CLI / FPM / MySQL / mbstring / curl / xml

### 4.2 App deployment

The repo is copied into `/var/www/html` by default using `rsync`, excluding:

- `.git`
- `.env`
- `vendor`

Then it runs:

```bash
composer install --no-dev --optimize-autoloader
```

### 4.3 Runtime directories

The script creates:

```text
/var/video-work/incoming
/var/video-work/processing
```

and assigns ownership to `www-data:www-data`.

### 4.4 Environment file

The production `.env` is written to:

```text
/var/www/html/.env
```

Permissions:

- owner: `www-data:www-data`
- mode: `600`

### 4.5 Database bootstrap

The script:

1. creates the MySQL database if needed
2. creates or updates the MySQL user
3. grants privileges
4. applies only unapplied migrations in `database/migrations/*.sql`

The script records applied files in a `schema_migrations` table:

```sql
CREATE TABLE IF NOT EXISTS schema_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_schema_migrations_filename (filename)
);
```

If the database already contains known application tables such as `videos`, `api_keys`, or `admin_users` but does not contain `schema_migrations`, the script stops and requires a manual migration backfill instead of guessing which SQL files already ran.

This is the important correction relative to the older guide. A fully operational deployment requires all current migrations, not just `001_initial_schema.sql`, but rerun safety now comes from migration tracking rather than replaying raw SQL files on every deploy.

The standalone runner at `./scripts/apply-migrations.sh` uses the same migration tracking and the same guard against legacy databases that lack `schema_migrations`.

### 4.6 Admin and API bootstrap

Production bootstrap does **not** use `bin/seed.php`.

Instead, the script uses the production config values to:

- upsert the initial admin user in `admin_users`
- upsert the initial API key in `api_keys`
- hash both secrets with `password_hash()`

This avoids the development defaults and development-named keys from the local seeder.

### 4.7 PHP-FPM / Nginx / Supervisor

The script installs and refreshes:

- the dedicated upload pool
- the Nginx site config
- the worker and reaper Supervisor programs

It also templates:

- the upload size limit
- the worker process count
- the deployment domain

### 4.8 TLS

TLS is obtained with Certbot for the domain derived from `APP_BASE_URL`.

---

## 5. Manual Verification

Even though the script runs its own verification, run these checks yourself after the first deploy.

### Health endpoint

```bash
curl https://yourdomain.com/health
```

Expected:

```json
{"status":"ok","db":"ok","disk":"ok"}
```

### Supervisor status

```bash
supervisorctl status
```

Expected:

```text
job-reaper                    RUNNING
video-worker:video-worker_00  RUNNING
video-worker:video-worker_01  RUNNING
```

### Upload a test file

```bash
curl -X POST https://yourdomain.com/api/upload \
     -H "Authorization: Bearer your-initial-api-key-token" \
     -F "file=@/path/to/sample.mp4"
```

### Check worker logs

```bash
tail -f /var/log/video-worker.log /var/log/video-worker-error.log
```

### Admin login

Open:

```text
https://yourdomain.com/admin/login
```

Sign in with the `ADMIN_USERNAME` / `ADMIN_PASSWORD` values from your production config file.

---

## 6. Operational Notes

### Backblaze B2

Use a **private** bucket. Players should never receive a direct permanent B2 URL.

Recommended bucket-side settings:

- private bucket access
- incomplete multipart upload lifecycle cleanup

### Upload and body size alignment

`MAX_UPLOAD_BYTES` should match the Nginx and PHP-FPM upload limits the script writes. If you change the application upload limit later, redeploy so the web-tier limits stay aligned.

### Worker sizing

Each worker process runs CPU-bound FFmpeg jobs. Increase `WORKER_COUNT` only when the host has enough CPU, RAM, and disk throughput to support more concurrent encodes.

---

## 7. Updating / Redeployment

To redeploy updated code:

1. update the checked-out repo on the server
2. rerun the same deployment command

Example:

```bash
git pull origin main
sudo ./scripts/deploy-prod.sh --config /root/videosystem-prod.env
```

This reapplies configuration, refreshes the app files, runs production Composer install, applies only unapplied migrations, and restarts the managed services.

If you only need the database changes and do not want to rerun the full deployment flow, run one of these instead:

```bash
./scripts/apply-migrations.sh --target prod --config /root/videosystem-prod.env
sudo ./scripts/apply-migrations.sh --target prod --env-file /var/www/html/.env
```

---

## 8. Log Locations

| Component | Log file |
|---|---|
| Nginx access log | `/var/log/nginx/videosystem.access.log` |
| Nginx error log | `/var/log/nginx/error.log` |
| PHP-FPM error log | `/var/log/php8.4-fpm.log` |
| Upload pool access log | `/var/log/php8.4-fpm-upload.access.log` |
| Video worker stdout | `/var/log/video-worker.log` |
| Video worker stderr | `/var/log/video-worker-error.log` |
| Job reaper stdout | `/var/log/job-reaper.log` |
| Job reaper stderr | `/var/log/job-reaper-error.log` |
| MySQL error log | `/var/log/mysql/error.log` |

---

## 9. Troubleshooting

| Symptom | Likely Cause | Fix |
|---|---|---|
| TLS step fails | DNS is not pointed at the VPS, or port 80 is blocked | Fix DNS/firewall and rerun the script |
| Admin login fails after deploy | Wrong `ADMIN_PASSWORD` or admin user was updated unexpectedly | Recheck the production config and rerun bootstrap |
| HLS key fetch fails | `APP_BASE_URL` was wrong during encode | Fix `APP_BASE_URL` and re-encode affected videos |
| Upload returns `413` | Web-tier upload size is lower than the app limit | Increase `MAX_UPLOAD_BYTES` in the config and redeploy |
| Workers remain idle | Supervisor not running or DB unreachable | Check `supervisorctl status` and `/var/log/video-worker-error.log` |
| B2 signature errors | Wrong key / endpoint / region combination | Verify `B2_KEY_ID`, `B2_APP_KEY`, `B2_ENDPOINT`, and `B2_REGION` |
| Stale jobs remain `claimed` | Reaper not running | Check `supervisorctl status job-reaper` |
| Browser token cookie is not sent | Wrong CORS origin or a cross-origin cookie flow mismatch | Set `CORS_ALLOWED_ORIGIN` to the exact browser origin; avoid `*` |

The deployment script is the source of truth for the preferred production path. If the manual and the script ever diverge, update the script first and then bring this document back into alignment.
