# STB Armbian Sync Worker

Panduan ini menyiapkan mode deployment `stb-sync-worker` untuk STB HG680P/HGP Armbian Server RAM 2GB/internal 8GB. Mode ini hanya menjalankan backend Laravel sebagai worker order sync/marketplace sync ringan. Frontend Vue, browser auto-sync, bulk SKU, dan analisis stok berat tidak dijalankan otomatis.

Untuk checklist setting `.env`, cron, supervisor, dan verifikasi setelah perangkat STB siap, lihat juga `docs/STB_CONFIG_REFERENCE.md`.

## Kebutuhan Minimal

- STB HG680P/HGP dengan Armbian Server 64-bit.
- RAM 2GB, storage kosong disarankan minimal 2GB.
- Koneksi internet stabil.
- PHP 8.2 atau versi PHP yang memenuhi `composer.json` (`^8.2`).
- PostgreSQL bisa berada di STB, komputer utama, VPS, atau database lain yang bisa dijangkau STB.
- Token dan kredensial Shopee/TikTok yang sama dengan backend utama.

## Install Dependency Armbian/Debian

Jalankan script ringan dari root repo setelah repo tersedia di STB:

```bash
sudo bash deploy/stb/install-armbian.sh
```

Script ini menginstall `nginx`, PHP/FPM/CLI, ekstensi PostgreSQL/XML/Curl/Zip/BCMath, `unzip`, `git`, `curl`, `supervisor`, dan Composer bila belum ada. Script tidak menginstall Node.js dan tidak build frontend.

Jika install manual:

```bash
sudo apt-get update
sudo apt-get install -y nginx php8.2-fpm php8.2-cli php8.2-pgsql php8.2-mbstring php8.2-xml php8.2-curl php8.2-zip php8.2-bcmath unzip git curl supervisor
```

Jika paket `php8.2-*` tidak tersedia di image Armbian, gunakan paket default Debian seperti `php-cli php-fpm php-pgsql ...`, atau aktifkan repo PHP yang sesuai untuk board tersebut.

## Install Composer

```bash
EXPECTED_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', 'composer-setup.php');")"
test "$EXPECTED_SIGNATURE" = "$ACTUAL_SIGNATURE"
sudo php composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm composer-setup.php
```

## Clone Repo

Lokasi standar deploy STB:

```bash
sudo mkdir -p /opt/agnishopbjm
sudo chown -R "$USER":www-data /opt/agnishopbjm
git clone https://github.com/rudininh/agnishopbjm-laravel.git /opt/agnishopbjm
cd /opt/agnishopbjm/backend
```

Install dependency backend:

```bash
composer install --no-dev --optimize-autoloader
```

## Setup `.env` Worker

```bash
cp .env.stb.example .env
php artisan key:generate
nano .env
```

Isi minimal:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://IP-STB
LOG_CHANNEL=daily
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=IP-DATABASE
DB_PORT=5432
DB_DATABASE=agnishopbjm-laravel
DB_USERNAME=...
DB_PASSWORD=...

SHOPEE_PARTNER_ID=...
SHOPEE_PARTNER_KEY=...
TIKTOK_APP_KEY=...
TIKTOK_APP_SECRET=...

