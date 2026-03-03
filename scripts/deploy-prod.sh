#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'EOF'
Usage:
  sudo ./scripts/deploy-prod.sh --config /absolute/path/to/prod.env

The config file must be a shell-style KEY=value file.
EOF
}

require_root() {
    if [[ ${EUID:-$(id -u)} -ne 0 ]]; then
        echo "This script must run as root." >&2
        exit 1
    fi
}

require_ubuntu_2404() {
    if [[ ! -r /etc/os-release ]]; then
        echo "Cannot verify operating system." >&2
        exit 1
    fi

    # shellcheck disable=SC1091
    . /etc/os-release
    if [[ ${ID:-} != "ubuntu" || ${VERSION_ID:-} != "24.04" ]]; then
        echo "This script supports Ubuntu 24.04 only." >&2
        exit 1
    fi
}

require_command() {
    local command_name="$1"
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Missing required command: $command_name" >&2
        exit 1
    fi
}

PHP_SERIES="8.4"
SCHEMA_MIGRATIONS_TABLE="schema_migrations"

sql_escape() {
    printf "%s" "$1" | sed "s/'/''/g"
}

ensure_required_env() {
    local key
    for key in "$@"; do
        if [[ -z "${!key:-}" ]]; then
            echo "Missing required config key: $key" >&2
            exit 1
        fi
    done
}

validate_identifier() {
    local label="$1"
    local value="$2"
    if [[ ! "$value" =~ ^[A-Za-z0-9_]+$ ]]; then
        echo "$label must match ^[A-Za-z0-9_]+\$" >&2
        exit 1
    fi
}

install_composer() {
    if command -v composer >/dev/null 2>&1; then
        return
    fi

    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
    rm -f /tmp/composer-setup.php
}

mysql_query() {
    local tmp_cnf
    tmp_cnf=$(mktemp)
    chmod 600 "$tmp_cnf"
    printf '[client]\nuser=%s\npassword=%s\n' "$DB_USER" "$DB_PASS" > "$tmp_cnf"
    mysql --defaults-extra-file="$tmp_cnf" --host=127.0.0.1 --batch --skip-column-names "$DB_NAME" -e "$1"
    rm -f "$tmp_cnf"
}

wait_for_mysql() {
    local attempt
    for attempt in $(seq 1 60); do
        if mysqladmin ping --silent >/dev/null 2>&1; then
            return 0
        fi
        sleep 2
    done

    echo "Timed out waiting for MySQL to accept connections." >&2
    exit 1
}

update_ini_setting() {
    local file="$1"
    local key="$2"
    local value="$3"

    if grep -Eq "^[;[:space:]]*${key}[[:space:]]*=" "$file"; then
        sed -i -E "s#^[;[:space:]]*${key}[[:space:]]*=.*#${key} = ${value}#g" "$file"
    else
        printf '%s = %s\n' "$key" "$value" >> "$file"
    fi
}

write_env_file() {
    cat > "$APP_ROOT/.env" <<EOF
DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS

B2_KEY_ID=$B2_KEY_ID
B2_APP_KEY=$B2_APP_KEY
B2_BUCKET=$B2_BUCKET
B2_ENDPOINT=$B2_ENDPOINT
B2_REGION=$B2_REGION

STREAM_TOKEN_SECRET=$STREAM_TOKEN_SECRET
STREAM_TOKEN_TTL_SECONDS=$STREAM_TOKEN_TTL_SECONDS
EMBED_TOKEN_SECRET=$EMBED_TOKEN_SECRET
EMBED_TOKEN_TTL_SECONDS=$EMBED_TOKEN_TTL_SECONDS
KEY_ENCRYPTION_SECRET=$KEY_ENCRYPTION_SECRET

APP_BASE_URL=$APP_BASE_URL
TRUSTED_PROXIES=$TRUSTED_PROXIES
CORS_ALLOWED_ORIGIN=$CORS_ALLOWED_ORIGIN

FFMPEG_BIN=/usr/bin/ffmpeg
FFPROBE_BIN=/usr/bin/ffprobe
WORK_DIR=$WORK_DIR
MAX_UPLOAD_BYTES=$MAX_UPLOAD_BYTES
WORKER_POLL_INTERVAL=$WORKER_POLL_INTERVAL
STALE_JOB_TIMEOUT_MINUTES=$STALE_JOB_TIMEOUT_MINUTES
MIN_DISK_FREE_BYTES=$MIN_DISK_FREE_BYTES
EOF
    chown www-data:www-data "$APP_ROOT/.env"
    chmod 600 "$APP_ROOT/.env"
}

