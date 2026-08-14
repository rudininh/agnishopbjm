# Gita Order Scraper Launcher PC

## Tujuan

Menjalankan kolektor pesanan Gita dari halaman `/marketplace/gita-orders` tanpa operator membuka PowerShell secara normal. Launcher hanya menjalankan perintah lokal `npm run gita-order-scrape`; proses kolektor tetap read-only terhadap Seller Centre dan tidak menjalankan sinkron stok otomatis.

## Ruang Lingkup

- Menambah tombol `Jalankan Scraper PC` pada laporan Pesanan Gita.
- Menambah endpoint Laravel lokal untuk membangunkan scraper Node melalui proses PowerShell tersembunyi.
- Menampilkan status launcher yang aman di halaman: dimulai, sudah berjalan, diblokir pengaman marketplace, atau perlu dijalankan manual.
- Menampilkan panduan PowerShell fallback pada halaman yang sama.
- Menambah penguncian proses lokal untuk mencegah dua scraper memakai profil browser Gita yang sama.
- Memuat ulang laporan run Gita setelah launcher dipicu dan selama scraper masih aktif.

## Batasan Dan Pengaman

- Launcher hanya boleh menjalankan `tools/gita-order-scraper/src/cli.js` dari root proyek ini; tidak menerima path, argumen Node, atau perintah dari request web.
- Satu scraper Gita aktif saja. Lock file menyimpan PID dan waktu mulai; PID hidup menghasilkan `already_running`, sedangkan lock stale dibersihkan sebelum proses baru dimulai.
- Launcher memeriksa `MarketplaceOperationLeaseService` sebelum memulai. Jika ada lease operasi marketplace aktif, launcher tidak membuka browser dan mengembalikan status `marketplace_busy` beserta pesan tersanitasi.
- Setelah scraper mulai, scraper memperoleh lease `gita_order_scrape` sebelum membuka browser dan memperbaruinya sepanjang proses. Lease dilepas pada seluruh hasil terminal dan pada kegagalan proses.
- Launcher tidak memaksa menghentikan STB, worker Mass Update, browser, atau proses manual. Ia hanya menolak start baru ketika lease/profil sedang dipakai.
- Profil browser tetap `tools/gita-order-scraper/.profile`; tidak ada token, cookie, password, CAPTCHA, OTP, atau isi profil yang dikirim ke frontend maupun log launcher.
- Perintah manual yang dijalankan ketika scraper aktif harus keluar aman tanpa membuka browser kedua.

## Alur

1. Pengguna menekan `Jalankan Scraper PC` di `/marketplace/gita-orders`.
2. Frontend memanggil endpoint wake launcher dan menampilkan hasilnya.
3. Backend memeriksa konfigurasi launcher lokal, marketplace operation lease, lock scraper, dan keberadaan Node/script.
4. Jika aman, backend memakai PowerShell `Start-Process` tersembunyi untuk menjalankan Node dari root proyek. Output standar dan error disimpan pada `backend/storage/logs/` tanpa rahasia.
5. CLI mengambil lock file, memperoleh lease `gita_order_scrape`, lalu membuka profil Gita yang telah diautentikasi manusia.
6. Kolektor membaca Pesanan Reguler dan Instant pada Perlu Dikirim serta Dikirim sesuai kontrak kolektor saat ini, lalu mengirim satu payload terminal ke API Laravel.
7. CLI melepas lease dan lock pada keberhasilan, login/verifikasi diperlukan, atau error.
8. Frontend melakukan polling laporan terbaru ketika proses dimulai atau sudah berjalan. Halaman menunjukkan run terminal terakhir yang benar-benar telah direkam.

## Status Launcher Dan UI

- `started`: proses lokal dimulai; UI menampilkan bahwa scraper sedang berjalan.
- `already_running`: scraper lokal aktif; UI memuat laporan terbaru tanpa memulai proses lain.
- `marketplace_busy`: ada lease marketplace aktif; UI menampilkan operasi yang sedang memakai pengaman tanpa detail sensitif.
- `manual_required`: konfigurasi launcher tidak tersedia; UI meminta operator memakai command fallback.

Panduan fallback yang tampil di halaman:

```powershell
Set-Location 'C:\laragon\www\agnishopbjm-laravel'
npm run gita-order-scrape
```

Panduan menjelaskan bahwa command cukup dijalankan sekali dan hasil non-sukses `needs_login` atau `failed` perlu dibaca dari laporan, bukan diulang otomatis.

## Konfigurasi

Konfigurasi baru memakai prefix `GITA_ORDER_SCRAPER_LOCAL_WORKER_`:

- `GITA_ORDER_SCRAPER_LOCAL_WORKER_ENABLED=true`
- `GITA_ORDER_SCRAPER_LOCAL_WORKER_NODE_BINARY=node`
- `GITA_ORDER_SCRAPER_LOCAL_WORKER_ALIVE_SECONDS=45`
- `GITA_ORDER_SCRAPER_LOCAL_WORKER_LEASE_SECONDS=900`

Tidak ada nilai token di `.env.example`. Token ingest tetap dibaca hanya oleh CLI dari `backend/.env` seperti mekanisme saat ini.

## Kriteria Penerimaan

- Menekan tombol Gita Orders memulai satu proses scraper lokal jika kondisi aman.
- Klik ulang dan command manual kedua tidak membuka browser/profil kedua.
- Lease marketplace aktif mencegah browser scraper dibuka dan memberi pesan yang jelas.
- Launcher dan worker selalu melepaskan lock/lease pada hasil terminal.
- Pesanan Gita tetap read-only; tidak ada pemanggilan sinkron stok otomatis dari launcher.
- Halaman Gita Orders menampilkan command manual fallback dan status `already_running`/`manual_required`/`marketplace_busy` secara aman.
- Tes mencakup keputusan launcher, lock aktif/stale, lease busy, pelepasan pengaman pada exit, endpoint, state UI, dan regression suite scraper yang ada.