#!/usr/bin/env bash
set -u

APP_DIR="${APP_DIR:-/opt/agnishopbjm}"
BACKEND_DIR="${BACKEND_DIR:-$APP_DIR/backend}"
SUPERVISOR_PROGRAM="${STB_SUPERVISOR_PROGRAM:-agnishop-worker}"
EXIT_CODE=0

ok() { printf "[OK] %s\n" "$1"; }
warn() { printf "[WARN] %s\n" "$1"; EXIT_CODE=1; }

if command -v php >/dev/null 2>&1; then
  ok "PHP available: $(php -v | head -n 1)"
else
  warn "PHP is not available"
fi

if [ -d "$BACKEND_DIR" ]; then
  ok "Backend directory exists: $BACKEND_DIR"
else
  warn "Backend directory missing: $BACKEND_DIR"
fi

if [ -f "$BACKEND_DIR/.env" ]; then
  ok ".env exists"
else
  warn ".env missing"
fi

if [ -d "$BACKEND_DIR" ] && [ -f "$BACKEND_DIR/artisan" ]; then
  cd "$BACKEND_DIR" || exit 1
  if php artisan agnishop:runtime-status --json >/tmp/agnishop-stb-status.json 2>/tmp/agnishop-stb-status.err; then
    ok "Database connection and runtime status OK"
    LAST_ORDER_SYNC="$(php -r '$j=json_decode(file_get_contents("/tmp/agnishop-stb-status.json"), true); echo $j["last_order_sync_at"] ?? "-";')"
    ok "Last sync order: $LAST_ORDER_SYNC"
  else
    warn "Database/runtime status failed: $(cat /tmp/agnishop-stb-status.err)"
  fi
fi

if command -v supervisorctl >/dev/null 2>&1; then
  SUPERVISOR_STATUS="$(supervisorctl status "$SUPERVISOR_PROGRAM" 2>&1 || true)"
  if printf "%s" "$SUPERVISOR_STATUS" | grep -q "RUNNING"; then
    ok "Supervisor worker running: $SUPERVISOR_STATUS"
  else
    warn "Supervisor worker not running: $SUPERVISOR_STATUS"
  fi
else
  warn "supervisorctl not available"
fi

if [ -f /etc/cron.d/agnishop-scheduler ] && grep -q "schedule:run" /etc/cron.d/agnishop-scheduler; then
  ok "Cron scheduler installed"
else
  warn "Cron scheduler not installed"
fi

if [ -d "$BACKEND_DIR/storage/logs" ]; then
  LOG_MB="$(du -sm "$BACKEND_DIR/storage/logs" 2>/dev/null | awk '{print $1}')"
  if [ "${LOG_MB:-0}" -gt 200 ]; then
    warn "storage/logs is large: ${LOG_MB}MB"
  else
    ok "storage/logs size: ${LOG_MB:-0}MB"
  fi
fi

DISK_USED="$(df -P "$APP_DIR" 2>/dev/null | awk 'NR==2 {gsub("%","",$5); print $5}')"
if [ -n "${DISK_USED:-}" ]; then
  if [ "$DISK_USED" -ge 80 ]; then
    warn "Disk usage high: ${DISK_USED}%"
  else
    ok "Disk usage: ${DISK_USED}%"
  fi
fi

exit "$EXIT_CODE"
