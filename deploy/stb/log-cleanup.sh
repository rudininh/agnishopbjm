#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/agnishopbjm}"
BACKEND_DIR="${BACKEND_DIR:-$APP_DIR/backend}"
LOG_DIR="${LOG_DIR:-$BACKEND_DIR/storage/logs}"
RETENTION_DAYS="${RETENTION_DAYS:-7}"

if [ ! -d "$LOG_DIR" ]; then
  echo "Log directory not found: $LOG_DIR"
  exit 0
fi

BEFORE="$(du -sh "$LOG_DIR" 2>/dev/null | awk '{print $1}')"
echo "Storage logs before cleanup: ${BEFORE:-unknown}"

find "$LOG_DIR" -type f \
  \( -name "*.log" -o -name "laravel-*.log" -o -name "agnishop-worker*.log" \) \
  -mtime +"$RETENTION_DAYS" \
  -print \
  -delete

AFTER="$(du -sh "$LOG_DIR" 2>/dev/null | awk '{print $1}')"
echo "Storage logs after cleanup: ${AFTER:-unknown}"
