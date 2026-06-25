#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/agnishopbjm}"
BACKEND_DIR="${BACKEND_DIR:-$APP_DIR/backend}"
PHP_VERSION="${PHP_VERSION:-8.2}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"

if [ "$(id -u)" -eq 0 ]; then
  SUDO=""
else
  SUDO="sudo"
fi

echo "==> Updating apt metadata"
$SUDO apt-get update

echo "==> Installing lightweight STB packages"
COMMON_PACKAGES=(nginx unzip git curl supervisor cron ca-certificates)
if apt-cache show "php${PHP_VERSION}-cli" >/dev/null 2>&1; then
  PHP_PACKAGES=(
    "php${PHP_VERSION}-fpm"
    "php${PHP_VERSION}-cli"
    "php${PHP_VERSION}-pgsql"
    "php${PHP_VERSION}-mbstring"
    "php${PHP_VERSION}-xml"
    "php${PHP_VERSION}-curl"
    "php${PHP_VERSION}-zip"
    "php${PHP_VERSION}-bcmath"
    "php${PHP_VERSION}-gd"
  )
else
  PHP_PACKAGES=(php-fpm php-cli php-pgsql php-mbstring php-xml php-curl php-zip php-bcmath php-gd)
fi
$SUDO apt-get install -y "${COMMON_PACKAGES[@]}" "${PHP_PACKAGES[@]}"

if ! command -v composer >/dev/null 2>&1; then
  echo "==> Installing Composer"
  EXPECTED_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"
  php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
  ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
  if [ "$EXPECTED_SIGNATURE" != "$ACTUAL_SIGNATURE" ]; then
    rm -f /tmp/composer-setup.php
    echo "Composer installer signature mismatch" >&2
    exit 1
  fi
  $SUDO php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
  rm -f /tmp/composer-setup.php
fi

echo "==> Creating $APP_DIR"
$SUDO mkdir -p "$APP_DIR"
$SUDO chown -R "${USER:-www-data}:www-data" "$APP_DIR" || true

cat <<EOF

Next manual steps:
1. Clone or copy the repository into:
   $APP_DIR

   Example:
   git clone https://github.com/rudininh/agnishopbjm-laravel.git $APP_DIR

2. Copy the STB environment file:
   cp $BACKEND_DIR/.env.stb.example $BACKEND_DIR/.env

3. Fill database and marketplace credentials, then run:
   cd $BACKEND_DIR
   php artisan key:generate
   composer install --no-dev --optimize-autoloader
   php artisan migrate --force

EOF

if [ -d "$BACKEND_DIR" ]; then
  echo "==> Backend directory found: $BACKEND_DIR"
  cd "$BACKEND_DIR"

  if [ -f composer.json ]; then
    composer install --no-dev --optimize-autoloader
  fi

  if [ -f .env ]; then
    php artisan config:cache || true
    php artisan route:cache || true
    php artisan event:cache || true
  else
    echo "Skipping artisan cache commands because $BACKEND_DIR/.env is missing"
  fi
fi

echo "==> Installing scheduler cron"
if [ -f "$REPO_ROOT/deploy/stb/cron/agnishop-scheduler" ]; then
  $SUDO cp "$REPO_ROOT/deploy/stb/cron/agnishop-scheduler" /etc/cron.d/agnishop-scheduler
  $SUDO chmod 0644 /etc/cron.d/agnishop-scheduler
fi
$SUDO systemctl enable --now cron || true

echo "==> Installing supervisor tmpfiles"
if [ -f "$REPO_ROOT/deploy/stb/tmpfiles/agnishop-supervisor.conf" ]; then
  $SUDO cp "$REPO_ROOT/deploy/stb/tmpfiles/agnishop-supervisor.conf" /etc/tmpfiles.d/agnishop-supervisor.conf
  $SUDO systemd-tmpfiles --create /etc/tmpfiles.d/agnishop-supervisor.conf || true
fi

echo "==> Installing supervisor programs"
$SUDO systemctl enable --now supervisor || true
if compgen -G "$REPO_ROOT/deploy/stb/supervisor/*.conf" >/dev/null; then
  for supervisor_conf in "$REPO_ROOT"/deploy/stb/supervisor/*.conf; do
    $SUDO cp "$supervisor_conf" "/etc/supervisor/conf.d/$(basename "$supervisor_conf")"
  done
  $SUDO supervisorctl reread || true
  $SUDO supervisorctl update || true
  $SUDO supervisorctl restart agnishop-worker || true
  $SUDO supervisorctl restart agnishop-api || true
fi

echo "==> Done"
echo "No Node.js or frontend build was installed by this script."
