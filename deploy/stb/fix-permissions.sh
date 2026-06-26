#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/agnishopbjm}"
BACKEND_DIR="${BACKEND_DIR:-$APP_DIR/backend}"
RUN_USER="${RUN_USER:-www-data}"
RUN_GROUP="${RUN_GROUP:-www-data}"
CLEAR_CACHE_DATA="${CLEAR_CACHE_DATA:-true}"

if [ ! -d "$BACKEND_DIR" ]; then
  echo "Backend directory not found: $BACKEND_DIR" >&2
  exit 1
fi

cd "$BACKEND_DIR"

if [ "$CLEAR_CACHE_DATA" = "true" ]; then
  rm -rf storage/framework/cache/data
fi

mkdir -p \
  storage/framework/cache/data \
  storage/framework/sessions \
  storage/framework/views \
  storage/logs \
  bootstrap/cache

chown -R "$RUN_USER:$RUN_GROUP" storage bootstrap/cache
find storage bootstrap/cache -type d -exec chmod 2775 {} \;
find storage/framework storage/logs bootstrap/cache -type f -exec chmod 664 {} \;

echo "Fixed Laravel writable permissions for $BACKEND_DIR as $RUN_USER:$RUN_GROUP"
