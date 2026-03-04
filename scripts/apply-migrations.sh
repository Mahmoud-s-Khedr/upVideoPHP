#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
REPO_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)
COMPOSE_FILE="$REPO_ROOT/docker/dev/compose.yml"
RUNTIME_DIR="$REPO_ROOT/docker/dev/runtime"
APP_ENV="$RUNTIME_DIR/app.env"
COMPOSE_ENV="$RUNTIME_DIR/compose.env"
SCHEMA_MIGRATIONS_TABLE="schema_migrations"

TARGET=""
CONFIG_FILE=""
ENV_FILE=""
MYSQL_CNF=""

usage() {
    cat <<'EOF'
Usage:
  ./scripts/apply-migrations.sh --target dev
  ./scripts/apply-migrations.sh --target prod --config /absolute/path/to/prod.env
  ./scripts/apply-migrations.sh --target prod --env-file /absolute/path/to/.env

Options:
  --target <dev|prod>    Select the migration target environment.
  --config <path>        Production shell-style deploy config file.
  --env-file <path>      Absolute path to an existing app .env file.
  -h, --help             Show this help text.
EOF
}

cleanup() {
    if [[ -n "$MYSQL_CNF" && -f "$MYSQL_CNF" ]]; then
        rm -f "$MYSQL_CNF"
    fi
}

trap cleanup EXIT

compose() {
    docker compose --env-file "$COMPOSE_ENV" -f "$COMPOSE_FILE" "$@"
}

require_command() {
    local command_name="$1"
    if ! command -v "$command_name" >/dev/null 2>&1; then
        echo "Missing required command: $command_name" >&2
        exit 1
    fi
}

