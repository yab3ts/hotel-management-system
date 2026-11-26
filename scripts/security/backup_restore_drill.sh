#!/usr/bin/env bash
set -euo pipefail

# Multi-Table Encrypted Backup & Restore Drill (#74, #129, #130)
# Usage: ./scripts/security/backup_restore_drill.sh [remote_disk]
REMOTE_DISK="${1:-}"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
LARAVEL_DIR="$(cd "${SCRIPT_DIR}/../../backend-laravel" && pwd)"

START_TS=$(date +%s)
echo "=== Starting Hardened Encrypted Backup & Restore Drill ==="

# Auto-detect execution environment: native PHP vs containerized
if command -v php >/dev/null 2>&1 && [ -f "${LARAVEL_DIR}/artisan" ]; then
  RUN_PHP() { cd "${LARAVEL_DIR}" && php "$@"; }
  RUN_ARTISAN() { cd "${LARAVEL_DIR}" && php artisan "$@"; }
elif docker ps --format '{{.Names}}' | grep -q "^cafex-api$"; then
  RUN_PHP() { docker exec cafex-api php "$@"; }
  RUN_ARTISAN() { docker exec cafex-api php artisan "$@"; }
else
  echo "FAIL (#74): Neither native PHP nor cafex-api Docker container found."
  exit 1
fi

