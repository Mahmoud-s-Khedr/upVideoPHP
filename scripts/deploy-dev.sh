#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)
COMPOSE_FILE="$REPO_ROOT/docker/dev/compose.yml"
RUNTIME_DIR="$REPO_ROOT/docker/dev/runtime"
APP_ENV="$RUNTIME_DIR/app.env"
COMPOSE_ENV="$RUNTIME_DIR/compose.env"

usage() {
    cat <<'EOF'
Usage:
  ./scripts/deploy-dev.sh
  ./scripts/deploy-dev.sh --reset

Options:
  --reset    Recreate the Docker dev environment from scratch.

Optional override:
  MINIO_PUBLIC_HOST=<host-or-ip> ./scripts/deploy-dev.sh
EOF
}

compose() {
    docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" "$@"
}

require_command() {
    local name="$1"
    if ! command -v "$name" >/dev/null 2>&1; then
        echo "Missing required command: $name" >&2
        exit 1
    fi
}

detect_minio_public_host() {
    if [[ -n "${MINIO_PUBLIC_HOST:-}" ]]; then
        printf '%s\n' "$MINIO_PUBLIC_HOST"
        return
    fi

    case "$(uname -s)" in
        Darwin|MINGW*|MSYS*|CYGWIN*)
            printf 'host.docker.internal\n'
            return
            ;;
    esac

    local bridge_gateway
    bridge_gateway=$(docker network inspect bridge -f '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || true)
    if [[ -n "$bridge_gateway" && "$bridge_gateway" != "<no value>" ]]; then
        printf '%s\n' "$bridge_gateway"
        return
    fi

    local host_ip
    host_ip=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '/src/ {for (i=1; i<=NF; i++) if ($i == "src") {print $(i + 1); exit}}')
    if [[ -n "$host_ip" ]]; then
        printf '%s\n' "$host_ip"
        return
    fi

    printf '127.0.0.1\n'
}

wait_for_mysql() {
    local attempt
    for attempt in $(seq 1 60); do
        if compose exec -T db mysqladmin ping -h127.0.0.1 -uroot -pdevroot --silent >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done

    echo "Timed out waiting for MySQL." >&2
    exit 1
}

wait_for_minio() {
    local attempt
    for attempt in $(seq 1 60); do
        if curl -fsS http://127.0.0.1:9000/minio/health/live >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done

    echo "Timed out waiting for MinIO." >&2
    exit 1
}

wait_for_http() {
    local attempt
    for attempt in $(seq 1 60); do
        if curl -fsS http://127.0.0.1:8080/health >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done

    echo "Timed out waiting for http://127.0.0.1:8080/health." >&2
    echo "Inspect logs with:" >&2
    echo "  docker compose --env-file $COMPOSE_ENV -f $COMPOSE_FILE logs -f nginx php worker reaper db minio" >&2
    exit 1
}