require_absolute_existing_file() {
    local flag_name="$1"
    local path="$2"

    if [[ "$path" != /* ]]; then
        echo "$flag_name must be an absolute path." >&2
        exit 1
    fi

    if [[ ! -f "$path" ]]; then
        echo "File not found: $path" >&2
        exit 1
    fi
}

ensure_required_env() {
    local source_label="$1"
    shift

    local key
    for key in "$@"; do
        if [[ -z "${!key:-}" ]]; then
            echo "Missing required $source_label key: $key" >&2
            exit 1
        fi
    done
}

sql_escape() {
    printf "%s" "$1" | sed "s/'/''/g"
}

load_shell_env_file() {
    local path="$1"

    unset DB_HOST DB_PORT DB_NAME DB_USER DB_PASS

    # shellcheck disable=SC1090
    set -a
    . "$path"
    set +a
}

load_prod_config() {
    load_shell_env_file "$1"
    DB_HOST="${DB_HOST:-127.0.0.1}"
    DB_PORT="${DB_PORT:-3306}"
    ensure_required_env "config" DB_NAME DB_USER DB_PASS
}

load_app_env_file() {
    load_shell_env_file "$1"
    DB_PORT="${DB_PORT:-3306}"
    ensure_required_env ".env" DB_HOST DB_NAME DB_USER DB_PASS
}

prepare_mysql_defaults_file() {
    MYSQL_CNF=$(mktemp)
    chmod 600 "$MYSQL_CNF"
    cat > "$MYSQL_CNF" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
EOF
}

mysql_query() {
    mysql --defaults-extra-file="$MYSQL_CNF" --batch --skip-column-names "$DB_NAME" -e "$1"
}

apply_migration_file() {
    local migration_path="$1"
    mysql --defaults-extra-file="$MYSQL_CNF" "$DB_NAME" < "$migration_path"
}

run_tracked_migrations() {
    local migrations_dir="$1"
    local db_name_sql
    local schema_table_exists
    local existing_app_tables
    local migration
    local migration_name
    local migration_name_sql
    local applied
    local -a migrations

    if [[ ! -d "$migrations_dir" ]]; then
        echo "Migrations directory not found: $migrations_dir" >&2
        exit 1
    fi

    shopt -s nullglob
    migrations=("$migrations_dir"/*.sql)
    shopt -u nullglob

    if [[ ${#migrations[@]} -eq 0 ]]; then
        echo "No migration files found in $migrations_dir" >&2
        exit 1
    fi

    db_name_sql=$(sql_escape "$DB_NAME")
    schema_table_exists=$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${db_name_sql}' AND table_name = '${SCHEMA_MIGRATIONS_TABLE}';")

    if [[ "$schema_table_exists" == "0" ]]; then
        existing_app_tables=$(mysql_query "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '${db_name_sql}' AND table_name IN ('videos', 'api_keys', 'admin_users');")
        if [[ "$existing_app_tables" != "0" ]]; then
            echo "Existing app schema detected without ${SCHEMA_MIGRATIONS_TABLE}. Manual migration backfill is required before applying tracked migrations." >&2
            exit 1
        fi

        mysql_query "CREATE TABLE IF NOT EXISTS ${SCHEMA_MIGRATIONS_TABLE} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            filename VARCHAR(255) NOT NULL,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_schema_migrations_filename (filename)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;"
    fi

    for migration in "${migrations[@]}"; do
        migration_name=$(basename "$migration")
        migration_name_sql=$(sql_escape "$migration_name")
        applied=$(mysql_query "SELECT COUNT(*) FROM ${SCHEMA_MIGRATIONS_TABLE} WHERE filename = '${migration_name_sql}';")

        if [[ "$applied" != "0" ]]; then
            echo "Skipping migration: $migration_name"
            continue
        fi

        echo "Running migration: $migration_name"
        apply_migration_file "$migration"
        mysql_query "INSERT INTO ${SCHEMA_MIGRATIONS_TABLE} (filename) VALUES ('${migration_name_sql}');"
    done
}

run_prod_migrations() {
    require_command mysql

    if [[ -n "$CONFIG_FILE" && -n "$ENV_FILE" ]]; then
        echo "Specify either --config or --env-file for --target prod, not both." >&2
        exit 1
    fi

    if [[ -z "$CONFIG_FILE" && -z "$ENV_FILE" ]]; then
        echo "--target prod requires exactly one of --config or --env-file." >&2
        exit 1
    fi

    if [[ -n "$CONFIG_FILE" ]]; then
        require_absolute_existing_file "--config" "$CONFIG_FILE"
        load_prod_config "$CONFIG_FILE"
    else
        require_absolute_existing_file "--env-file" "$ENV_FILE"
        load_app_env_file "$ENV_FILE"
    fi

    prepare_mysql_defaults_file
    run_tracked_migrations "$REPO_ROOT/database/migrations"
}

run_dev_migrations() {
    require_command docker

    if ! docker compose version >/dev/null 2>&1; then
        echo "Docker Compose v2 is required." >&2
        exit 1
    fi

    if [[ ! -f "$COMPOSE_ENV" || ! -f "$APP_ENV" ]]; then
        echo "Missing Docker dev runtime files. Run ./scripts/deploy-dev.sh first." >&2
        exit 1
    fi

    if ! compose exec -T db mysqladmin ping -h127.0.0.1 -uroot -pdevroot --silent >/dev/null 2>&1; then
        echo "The Docker dev database is not reachable. Start the dev stack before applying migrations." >&2
        exit 1
    fi

    compose run --rm -T php bash -lc 'cd /var/www/html && ./scripts/apply-migrations.sh --target prod --env-file /var/www/html/.env'
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --target)
            TARGET="${2:-}"
            shift 2
            ;;
        --config)
            CONFIG_FILE="${2:-}"
            shift 2
            ;;
        --env-file)
            ENV_FILE="${2:-}"
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

if [[ "$TARGET" != "dev" && "$TARGET" != "prod" ]]; then
    echo "--target is required and must be one of: dev, prod." >&2
    usage
    exit 1
fi

case "$TARGET" in
    dev)
        if [[ -n "$CONFIG_FILE" || -n "$ENV_FILE" ]]; then
            echo "--config and --env-file are not valid with --target dev." >&2
            exit 1
        fi
        run_dev_migrations
        ;;
    prod)
        run_prod_migrations
        ;;
esac
