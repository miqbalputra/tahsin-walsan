#!/usr/bin/env bash
set -euo pipefail

SESSION_DIR="${SESSION_SAVE_PATH:-/var/www/html/.sessions}"

mkdir -p "$SESSION_DIR"
chown -R www-data:www-data "$SESSION_DIR" || true
chmod 700 "$SESSION_DIR" || true

exec "$@"
