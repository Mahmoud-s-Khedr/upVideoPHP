# Development Deployment Guide

This guide bootstraps a complete local development stack for the PHP HLS streaming system using Docker Compose. It runs Nginx, PHP-FPM, the worker, the job reaper, MySQL 8, and MinIO as a local S3-compatible object store.

---

## 1. Prerequisites

- Docker Engine or Docker Desktop
- Docker Compose v2 (`docker compose version`)
- Ports `8080`, `9000`, `9001`, and `3307` available on the host

---

## 2. One-command bootstrap

From the repo root:

```bash
./scripts/deploy-dev.sh
```

To fully recreate the environment and clear volumes:

```bash
./scripts/deploy-dev.sh --reset
```

### Optional override

If MinIO presigned URLs need a different host/IP to be reachable from both containers and your browser:

```bash
MINIO_PUBLIC_HOST=192.168.1.50 ./scripts/deploy-dev.sh
```

---

## 3. Local URLs and credentials

After bootstrap completes and the script reports success, the HTTP health check is already green:

- App: `http://localhost:8080`
- Health check: `http://localhost:8080/health`
- MinIO API: `http://localhost:9000`
- MinIO Console: `http://localhost:9001`
- MySQL exposed port: `127.0.0.1:3307`

### Admin login

- Username: `admin`
- Password: `admin123`

### Seeded API keys

- Upload + stream: `dev-full-access-local`
- Stream only: `dev-stream-only-local`

These are deterministic local-dev credentials. Do not reuse them outside the Docker dev stack.

---

## 4. Stack topology

The local stack contains:

- `nginx` — serves the app at `http://localhost:8080`
- `php` — PHP-FPM runtime with Composer, MySQL client, and FFmpeg
- `worker` — runs `php bin/worker.php`
- `reaper` — runs `php bin/reaper.php`
- `db` — MySQL 8
- `minio` — local S3-compatible object storage
- `minio-init` — one-shot bucket bootstrap for `videosystem-dev`

The script generates `docker/dev/runtime/app.env` and mounts it into the PHP containers as `/var/www/html/.env`. It does not modify the repo root `.env`.

---

## 5. Day-to-day workflow

### Start the stack

If it is already bootstrapped:

```bash
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml up -d
```

### Stop the stack

```bash
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml down
```

### Reset everything

```bash
./scripts/deploy-dev.sh --reset
```

### Apply pending migrations only

If the dev stack was already bootstrapped and the `db` service is running, you can apply only new tracked migrations without rerunning the full bootstrap:

```bash
./scripts/apply-migrations.sh --target dev
```

This command fails fast if `docker/dev/runtime/app.env` or `docker/dev/runtime/compose.env` is missing. Run `./scripts/deploy-dev.sh` first if the runtime files do not exist yet.

### View logs

```bash
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml logs -f
```

### Run tests inside the container

```bash
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml exec php composer test:unit
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml exec -e DB_HOST=db -e DB_PORT=3306 -e DB_NAME=videosystem -e DB_USER=videosystem -e DB_PASS=videosystem php composer test:integration
```

Integration tests do not load `.env`. When you run them inside Docker, pass the database variables explicitly so PHPUnit targets the Docker MySQL service instead of its host-default `127.0.0.1` test settings.

---

## 6. Verification

### Health endpoint

```bash
curl http://localhost:8080/health
```

Expected:

```json
{"status":"ok","db":"ok","disk":"ok"}
```

### Admin login

1. Open `http://localhost:8080/admin/login`
2. Log in with `admin / admin123`

### Upload flow

The API upload uses a two-step presigned PUT flow. The file goes directly to MinIO — the app server is not involved in the transfer.