create_mysql_database() {
    local db_name_sql user_sql pass_sql
    validate_identifier "DB_NAME" "$DB_NAME"
    validate_identifier "DB_USER" "$DB_USER"
    db_name_sql="$DB_NAME"
    user_sql=$(sql_escape "$DB_USER")
    pass_sql=$(sql_escape "$DB_PASS")

    mysql <<EOF
CREATE DATABASE IF NOT EXISTS \`$db_name_sql\`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$user_sql'@'127.0.0.1' IDENTIFIED BY '$pass_sql';
ALTER USER '$user_sql'@'127.0.0.1' IDENTIFIED BY '$pass_sql';
GRANT ALL PRIVILEGES ON \`$db_name_sql\`.* TO '$user_sql'@'127.0.0.1';
FLUSH PRIVILEGES;
EOF
}

run_migrations() {
    local migration
    local schema_table_exists
    local existing_app_tables
    local migration_name
    local migration_name_sql
    local applied

    schema_table_exists=$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}' AND table_name = '${SCHEMA_MIGRATIONS_TABLE}';")
    if [[ "$schema_table_exists" == "0" ]]; then
        existing_app_tables=$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${DB_NAME}' AND table_name IN ('videos', 'api_keys', 'admin_users');")
        if [[ "$existing_app_tables" != "0" ]]; then
            echo "Existing app schema detected without ${SCHEMA_MIGRATIONS_TABLE}. Manual migration backfill is required before using deploy-prod.sh for reruns." >&2
            exit 1
        fi

        mysql_query "CREATE TABLE IF NOT EXISTS ${SCHEMA_MIGRATIONS_TABLE} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schema_migrations_filename (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    fi

    for migration in "$APP_ROOT"/database/migrations/*.sql; do
        migration_name=$(basename "$migration")
        migration_name_sql=$(sql_escape "$migration_name")
        applied=$(mysql_query "SELECT COUNT(*) FROM ${SCHEMA_MIGRATIONS_TABLE} WHERE filename = '${migration_name_sql}';")
        if [[ "$applied" != "0" ]]; then
            echo "Skipping migration: $migration_name"
            continue
        fi

        echo "Running migration: $migration_name"
        mysql --host=127.0.0.1 --user="$DB_USER" --password="$DB_PASS" "$DB_NAME" < "$migration"
        mysql_query "INSERT INTO ${SCHEMA_MIGRATIONS_TABLE} (filename) VALUES ('${migration_name_sql}');"
    done
}

bootstrap_admin_and_api_key() {
    export ADMIN_USERNAME ADMIN_PASSWORD INITIAL_API_KEY_NAME INITIAL_API_KEY_TOKEN APP_ROOT

    sudo -u www-data php <<'PHP'
<?php
declare(strict_types=1);

require getenv('APP_ROOT') . '/vendor/autoload.php';

Dotenv\Dotenv::createImmutable(getenv('APP_ROOT'))->load();

$pdo = new PDO(
    'mysql:host=' . $_ENV['DB_HOST'] . ';port=' . ($_ENV['DB_PORT'] ?? '3306') . ';dbname=' . $_ENV['DB_NAME'] . ';charset=utf8mb4',
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);

$adminUsername = getenv('ADMIN_USERNAME');
$adminPassword = getenv('ADMIN_PASSWORD');
$apiKeyName    = getenv('INITIAL_API_KEY_NAME');
$apiKeyToken   = getenv('INITIAL_API_KEY_TOKEN');

$adminHash = password_hash($adminPassword, PASSWORD_BCRYPT);
$apiHash   = password_hash($apiKeyToken, PASSWORD_BCRYPT);

$existingAdmin = $pdo->prepare('SELECT id FROM admin_users WHERE username = ? LIMIT 1');
$existingAdmin->execute([$adminUsername]);
$adminRow = $existingAdmin->fetch();

if ($adminRow) {
    $pdo->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')
        ->execute([$adminHash, $adminRow['id']]);
    echo "Updated admin user {$adminUsername}\n";
} else {
    $pdo->prepare('INSERT INTO admin_users (username, password_hash) VALUES (?, ?)')
        ->execute([$adminUsername, $adminHash]);
    echo "Created admin user {$adminUsername}\n";
}

$existingKey = $pdo->prepare('SELECT id FROM api_keys WHERE name = ? LIMIT 1');
$existingKey->execute([$apiKeyName]);
$apiRow = $existingKey->fetch();

if ($apiRow) {
    $pdo->prepare('UPDATE api_keys SET key_hash = ?, can_upload = 1, can_stream = 1, revoked_at = NULL WHERE id = ?')
        ->execute([$apiHash, $apiRow['id']]);
    echo "Updated API key {$apiKeyName}\n";
} else {
    $pdo->prepare('INSERT INTO api_keys (name, key_hash, can_upload, can_stream) VALUES (?, ?, 1, 1)')
        ->execute([$apiKeyName, $apiHash]);
    echo "Created API key {$apiKeyName}\n";
}
PHP
}

write_bootstrap_nginx_config() {
    cat > /etc/nginx/sites-available/videosystem.conf <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;

    root $APP_ROOT/public;
    index index.php;

    location ^~ /.well-known/acme-challenge/ {
        root $APP_ROOT/public;
        default_type "text/plain";
    }

    location = /api/upload {
        fastcgi_pass unix:/run/php/php${PHP_SERIES}-fpm-upload.sock;
        client_max_body_size ${UPLOAD_LIMIT_MB}M;
        client_body_timeout 300s;
        fastcgi_read_timeout 3600s;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_SERIES}-fpm.sock;
        fastcgi_read_timeout 60s;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }

    location ~ /\. {
        deny all;
    }
}
EOF
}

write_final_nginx_config() {
    cat > /etc/nginx/sites-available/videosystem.conf <<EOF
map \$request_uri \$request_no_token {
    ~^(.*)\?(.*)token=[^&]*(.*)  \$1?\$2\$3;
    default                       \$request_uri;
}

log_format safe_log '\$remote_addr - \$remote_user [\$time_local] '
                    '"\$request_method \$request_no_token \$server_protocol" \$status \$body_bytes_sent '
                    '"\$http_referer" "\$http_user_agent"';

server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $DOMAIN;

    ssl_certificate     /etc/letsencrypt/live/$DOMAIN/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;

    root  $APP_ROOT/public;
    index index.php;

    location = /api/upload {
        fastcgi_pass unix:/run/php/php${PHP_SERIES}-fpm-upload.sock;
        client_max_body_size ${UPLOAD_LIMIT_MB}M;
        client_body_timeout 300s;
        fastcgi_read_timeout 3600s;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root/index.php;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }

    location / {
        try_files \$uri \$uri/ /index.php\$is_args\$args;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php${PHP_SERIES}-fpm.sock;
        fastcgi_read_timeout 60s;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_param PATH_INFO \$fastcgi_path_info;
    }

    location ~ /\. {
        deny all;
    }

    access_log /var/log/nginx/videosystem.access.log safe_log;
}

server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN;
    return 301 https://\$host\$request_uri;
}
EOF
}

prepare_service_configs() {
    install -m 0644 "$APP_ROOT/deploy/php-fpm/upload.conf" /etc/php/${PHP_SERIES}/fpm/pool.d/upload.conf
    install -m 0644 "$APP_ROOT/deploy/supervisor/video-worker.conf" /etc/supervisor/conf.d/video-worker.conf
    install -m 0644 "$APP_ROOT/deploy/supervisor/job-reaper.conf" /etc/supervisor/conf.d/job-reaper.conf

    sed -i -E "s#^numprocs=.*#numprocs=$WORKER_COUNT#g" /etc/supervisor/conf.d/video-worker.conf

    sed -i -E "s#php8\.2#php${PHP_SERIES}#g" /etc/php/${PHP_SERIES}/fpm/pool.d/upload.conf
    sed -i -E "s#php_admin_value\\[upload_max_filesize\\] = .*#php_admin_value[upload_max_filesize] = ${UPLOAD_LIMIT_MB}M#g" /etc/php/${PHP_SERIES}/fpm/pool.d/upload.conf
    sed -i -E "s#php_admin_value\\[post_max_size\\]       = .*#php_admin_value[post_max_size]       = ${UPLOAD_LIMIT_MB}M#g" /etc/php/${PHP_SERIES}/fpm/pool.d/upload.conf
}

reload_services() {
    systemctl enable --now mysql nginx supervisor "php${PHP_SERIES}-fpm"
    systemctl is-active --quiet "php${PHP_SERIES}-fpm" || {
        echo "PHP-FPM failed to start." >&2
        exit 1
    }
    systemctl is-active --quiet nginx || {
        echo "Nginx failed to start." >&2
        exit 1
    }
    systemctl is-active --quiet supervisor || {
        echo "Supervisor failed to start." >&2
        exit 1
    }
    systemctl reload "php${PHP_SERIES}-fpm"
    nginx -t
    systemctl reload nginx
    supervisorctl reread
    supervisorctl update
}

verify_deployment() {
    echo "Running deployment verification..."
    nginx -t || {
        echo "Verification failed: nginx config test." >&2
        exit 1
    }
    systemctl is-active --quiet "php${PHP_SERIES}-fpm" || {
        echo "Verification failed: php${PHP_SERIES}-fpm is not active." >&2
        exit 1
    }
    systemctl is-active --quiet mysql || {
        echo "Verification failed: mysql is not active." >&2
        exit 1
    }
    supervisorctl status || {
        echo "Verification failed: supervisorctl status." >&2
        exit 1
    }
    curl -fsS "https://$DOMAIN/health" || {
        echo "Verification failed: https://$DOMAIN/health" >&2
        exit 1
    }
}

CONFIG_FILE=""
while [[ $# -gt 0 ]]; do
    case "$1" in
        --config)
            CONFIG_FILE="${2:-}"
            shift 2
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

if [[ -z "$CONFIG_FILE" ]]; then
    usage
    exit 1
fi

if [[ "$CONFIG_FILE" != /* ]]; then
    echo "--config must be an absolute path." >&2
    exit 1
fi

if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "Config file not found: $CONFIG_FILE" >&2
    exit 1
fi

require_root
require_ubuntu_2404
require_command sed

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)

# shellcheck disable=SC1090
source "$CONFIG_FILE"

APP_ROOT="${APP_ROOT:-/var/www/html}"
WORK_DIR="${WORK_DIR:-/var/video-work}"
WORKER_COUNT="${WORKER_COUNT:-2}"
CORS_ALLOWED_ORIGIN="${CORS_ALLOWED_ORIGIN:-}"
TRUSTED_PROXIES="${TRUSTED_PROXIES:-}"
STREAM_TOKEN_TTL_SECONDS="${STREAM_TOKEN_TTL_SECONDS:-14400}"
EMBED_TOKEN_TTL_SECONDS="${EMBED_TOKEN_TTL_SECONDS:-14400}"
MAX_UPLOAD_BYTES="${MAX_UPLOAD_BYTES:-8589934592}"
WORKER_POLL_INTERVAL="${WORKER_POLL_INTERVAL:-5}"
STALE_JOB_TIMEOUT_MINUTES="${STALE_JOB_TIMEOUT_MINUTES:-30}"
MIN_DISK_FREE_BYTES="${MIN_DISK_FREE_BYTES:-21474836480}"

ensure_required_env \
    APP_BASE_URL \
    LETSENCRYPT_EMAIL \
    DB_NAME \
    DB_USER \
    DB_PASS \
    B2_KEY_ID \
    B2_APP_KEY \
    B2_BUCKET \
    B2_ENDPOINT \
    B2_REGION \
    ADMIN_USERNAME \
    ADMIN_PASSWORD \
    INITIAL_API_KEY_NAME \
    INITIAL_API_KEY_TOKEN

validate_identifier "DB_NAME" "$DB_NAME"
validate_identifier "DB_USER" "$DB_USER"

if [[ "$APP_BASE_URL" != https://* ]]; then
    echo "APP_BASE_URL must start with https:// for production." >&2
    exit 1
fi

DOMAIN=$(printf '%s' "$APP_BASE_URL" | sed -E 's#^https://([^/:]+).*#\1#')
if [[ -z "$DOMAIN" || "$DOMAIN" == "$APP_BASE_URL" ]]; then
    echo "Could not derive domain from APP_BASE_URL." >&2
    exit 1
fi

STREAM_TOKEN_SECRET="${STREAM_TOKEN_SECRET:-$(openssl rand -base64 48)}"
EMBED_TOKEN_SECRET="${EMBED_TOKEN_SECRET:-$(openssl rand -base64 48)}"
KEY_ENCRYPTION_SECRET="${KEY_ENCRYPTION_SECRET:-$(openssl rand -hex 32)}"

UPLOAD_LIMIT_MB=$(( (MAX_UPLOAD_BYTES + 1048575) / 1048576 ))

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y software-properties-common curl git unzip rsync ca-certificates gnupg lsb-release
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
    nginx \
    mysql-server \
    ffmpeg \
    supervisor \
    certbot \
    php${PHP_SERIES}-cli \
    php${PHP_SERIES}-fpm \
    php${PHP_SERIES}-curl \
    php${PHP_SERIES}-mbstring \
    php${PHP_SERIES}-mysql \
    php${PHP_SERIES}-xml

install_composer

mkdir -p "$APP_ROOT"
rsync -a --delete \
    --exclude '.git' \
    --exclude '.env' \
    --exclude 'vendor' \
    "$REPO_ROOT"/ "$APP_ROOT"/

composer install --no-dev --optimize-autoloader --working-dir="$APP_ROOT"

mkdir -p "$WORK_DIR/incoming" "$WORK_DIR/processing"
chown -R www-data:www-data "$APP_ROOT" "$WORK_DIR"

write_env_file

systemctl enable --now mysql
wait_for_mysql
create_mysql_database
run_migrations
bootstrap_admin_and_api_key

update_ini_setting /etc/php/${PHP_SERIES}/fpm/php.ini upload_max_filesize "${UPLOAD_LIMIT_MB}M"
update_ini_setting /etc/php/${PHP_SERIES}/fpm/php.ini post_max_size "${UPLOAD_LIMIT_MB}M"
update_ini_setting /etc/php/${PHP_SERIES}/fpm/php.ini memory_limit "512M"

prepare_service_configs
write_bootstrap_nginx_config
ln -sf /etc/nginx/sites-available/videosystem.conf /etc/nginx/sites-enabled/videosystem.conf
rm -f /etc/nginx/sites-enabled/default

systemctl enable --now nginx "php${PHP_SERIES}-fpm" supervisor
systemctl is-active --quiet "php${PHP_SERIES}-fpm" || {
    echo "PHP-FPM failed to start before TLS bootstrap." >&2
    exit 1
}
systemctl is-active --quiet nginx || {
    echo "Nginx failed to start before TLS bootstrap." >&2
    exit 1
}
nginx -t
systemctl reload nginx
systemctl reload "php${PHP_SERIES}-fpm"

if ! certbot certonly --webroot \
    --webroot-path "$APP_ROOT/public" \
    --non-interactive \
    --agree-tos \
    --email "$LETSENCRYPT_EMAIL" \
    -d "$DOMAIN"; then
    echo "Certbot failed for $DOMAIN using webroot $APP_ROOT/public. The bootstrap HTTP Nginx config has been left in place." >&2
    exit 1
fi

write_final_nginx_config
reload_services
verify_deployment

cat <<EOF

Production deployment complete.
Domain: $DOMAIN
Admin user: $ADMIN_USERNAME
Initial API key name: $INITIAL_API_KEY_NAME

Store this API token securely:
$INITIAL_API_KEY_TOKEN
EOF