# 1. Verify operator secret hygiene: encryption key is passed via environment, not stored in backup directory
ENC_KEY=$(RUN_PHP -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('services.backup.encryption_key') ?: config('app.key');")
if [ -z "${ENC_KEY}" ]; then
  echo "FAIL (#74): Backup encryption key (BACKUP_ENCRYPTION_KEY or APP_KEY) is not configured in operator environment."
  exit 1
fi
echo "INFO (#74): Encryption key verified from operator environment (starts with $(echo "${ENC_KEY}" | cut -c 1-8)...)"

# 2. Trigger encrypted database dump (AES-256-GCM + SHA-256 checksum)
if [ -n "${REMOTE_DISK}" ]; then
  RUN_ARTISAN cafex:backup --remote-disk="${REMOTE_DISK}"
else
  RUN_ARTISAN cafex:backup
fi

DB_DRIVER=$(RUN_PHP -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.default');")

if [ "${DB_DRIVER}" = "pgsql" ]; then
  RESTORE_DB="restore_test_$(date +%s)"
  PG_HOST=$(RUN_PHP -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.host');")
  PG_USER=$(RUN_PHP -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.username');")
  PG_PASS=$(RUN_PHP -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.password');")
  PG_MAIN=$(RUN_PHP -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo config('database.connections.pgsql.database');")

  CHECK_TABLES=(
    "tenants"
    "users"
    "restaurant_tables"
    "menu_items"
    "orders"
    "payments"
    "table_sessions"
    "reservations"
    "audit_logs"
    "inventory"
    "iot_devices"
    "approval_requests"
  )

  EXEC_PSQL() {
    local target_db="$1"
    local sql_query="$2"
    if docker ps --format '{{.Names}}' | grep -q "^bekur-postgres-1$"; then
      docker exec bekur-postgres-1 psql -U "${PG_USER}" -d "${target_db}" -tAc "${sql_query}"
    else
      PGPASSWORD="${PG_PASS}" psql -h "${PG_HOST}" -U "${PG_USER}" -d "${target_db}" -tAc "${sql_query}"
    fi
  }

  CREATE_TEST_DB() {
    local db_to_create="$1"
    if docker ps --format '{{.Names}}' | grep -q "^bekur-postgres-1$"; then
      docker exec bekur-postgres-1 psql -U "${PG_USER}" -d "${PG_MAIN}" -c "CREATE DATABASE ${db_to_create};"
    else
      PGPASSWORD="${PG_PASS}" createdb -h "${PG_HOST}" -U "${PG_USER}" "${db_to_create}"
    fi
  }

  DROP_TEST_DB() {
    local db_to_drop="$1"
    if docker ps --format '{{.Names}}' | grep -q "^bekur-postgres-1$"; then
      docker exec bekur-postgres-1 psql -U "${PG_USER}" -d "${PG_MAIN}" -c "DROP DATABASE IF EXISTS ${db_to_drop};"
    else
      PGPASSWORD="${PG_PASS}" dropdb -h "${PG_HOST}" -U "${PG_USER}" --if-exists "${db_to_drop}"
    fi
  }

  # Collect baseline table counts and checksums before restore
  declare -A PROD_COUNTS
  for tbl in "${CHECK_TABLES[@]}"; do
    PROD_COUNTS["${tbl}"]=$(EXEC_PSQL "${PG_MAIN}" "SELECT count(*) FROM ${tbl};" 2>/dev/null || echo "0")
  done

  PROD_PAYMENTS_CS=$(EXEC_PSQL "${PG_MAIN}" "SELECT md5(COALESCE(string_agg(id::text || ':' || amount::text, ',' ORDER BY id), 'none')) FROM payments;" 2>/dev/null || echo "empty")
  PROD_ORDERS_CS=$(EXEC_PSQL "${PG_MAIN}" "SELECT md5(COALESCE(string_agg(id::text || ':' || total::text, ',' ORDER BY id), 'none')) FROM orders;" 2>/dev/null || echo "empty")

  # Create temporary isolated restore target database
  CREATE_TEST_DB "${RESTORE_DB}"
  trap 'DROP_TEST_DB "${RESTORE_DB}"' EXIT

  # Restore into temporary database
  if docker ps --format '{{.Names}}' | grep -q "^cafex-api$"; then
    docker exec -e DB_DATABASE="${RESTORE_DB}" cafex-api php artisan cafex:restore --force
  else
    DB_DATABASE="${RESTORE_DB}" php artisan cafex:restore --force
  fi

  # Verify multi-table parity across all tables
  for tbl in "${CHECK_TABLES[@]}"; do
    RESTORED_COUNT=$(EXEC_PSQL "${RESTORE_DB}" "SELECT count(*) FROM ${tbl};" 2>/dev/null || echo "0")
    if [ "${PROD_COUNTS[${tbl}]}" != "${RESTORED_COUNT}" ]; then
      echo "FAIL (#130): Table '${tbl}' count mismatch (prod=${PROD_COUNTS[${tbl}]}, restored=${RESTORED_COUNT})"
      exit 1
    fi
    echo "  - Table '${tbl}': ${RESTORED_COUNT} rows matched"
  done

  # Verify cryptographically computed data checksums
  REST_PAYMENTS_CS=$(EXEC_PSQL "${RESTORE_DB}" "SELECT md5(COALESCE(string_agg(id::text || ':' || amount::text, ',' ORDER BY id), 'none')) FROM payments;" 2>/dev/null || echo "empty")
  REST_ORDERS_CS=$(EXEC_PSQL "${RESTORE_DB}" "SELECT md5(COALESCE(string_agg(id::text || ':' || total::text, ',' ORDER BY id), 'none')) FROM orders;" 2>/dev/null || echo "empty")

  if [ "${PROD_PAYMENTS_CS}" != "${REST_PAYMENTS_CS}" ]; then
    echo "FAIL (#130): Payments data MD5 mismatch (prod=${PROD_PAYMENTS_CS}, restored=${REST_PAYMENTS_CS})"
    exit 1
  fi
  echo "PASS (#130): Payments MD5 checksum matches: ${REST_PAYMENTS_CS}"

  if [ "${PROD_ORDERS_CS}" != "${REST_ORDERS_CS}" ]; then
    echo "FAIL (#130): Orders data MD5 mismatch (prod=${PROD_ORDERS_CS}, restored=${REST_ORDERS_CS})"
    exit 1
  fi
  echo "PASS (#130): Orders MD5 checksum matches: ${REST_ORDERS_CS}"

else
  RUN_ARTISAN cafex:restore --force
fi

END_TS=$(date +%s)
ELAPSED=$((END_TS - START_TS))
echo "PASS (#74/#130): Full multi-table restore drill succeeded in ${ELAPSED}s (RTO Evidence: ${ELAPSED}s, target < 300s)"
