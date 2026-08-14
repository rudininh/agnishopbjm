# Worker Upload Massal Gitashopcollection

Worker ini membuat enam file Shopee Mass Update terbaru dari data di halaman
`/marketplace/import`, kemudian mengunggahnya secara serial ke akun Seller
Centre **Gitashopcollection**. Target akun dikunci di backend dan tidak dapat
diubah dari halaman web atau argumen worker.

## Pengaman Operasi

- Worker memakai profil Chrome khusus `tools/gitashop-mass-upload-worker/.profile`.
  Jangan arahkan worker ini ke `tools/gita-order-scraper/.profile` dan jangan
  salin cookie atau data profil antarakun.
- Browser berjalan terlihat secara default (`headless=false`). Login, OTP, dan
  CAPTCHA selalu diselesaikan manual pada profil Gitashopcollection saja.
- Upload dan sinkronisasi marketplace STB memakai lease database yang sama.
  Jika STB aktif atau tidak dapat diverifikasi, job berakhir aman tanpa
  mengunggah file.
- Enam file diproses satu per satu: Basic Info, Sales Info, Media Info,
  Shipping Info, DTS Info, dan Republish Items. Republish tetap wajib selesai
  dengan hasil Shopee `0`.
- Tidak ada retry otomatis. Untuk mengulang hasil `menunggu_verifikasi`,
  `dibatalkan_aman`, atau kegagalan, buat job baru dari halaman import.

## Konfigurasi Lokal

Tambahkan nilai lokal berikut ke `backend/.env`. Jangan memasukkan nilainya ke
Git, log, PowerShell history, atau dokumen ini.

```text
GITASHOP_MASS_UPLOAD_WORKER_TOKEN=<token-khusus-worker>
GITASHOP_MASS_UPLOAD_WORKER_HEARTBEAT_SECONDS=30
GITASHOP_MASS_UPLOAD_STB_CONTROL_URL=
GITASHOP_MASS_UPLOAD_STB_CONTROL_TOKEN=<token-kontrol-stb-khusus>
GITASHOP_MASS_UPLOAD_STB_WAIT_SECONDS=300
```

Opsional untuk komputer worker (bukan `backend/.env`):

```text
GITASHOP_MASS_UPLOAD_API_BASE_URL=http://agnishopbjm-laravel.test/api
GITASHOP_MASS_UPLOAD_PROFILE_DIR=tools/gitashop-mass-upload-worker/.profile
GITASHOP_MASS_UPLOAD_HEADLESS=false
GITASHOP_MASS_UPLOAD_POLL_SECONDS=5
GITASHOP_MASS_UPLOAD_TIMEOUT_SECONDS=60
```

## Login Pertama

1. Pastikan tidak ada Chrome lain yang membuka profil khusus Gitashop.
2. Jalankan `npm run gitashop-mass-upload-worker -- --once` dari root proyek.
3. Login secara manual ke Seller Centre Gitashopcollection pada browser yang
   terbuka, termasuk OTP atau CAPTCHA bila diminta.
4. Tutup browser setelah login tersimpan. Tidak perlu membuat job upload untuk
   langkah ini.

## Menjalankan Worker

Instal dependensi root sekali dengan `npm install`. Jalankan daemon lokal:

```powershell
npm run gitashop-mass-upload-worker
```

Untuk cek idle satu kali tanpa membuat job:

```powershell
npm run gitashop-mass-upload-worker -- --once
```

Pada Windows, jalankan proses di Task Scheduler atau supervisor proses yang
menjaga direktori kerja di root proyek. Konfigurasikan proses agar dihentikan
normal saat maintenance; jangan menjalankan dua instance worker sekaligus.

## Status dan Pemulihan

- `menunggu_stb`: job menunggu lease marketplace/STB aman.
- `berjalan`: file sedang dibuat atau diproses serial.
- `menunggu_verifikasi`: Seller Centre membutuhkan login, OTP, CAPTCHA, atau
  struktur halaman tidak dapat diverifikasi. Login ulang manual, lalu buat job
  baru.
- `dibatalkan_aman`: STB masih aktif, tidak dapat dihubungi, atau lease tidak
  dapat dijaga. Tunggu sinkronisasi selesai, lalu buat job baru.
- `selesai`: keenam file diterima dan berstatus selesai.
- `selesai_dengan_gagal`: Seller Centre menolak atau gagal memproses satu file;
  file berikutnya tidak diunggah. Periksa audit di halaman import lalu buat job
  baru setelah penyebabnya diperbaiki.

Audit status, nama file, jumlah baris, hash, dan hasil Seller Centre tersedia
pada `/marketplace/import`. Browser profile, cookie, token, HTML mentah, dan
respons mentah Seller Centre tidak ditampilkan atau dicatat.