#!/usr/bin/env bash
set -euo pipefail

SESSION_DIR="${SESSION_SAVE_PATH:-/var/www/html/.sessions}"

if [[ "${TAHSIN_STAGING_ONLY:-0}" == "1" ]]; then
    if [[ "${APP_ENV:-}" != "staging" || ! "${DB_DATABASE:-}" =~ ^tahsin_test_autosave_[a-z0-9_]+$ ]]; then
        echo "Staging-only image requires APP_ENV=staging and a synthetic tahsin_test_autosave_* database." >&2
        exit 64
    fi
    if [[ "${TAHSIN_NEXT_WRITE_ENABLED:-0}" == "1" ]]; then
        echo "Go writer is disabled in the Coolify staging compose image." >&2
        exit 64
    fi
fi

mkdir -p "$SESSION_DIR"
chown -R www-data:www-data "$SESSION_DIR" || true
chmod 700 "$SESSION_DIR" || true

exec "$@"