STB_SYNC_WORKER=true
ENABLE_FRONTEND=false
ENABLE_AUTO_BROWSER=false
ENABLE_ORDER_SYNC=true
ENABLE_MARKETPLACE_SYNC=true
ENABLE_STOCK_ANALYSIS=false
ENABLE_BULK_SKU=false
ORDER_SYNC_INTERVAL_MINUTES=5
SAFETY_CHECK_INTERVAL_MINUTES=15
FULL_MARKETPLACE_SYNC_INTERVAL_MINUTES=60
```

Jangan commit `.env`. Gunakan `.env.stb.example` sebagai template saja.

## Migration

Jika database baru:

```bash
php artisan migrate --force
```

Jika database sudah dipakai komputer utama, jalankan migration hanya setelah backup:

```bash
php artisan migrate:status
php artisan migrate --force
```

## Optimasi Laravel

```bash
php artisan config:cache
php artisan event:cache
php artisan route:cache || true
```

`route:cache` boleh gagal bila masih ada route closure; itu aman dilewati.

## Command Worker

Mode STB menambahkan command berikut:

```bash
php artisan agnishop:stb-heartbeat
php artisan agnishop:sync-orders
php artisan agnishop:sync-marketplace-lite
php artisan agnishop:safety-check-lite
php artisan agnishop:runtime-status
```

Fungsi:

- `agnishop:sync-orders`: polling order Shopee dan TikTok, retry ringan, log error ke `marketplace_sync_logs`.
- `agnishop:sync-marketplace-lite`: refresh token dan cache marketplace berkala tanpa bulk SKU/analisis berat.
- `agnishop:safety-check-lite`: cek error/stale sync ringan. Deep stock analysis hanya berjalan bila `ENABLE_STOCK_ANALYSIS=true`.
- `agnishop:runtime-status`: cek heartbeat, last sync, database, scheduler, supervisor queue, disk, dan memori.
- `agnishop:stb-heartbeat`: menyimpan heartbeat STB agar dashboard utama tahu worker hidup.

## Laravel Scheduler

Pasang cron:

```bash
sudo cp /opt/agnishopbjm/deploy/stb/cron/agnishop-scheduler /etc/cron.d/agnishop-scheduler
sudo chmod 0644 /etc/cron.d/agnishop-scheduler
```

Isi cron:

```cron
* * * * * cd /opt/agnishopbjm/backend && php artisan schedule:run >> /dev/null 2>&1
```

Saat `STB_SYNC_WORKER=true`, scheduler menjalankan:

- heartbeat setiap 1 menit
- order sync setiap `ORDER_SYNC_INTERVAL_MINUTES` menit
- safety check lite setiap `SAFETY_CHECK_INTERVAL_MINUTES` menit
- marketplace lite setiap `FULL_MARKETPLACE_SYNC_INTERVAL_MINUTES` menit

Schedule berat lama tidak dijalankan otomatis di mode STB.

## Queue Worker Supervisor

Pasang supervisor:

```bash
sudo cp /opt/agnishopbjm/deploy/stb/supervisor/agnishop-worker.conf /etc/supervisor/conf.d/agnishop-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart agnishop-worker
```

Command worker:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=120 --memory=256
```

Konfigurasi hanya menjalankan 1 worker agar aman untuk RAM 2GB.

## Cek Log dan Status

```bash
cd /opt/agnishopbjm/backend
php artisan agnishop:runtime-status
tail -f storage/logs/laravel-$(date +%F).log
sudo supervisorctl status agnishop-worker
sudo tail -f /var/log/syslog
```

Endpoint dashboard ringan:

```http
GET /api/runtime/stb-status
```

Response berisi mode, enable flag, last order sync, last marketplace sync, error terakhir, queue status, scheduler status, database, disk, dan memori.

## Restart Service

```bash
sudo supervisorctl restart agnishop-worker
sudo systemctl restart cron
sudo systemctl restart nginx
sudo systemctl restart php8.2-fpm
```

Jika memakai paket PHP default, nama service bisa `php-fpm`.

## Tetap Jalan Setelah Reboot

Pastikan service aktif:

```bash
sudo systemctl enable supervisor
sudo systemctl enable cron
sudo systemctl enable nginx
sudo systemctl enable php8.2-fpm || true
sudo reboot
```

Setelah reboot:

```bash
cd /opt/agnishopbjm/backend
php artisan agnishop:runtime-status
sudo supervisorctl status agnishop-worker
```

## Health Check dan Cleanup

```bash
bash /opt/agnishopbjm/deploy/stb/health-check.sh
bash /opt/agnishopbjm/deploy/stb/log-cleanup.sh
```

`health-check.sh` mengecek PHP, folder backend, `.env`, database, supervisor, cron scheduler, last order sync, ukuran log, dan disk usage. `log-cleanup.sh` menghapus log lama lebih dari 7 hari tanpa menghapus file penting.

## Mengaktifkan/Mematikan Mode STB

Aktif:

```env
STB_SYNC_WORKER=true
ENABLE_FRONTEND=false
ENABLE_AUTO_BROWSER=false
ENABLE_ORDER_SYNC=true
ENABLE_MARKETPLACE_SYNC=true
ENABLE_STOCK_ANALYSIS=false
ENABLE_BULK_SKU=false
```

Nonaktif/kembali mode normal:

```env
STB_SYNC_WORKER=false
ENABLE_FRONTEND=true
ENABLE_AUTO_BROWSER=true
ENABLE_STOCK_ANALYSIS=true
ENABLE_BULK_SKU=true
```

Setelah ubah `.env`:

```bash
php artisan config:clear
php artisan config:cache
sudo supervisorctl restart agnishop-worker
```

Dashboard boleh tetap dibuka dari komputer utama. Sync utama tidak bergantung pada tab browser karena berjalan lewat cron Laravel dan Artisan command di STB.