```bash
# Step 1 — get a presigned PUT URL
INIT=$(curl -s -X POST http://localhost:8080/api/upload/init \
     -H "Authorization: Bearer dev-full-access-local" \
     -H "Content-Type: application/json" \
     -d '{"filename":"sample.mp4","size_bytes":1000000,"content_type":"video/mp4"}')
echo "$INIT"
UUID=$(echo "$INIT" | python3 -c "import sys,json; print(json.load(sys.stdin)['video_uuid'])")
URL=$(echo  "$INIT" | python3 -c "import sys,json; print(json.load(sys.stdin)['upload_url'])")

# Step 2 — PUT the file directly to MinIO via the presigned URL
curl -X PUT "$URL" -H "Content-Type: video/mp4" --data-binary @/path/to/sample.mp4

# Step 3 — verify the upload and queue the encoding job
curl -s -X POST http://localhost:8080/api/upload/complete \
     -H "Authorization: Bearer dev-full-access-local" \
     -H "Content-Type: application/json" \
     -d "{\"video_uuid\":\"$UUID\"}"
```

Expected:

- Step 1: status `201`, JSON contains `video_uuid` and `upload_url` pointing to `http://<MINIO_PUBLIC_HOST>:9000/...`
- Step 2: status `200` from MinIO
- Step 3: status `202`, JSON contains `status: queued`
- Worker logs show the job being claimed and the file being downloaded from MinIO

### Worker activity

```bash
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml logs -f worker reaper
```

### Playback check

Once the worker completes:

1. Open `http://localhost:8080/watch/{video_uuid}`
2. Confirm the player loads
3. Confirm the HLS playlists and segments are served successfully

The local watch page uses the app’s existing query-token HLS flow, so playback works over plain HTTP localhost.

---

## 7. Troubleshooting

### Port conflicts

If `8080`, `9000`, `9001`, or `3307` are already in use, stop the conflicting services or change the host port mappings in [compose.yml](/home/mk/Projects/freelance/upwork/php_upload_script/docker/dev/compose.yml).

### MinIO presigned URLs do not load in the browser

The script auto-detects a host/IP for the public upload endpoint. If the generated presigned URLs are not reachable from your browser, rerun bootstrap with an explicit host:

```bash
# Plain HTTP (direct access on port 9000)
MINIO_PUBLIC_HOST=<server-ip> ./scripts/deploy-dev.sh --reset
```

**Running behind HTTPS (e.g. nginx on the host proxies `videophp.example.com`)?**

Pass the full `https://` URL. The script detects the scheme and sets `B2_PUBLIC_ENDPOINT=https://videophp.example.com` (no `:9000`), while keeping the internal `B2_ENDPOINT=http://minio:9000` for container-to-container traffic:

```bash
MINIO_PUBLIC_HOST=https://videophp.example.com ./scripts/deploy-dev.sh --reset
```

You also need to add a MinIO proxy block in the **host** nginx config so the public domain forwards upload requests to MinIO:

```nginx
# /etc/nginx/sites-available/videophp.example.com (inside the server {} block)
location /videosystem-dev/ {
    proxy_pass         http://127.0.0.1:9000/videosystem-dev/;
    proxy_set_header   Host              $host;
    proxy_set_header   X-Real-IP         $remote_addr;
    # Required for large file uploads
    client_max_body_size 8g;
    proxy_request_buffering off;
}
```

After adding the block: `nginx -t && systemctl reload nginx`.

### FFmpeg appears missing

Rebuild the PHP image:

```bash
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml build --no-cache php
```

### Integration tests skip immediately

Run them inside the Docker dev stack and pass the DB variables explicitly:

```bash
docker compose --env-file docker/dev/runtime/compose.env -f docker/dev/compose.yml exec -e DB_HOST=db -e DB_PORT=3306 -e DB_NAME=videosystem -e DB_USER=videosystem -e DB_PASS=videosystem php composer test:integration
```

### Reset Docker volumes

```bash
./scripts/deploy-dev.sh --reset
```

### Migration script stops with a manual backfill error

The standalone migration runner uses tracked migrations only. If the database already has app tables such as `videos`, `api_keys`, or `admin_users` but does not have `schema_migrations`, the script stops instead of guessing what already ran. In local development, reset the stack with `./scripts/deploy-dev.sh --reset` unless you intentionally need to preserve the existing data.