seed_dev_data() {
    compose run --rm -T php php <<'PHP'
<?php
declare(strict_types=1);

require "/var/www/html/vendor/autoload.php";
Dotenv\Dotenv::createImmutable("/var/www/html")->load();

$pdo = new PDO(
    "mysql:host=" . $_ENV["DB_HOST"] . ";port=" . ($_ENV["DB_PORT"] ?? "3306") . ";dbname=" . $_ENV["DB_NAME"] . ";charset=utf8mb4",
    $_ENV["DB_USER"],
    $_ENV["DB_PASS"],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$adminUsername = "admin";
$adminPassword = "admin123";
$adminHash = password_hash($adminPassword, PASSWORD_BCRYPT);

$adminStmt = $pdo->prepare("SELECT id FROM admin_users WHERE username = ? LIMIT 1");
$adminStmt->execute([$adminUsername]);
$admin = $adminStmt->fetch();
if ($admin) {
    $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")->execute([$adminHash, $admin["id"]]);
} else {
    $pdo->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)")->execute([$adminUsername, $adminHash]);
}

$keys = [
    ["name" => "dev-full-access-local", "token" => "dev-full-access-local", "can_upload" => 1, "can_stream" => 1],
    ["name" => "dev-stream-only-local", "token" => "dev-stream-only-local", "can_upload" => 0, "can_stream" => 1],
];

foreach ($keys as $key) {
    $hash = password_hash($key["token"], PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("SELECT id FROM api_keys WHERE name = ? LIMIT 1");
    $stmt->execute([$key["name"]]);
    $row = $stmt->fetch();

    if ($row) {
        $pdo->prepare("UPDATE api_keys SET key_hash = ?, can_upload = ?, can_stream = ?, revoked_at = NULL WHERE id = ?")
            ->execute([$hash, $key["can_upload"], $key["can_stream"], $row["id"]]);
    } else {
        $pdo->prepare("INSERT INTO api_keys (name, key_hash, can_upload, can_stream) VALUES (?, ?, ?, ?)")
            ->execute([$key["name"], $hash, $key["can_upload"], $key["can_stream"]]);
    }
}
PHP
}

run_migrations() {
    compose run --rm php bash -lc '
        set -euo pipefail
        set -a
        . /var/www/html/.env
        set +a

        mysql_query() {
            mysql --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" --batch --skip-column-names "$DB_NAME" -e "$1"
        }

        if [[ "$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '\''$DB_NAME'\'' AND table_name = '\''schema_migrations'\'';")" == "0" ]]; then
            mysql_query "CREATE TABLE IF NOT EXISTS schema_migrations (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                filename VARCHAR(255) NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_schema_migrations_filename (filename)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
        fi

        for migration in database/migrations/*.sql; do
            migration_name=$(basename "$migration")
            if [[ "$(mysql_query "SELECT COUNT(*) FROM schema_migrations WHERE filename = '\''$migration_name'\'';")" != "0" ]]; then
                echo "Skipping migration: $migration_name"
                continue
            fi

            echo "Running migration: $migration_name"
            mysql --host="$DB_HOST" --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" < "$migration"
            mysql_query "INSERT INTO schema_migrations (filename) VALUES ('\''$migration_name'\'');"
        done
    '
}

write_runtime_files() {
    mkdir -p "$RUNTIME_DIR"
    mkdir -p "$RUNTIME_DIR/video-work/incoming" "$RUNTIME_DIR/video-work/processing"

    MINIO_HOST=$(detect_minio_public_host)
    LOCAL_UID=$(id -u)
    LOCAL_GID=$(id -g)

    cat > "$COMPOSE_ENV" <<EOF
COMPOSE_PROJECT_NAME=videosystem-dev
REPO_ROOT=$REPO_ROOT
RUNTIME_DIR=$RUNTIME_DIR
MINIO_PUBLIC_HOST=$MINIO_HOST
LOCAL_UID=$LOCAL_UID
LOCAL_GID=$LOCAL_GID
EOF

    cat > "$APP_ENV" <<EOF
DB_HOST=db
DB_PORT=3306
DB_NAME=videosystem
DB_USER=videosystem
DB_PASS=videosystem

B2_KEY_ID=minioadmin
B2_APP_KEY=minioadmin123
B2_BUCKET=videosystem-dev
B2_ENDPOINT=http://$MINIO_HOST:9000
B2_REGION=us-east-1
B2_UPLOAD_PRESIGN_TTL_SECONDS=3600

STREAM_TOKEN_SECRET=dev_stream_token_secret_for_local_stack_only_123456789
STREAM_TOKEN_TTL_SECONDS=14400
EMBED_TOKEN_SECRET=dev_embed_token_secret_for_local_stack_only_123456789
EMBED_TOKEN_TTL_SECONDS=14400
KEY_ENCRYPTION_SECRET=0102030405060708090a0b0c0d0e0f101112131415161718191a1b1c1d1e1f20

APP_BASE_URL=http://localhost:8080
TRUSTED_PROXIES=
CORS_ALLOWED_ORIGIN=http://localhost:8080

FFMPEG_BIN=/usr/bin/ffmpeg
FFPROBE_BIN=/usr/bin/ffprobe
WORK_DIR=/var/video-work
MAX_UPLOAD_BYTES=8589934592
WORKER_POLL_INTERVAL=5
STALE_JOB_TIMEOUT_MINUTES=30
MIN_DISK_FREE_BYTES=2147483648
EOF
}

reset_stack() {
    if [[ -f "$COMPOSE_ENV" ]]; then
        compose down -v --remove-orphans || true
    else
        REPO_ROOT="$REPO_ROOT" \
        RUNTIME_DIR="$RUNTIME_DIR" \
        LOCAL_UID="$(id -u)" \
        LOCAL_GID="$(id -g)" \
        docker compose -f "$COMPOSE_FILE" down -v --remove-orphans || true
    fi

    rm -f "$APP_ENV" "$COMPOSE_ENV"
}

RESET=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --reset)
            RESET=1
            shift
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            echo "Unknown argument: $1" >&2
            usage
            exit 1
            ;;
    esac
done

require_command docker
require_command curl

if ! docker compose version >/dev/null 2>&1; then
    echo "Docker Compose v2 is required." >&2
    exit 1
fi

if [[ $RESET -eq 1 ]]; then
    reset_stack
fi

write_runtime_files

compose build php
compose up -d db minio

wait_for_mysql
wait_for_minio

compose run --rm minio-init
compose run --rm php bash -lc 'composer install'
run_migrations
seed_dev_data
compose up -d php worker reaper nginx
wait_for_http

cat <<EOF

Dev environment is ready.

App URL:              http://localhost:8080
Health check:         http://localhost:8080/health
MinIO API:            http://localhost:9000
MinIO Console:        http://localhost:9001
MinIO public host:    $MINIO_HOST

Admin login:
  username: admin
  password: admin123

API keys:
  upload+stream: dev-full-access-local
  stream-only:   dev-stream-only-local

Common commands:
  docker compose --env-file $COMPOSE_ENV -f $COMPOSE_FILE logs -f
  docker compose --env-file $COMPOSE_ENV -f $COMPOSE_FILE exec -e DB_HOST=db -e DB_PORT=3306 -e DB_NAME=videosystem -e DB_USER=videosystem -e DB_PASS=videosystem php composer test:integration
  ./scripts/deploy-dev.sh --reset
EOF
