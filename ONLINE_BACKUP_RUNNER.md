# Online Backup Runner

Online backup runner dipakai saat komputer local mati. Saat real mode masih `OFF`, scheduler hanya menjalankan dry-run dan tidak memproses order.

## Laravel endpoint

Scheduler utama:

```text
POST /api/marketplace/auto-sync/backup-runner/scheduler-tick
```

Env opsional di Laravel:

```env
AUTO_SYNC_BACKUP_RUNNER_TOKEN=isi-token-rahasia
```

Jika env ini diisi, request scheduler wajib membawa token yang sama lewat `Authorization: Bearer ...`, `X-Runner-Token`, atau query `?token=...`.

## Vercel bridge

Function:

```text
https://agnishopbjm-laravel.vercel.app/api/auto-sync-scheduler
```

Env di Vercel:

```env
AUTO_SYNC_SCHEDULER_TARGET_URL=https://domain-backend-online.test/api/marketplace/auto-sync/backup-runner/scheduler-tick
AUTO_SYNC_BACKUP_RUNNER_TOKEN=isi-token-yang-sama-dengan-laravel
AUTO_SYNC_SCHEDULER_BRIDGE_TOKEN=token-untuk-mengunci-endpoint-vercel
```

`AUTO_SYNC_SCHEDULER_TARGET_URL` wajib diisi untuk cron online. Jika kosong, bridge akan mengembalikan `skipped` dan tidak memanggil backend mana pun.

Jika `AUTO_SYNC_SCHEDULER_BRIDGE_TOKEN` kosong, bridge bisa dipanggil tanpa token. Untuk production, isi token ini.

Jika `CRON_SECRET` atau `AUTO_SYNC_SCHEDULER_BRIDGE_TOKEN` sudah aktif, akses manual tanpa token akan menghasilkan `401 Unauthorized`. Itu berarti bridge hidup dan terkunci.

Cron Vercel diset di `vercel.json`:

```json
{
  "path": "/api/auto-sync-scheduler",
  "schedule": "0 15 * * *"
}
```

Artinya bridge mencoba tick 1x sehari sekitar 23.00 WITA. Jadwal ini mengikuti batas Vercel Hobby/free yang hanya mengizinkan cron harian. Selama real mode OFF, Laravel tetap hanya dry-run.

Contoh panggil manual:

```bash
curl -X POST "https://agnishopbjm-laravel.vercel.app/api/auto-sync-scheduler?hours=1" \
  -H "Authorization: Bearer isi-token-bridge"
```

Catatan status deploy:

- Jika `https://agnishopbjm-laravel.vercel.app/api/auto-sync-scheduler` masih `404`, berarti commit terbaru belum ter-deploy ke Vercel.
- Setelah deploy sukses tapi env target belum diisi, endpoint akan merespons `skipped`.
- Setelah `AUTO_SYNC_SCHEDULER_TARGET_URL` diisi, endpoint akan meneruskan tick ke backend Laravel.

## Mode aman

Default aman:

- `online_backup_enabled`: OFF
- `online_backup_real_enabled`: OFF
- scheduler hanya dry-run
- tidak ada sync order yang dijalankan

Real mode baru bisa ON dari dashboard setelah mengetik:

```text
AKTIFKAN REAL BACKUP
```

## Checklist deploy

1. Push commit terbaru ke GitHub.
2. Pastikan Vercel selesai deploy branch yang benar.
3. Buka:

```text
https://agnishopbjm-laravel.vercel.app/api/auto-sync-scheduler
```

Hasil awal yang aman kalau env target belum diisi:

```json
{
  "bridge": "skipped",
  "reason": "AUTO_SYNC_SCHEDULER_TARGET_URL is not configured"
}
```

4. Set env Vercel:

```env
AUTO_SYNC_SCHEDULER_TARGET_URL=https://domain-backend-online/api/marketplace/auto-sync/backup-runner/scheduler-tick
AUTO_SYNC_BACKUP_RUNNER_TOKEN=token-sama-dengan-backend
AUTO_SYNC_SCHEDULER_BRIDGE_TOKEN=token-bridge-vercel
```

5. Set env backend online:

```env
AUTO_SYNC_BACKUP_RUNNER_TOKEN=token-sama-dengan-vercel
```

6. Tes manual:

```bash
curl -X POST "https://agnishopbjm-laravel.vercel.app/api/auto-sync-scheduler?hours=1" \
  -H "Authorization: Bearer token-bridge-vercel"
```

7. Cek dashboard local:

```text
http://agnishopbjm-laravel.test/marketplace/auto-sync
```

Tab `Runtime` harus menampilkan event `scheduler_tick` dan mode `dry_run` selama real mode OFF.

## Rollback aman

Jika ada hal aneh:

1. Di dashboard Auto Sync, klik `Matikan Real`.
2. Klik `Matikan Backup`.
3. Hapus atau kosongkan env Vercel `AUTO_SYNC_SCHEDULER_TARGET_URL`.
4. Cron Vercel berikutnya akan berubah menjadi `skipped`.

Selama `online_backup_real_enabled` OFF, scheduler tidak menjalankan order sync.
