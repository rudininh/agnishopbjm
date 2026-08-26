# One-Click TikTok Reconciliation Design

**Goal:** Menyediakan satu tombol untuk menyiapkan seluruh anomali Shopee → TikTok, membuat draft produk/varian TikTok yang belum ada, dan mengirimnya hanya setelah satu kali review/konfirmasi pengguna.

## Current State

- Pada 21 Agustus 2026 terdapat 23 varian `missing_tiktok_stock` dari dua produk Shopee yang belum memiliki produk TikTok tujuan.
- Terdapat tiga `tiktok_sku_conflict`: seller SKU Shopee sudah dipakai oleh SKU TikTok dengan nama varian berbeda.
- Konflik tidak boleh dipush stok, dihubungkan, atau dibuat ulang secara otomatis.

## User Flow

1. Pengguna membuka halaman Anomali Stok dan memilih **Selesaikan Semua dengan Review**.
2. Backend menyegarkan cache produk Shopee dan TikTok yang relevan, lalu membangun satu `reconciliation run` baru.
3. Halaman review menampilkan tiga kelompok:
   - **Siap dibuat:** produk Shopee tanpa target TikTok, dikelompokkan per produk dan berisi varian Shopee, harga, stok, gambar, serta draft seller SKU.
   - **Siap ditambah:** varian Shopee yang memiliki satu target produk TikTok yang aman dan belum memiliki seller SKU yang sama di TikTok.
   - **Perlu verifikasi:** konflik seller SKU atau data produk/varian yang tidak lengkap. Kelompok ini tidak memiliki checkbox kirim.
4. Untuk setiap draft produk baru, pengguna memilih kategori TikTok dan mengisi/mengonfirmasi atribut wajib yang tidak dapat diturunkan dari Shopee. Data turunan Shopee seperti judul, gambar, variasi, harga, stok, berat, dan dimensi ditampilkan sebagai draft yang dapat disunting.
5. Pengguna menekan **Konfirmasi Proses** sekali. Backend mengirim hanya kelompok `Siap dibuat` dan `Siap ditambah` yang masih lolos validasi final.
6. Setelah setiap pengiriman, backend memverifikasi katalog TikTok, menyimpan mapping `stock_master`/`sku_mappings`, memperbarui cache TikTok, lalu melakukan sinkron stok. Konflik tetap berada pada daftar review dengan alasan yang eksplisit.

## Safety Rules

- Tidak ada request mutasi TikTok pada tahap preview/review.
- Produk baru hanya dibuat jika semua varian Shopee memiliki gambar, SKU internal unik, stok, harga positif, kategori TikTok, dan atribut wajib yang tervalidasi.
- Varian baru hanya ditambahkan ke produk TikTok ketika target produk tunggal dan seller SKU belum ada.
- Jika seller SKU ditemukan tetapi nama varian Shopee dan TikTok berbeda, statusnya `tiktok_sku_conflict`; sistem tidak membuat varian baru, tidak membuat mapping, dan tidak push stok.
- Jika katalog berubah antara preview dan submit, run ditolak sebagai `stale_revision` dan pengguna harus memuat ulang review.
- Setiap hasil varian dicatat ke audit run dengan payload yang telah disensor dari token, app secret, dan signature.

## Backend Contract

### `POST /api/tiktok/reconciliation-runs/preview`

Membuat preview read-only dan mengembalikan:

```json
{
  status: ready_for_review,
  run_id: uuid,
  revision: sha256,
  summary: {
    new_products: 2,
    new_variants: 23,
    safe_variant_additions: 0,
    conflicts: 3,
    blocked: 0
  },
  new_products: [],
  variant_additions: [],
  conflicts: []
}
```

### `POST /api/tiktok/reconciliation-runs/{runId}/submit`

Menerima `revision`, `selected_new_product_keys`, `selected_variant_product_ids`, dan data kategori/atribut yang telah dikonfirmasi. Endpoint memvalidasi revision dan semua data wajib sebelum mengirim ke TikTok.

Respons memuat status per produk dan varian (`updated`, `submitted_unverified`, `skipped`, `failed`) serta ringkasan final.

## Persistence

- Tambahkan tabel `tiktok_reconciliation_runs` untuk metadata preview, revision, status, ringkasan, dan timestamps.
- Tambahkan tabel `tiktok_reconciliation_run_items` untuk setiap produk/varian draft, jenis tindakan, payload tersensor, hasil, dan alasan blokir.
- Gunakan relasi ke `stock_master` bila tersedia agar hasil verifikasi dapat memperbarui mapping secara deterministik.

## UI

- Tambahkan tombol **Selesaikan Semua dengan Review** pada `StockAnomalies.vue`.
- Tambahkan halaman review khusus dengan ringkasan, daftar produk baru, tambahan varian aman, konflik, dan tombol konfirmasi tunggal.
- Konflik menggunakan warna peringatan dan hanya menyediakan tautan ke rekonsiliasi/mapping manual; tidak ada aksi auto-sync.
- Setelah submit, tampilkan hasil per varian serta tombol muat ulang anomali.

## Success Criteria

- Pengguna dapat menyelesaikan 23 varian dari dua produk melalui satu preview dan satu konfirmasi, setelah melengkapi kategori/atribut TikTok yang wajib.
- Tidak ada produk atau varian TikTok baru dibuat selama preview.
- Tiga konflik SKU tetap tidak diubah otomatis dan tercatat sebagai konflik yang perlu verifikasi.
- Setiap pengiriman yang berhasil terverifikasi menghasilkan cache TikTok, mapping, dan stok lokal yang konsisten.
