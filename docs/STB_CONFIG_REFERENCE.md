# STB Config Reference

Dokumen ini dipakai saat nanti setting STB HG680P/HGP Armbian sebagai `stb-sync-worker`. Isinya fokus ke nilai config yang harus dicek setelah repo sudah ada di `/opt/agnishopbjm`.

## File Yang Dipakai

- Template env: `backend/.env.stb.example`
- Env aktif di STB: `/opt/agnishopbjm/backend/.env`
- Cron scheduler: `/etc/cron.d/agnishop-scheduler`
- Supervisor worker: `/etc/supervisor/conf.d/agnishop-worker.conf`
- Dokumentasi instalasi lengkap: `docs/STB_ARMBIAN_SYNC_WORKER.md`

## Checklist `.env`

Copy template:

```bash
cd /opt/agnishopbjm/backend
cp .env.stb.example .env
php artisan key:generate
nano .env
```

Wajib diset:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=http://IP-STB

DB_CONNECTION=pgsql
DB_HOST=IP-DATABASE
DB_PORT=5432
DB_DATABASE=agnishopbjm-laravel
DB_USERNAME=agnishop
DB_PASSWORD=ISI_PASSWORD_DATABASE
DB_SSLMODE=prefer

SHOPEE_PARTNER_ID=ISI_PARTNER_ID
SHOPEE_PARTNER_KEY=ISI_PARTNER_KEY
SHOPEE_HOST=https://partner.shopeemobile.com

TIKTOK_APP_KEY=ISI_APP_KEY
TIKTOK_APP_SECRET=ISI_APP_SECRET
```

Mode STB ringan:

```env
STB_SYNC_WORKER=true
ENABLE_FRONTEND=false
ENABLE_AUTO_BROWSER=false
ENABLE_ORDER_SYNC=true
ENABLE_MARKETPLACE_SYNC=true
ENABLE_STOCK_ANALYSIS=false
ENABLE_BULK_SKU=false
```

Interval default:

```env
ORDER_SYNC_INTERVAL_MINUTES=1
SAFETY_CHECK_INTERVAL_MINUTES=15
FULL_MARKETPLACE_SYNC_INTERVAL_MINUTES=60
ORDER_SYNC_LOOKBACK_HOURS=24
STB_SYNC_RETRY_ATTEMPTS=2
STB_SYNC_RETRY_SLEEP_SECONDS=3
STB_ORDER_PRODUCT_REFRESH_LIMIT=10
STB_HEARTBEAT_TIMEOUT_MINUTES=3
```

Queue ringan:

```env
QUEUE_CONNECTION=sync
STB_SUPERVISOR_PROGRAM=agnishop-worker
```

Catatan: jangan isi secret asli di file template repo. Secret hanya masuk ke `/opt/agnishopbjm/backend/.env` di STB.

## Command Setelah Edit `.env`

```bash
cd /opt/agnishopbjm/backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan config:cache
php artisan event:cache
php artisan route:cache || true
```

## Supervisor Config

File sumber repo:

```bash
/opt/agnishopbjm/deploy/stb/supervisor/agnishop-worker.conf
```

Pasang:

```bash
sudo cp /opt/agnishopbjm/deploy/stb/supervisor/agnishop-worker.conf /etc/supervisor/conf.d/agnishop-worker.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart agnishop-worker
```

Worker yang dijalankan:

```bash
php artisan queue:work --sleep=3 --tries=3 --timeout=120 --memory=256
```

Mode ini hanya 1 worker supaya RAM 2GB tetap aman.

## Cron Scheduler

File sumber repo:

```bash
/opt/agnishopbjm/deploy/stb/cron/agnishop-scheduler
```

Pasang:

```bash
sudo cp /opt/agnishopbjm/deploy/stb/cron/agnishop-scheduler /etc/cron.d/agnishop-scheduler
sudo chmod 0644 /etc/cron.d/agnishop-scheduler
sudo systemctl restart cron
```

Isi cron:

```cron
* * * * * www-data cd /opt/agnishopbjm/backend && php artisan schedule:run >> /dev/null 2>&1
```

## Verifikasi

```bash
cd /opt/agnishopbjm/backend
php artisan agnishop:runtime-status
php artisan schedule:list
sudo supervisorctl status agnishop-worker
bash /opt/agnishopbjm/deploy/stb/health-check.sh
```

Endpoint dari komputer utama:

```http
GET http://IP-STB/api/runtime/stb-status
```

Yang harus terlihat:

- `mode` = `stb-sync-worker`
- `stb_sync_worker` = `true`
- `order_sync_enabled` = `true`
- `marketplace_sync_enabled` = `true`
- `worker_online` berubah `true` setelah heartbeat/scheduler berjalan
- `queue_status.status` idealnya `running` di STB Linux
- `scheduler_status.status` menjadi `online` setelah cron berjalan

## Restart Harian Saat Ada Perubahan Config

```bash
cd /opt/agnishopbjm/backend
php artisan config:clear
php artisan config:cache
sudo supervisorctl restart agnishop-worker
sudo systemctl restart cron
```

## Matikan Mode STB

Jika STB tidak dipakai sebagai worker:

```env
STB_SYNC_WORKER=false
ENABLE_FRONTEND=true
ENABLE_AUTO_BROWSER=true
ENABLE_STOCK_ANALYSIS=true
ENABLE_BULK_SKU=true
```

Lalu:

```bash
php artisan config:clear
php artisan config:cache
sudo supervisorctl restart agnishop-worker
```
