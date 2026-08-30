# Shopee SKU Normalization and TikTok Variant Cleanup Design

**Goal:** Menambahkan satu alur aman pada halaman **Tambah Semua Varian TikTok** untuk menormalkan hanya SKU varian Shopee yang sudah tidak sesuai nama varian terbaru, lalu menghapus hanya varian TikTok lama yang memakai SKU tersebut. Varian TikTok lain, serta nama, gambar, harga, dan stok Shopee, tidak boleh diubah.

## Context

Admin dapat mengganti nama dan gambar varian Shopee tanpa mengganti seller SKU. Akibatnya, seller SKU lama masih menghubungkan model Shopee yang namanya sudah berubah dengan SKU TikTok yang masih membawa nama varian lama. Preview bulk TikTok kemudian menempatkannya pada kelompok **SKU TikTok Sudah Ada, Mapping Belum Tersambung**.

Snapshot data pada 22 Agustus 2026 menunjukkan:

- 18 produk TikTok memiliki konflik nama varian;
- 90 baris konflik nama terdeteksi pada saat pemeriksaan;
- 86 baris memiliki seller SKU Shopee yang berbeda dari SKU template terbaru;
- 4 baris sudah menggunakan SKU template dan tidak termasuk cakupan mutasi;
- 3 dari 86 perubahan memiliki benturan target SKU dengan model Shopee dan SKU TikTok lain;
- 83 baris saat itu memenuhi syarat awal untuk diproses otomatis;
- setiap produk TikTok masih memiliki minimal satu varian aktif setelah hanya varian target yang dibuang.

Angka tersebut hanya observasi awal. Preview dan submit harus selalu menghitung ulang dari data terbaru.

## Scope

Alur baru hanya menangani baris `mapping_only_variants` yang memenuhi seluruh syarat berikut:

1. Produk TikTok tujuan, SKU ID TikTok, item ID Shopee, dan model ID Shopee tersedia.
2. Seller SKU Shopee lama sama persis dengan seller SKU TikTok target setelah normalisasi huruf besar dan trim.
3. Nama varian Shopee dan TikTok berbeda.
4. SKU target yang dibangun dengan `buildShopeeTemplateSellerSku(item_id, current_shopee_variant_name)` berbeda dari SKU Shopee lama.
5. SKU target tidak digunakan oleh model Shopee lain pada item yang sama.
6. SKU target tidak digunakan oleh SKU TikTok aktif lain pada produk yang sama.
7. Menghapus seluruh SKU TikTok target pada satu produk masih menyisakan minimal satu SKU aktif.

Baris yang SKU lamanya sudah sama dengan template tidak diubah dan varian TikTok-nya tidak dihapus. Baris yang gagal salah satu validasi tampil sebagai **Diblokir** dengan alasan yang spesifik.

## Non-Goals

- Tidak mengubah nama, gambar, harga, stok, status, atau atribut lain pada Shopee.
- Tidak menghapus seluruh varian TikTok pada sebuah produk.
- Tidak menghapus varian TikTok yang seller SKU-nya tidak sedang dinormalisasi di Shopee.
- Tidak otomatis membuat ulang varian TikTok dalam aksi cleanup yang sama.
- Tidak mengubah SKU TikTok lama menjadi SKU target.
- Tidak menyelesaikan benturan target SKU secara otomatis dengan suffix buatan.

Setelah cleanup berhasil, varian yang bersangkutan kembali menjadi kandidat pada alur tambah varian TikTok yang sudah ada. Pengguna tetap menjalankan pembuatan ulang varian melalui tombol tambah varian terpisah.

## User Experience

Pada bagian **SKU TikTok Sudah Ada, Mapping Belum Tersambung**, tambahkan tombol **Normalisasi SKU & Hapus Varian TikTok Lama**.

Preview menampilkan:

- jumlah produk dan varian yang aman diproses;
- seller SKU lama dan SKU template target;
- nama varian Shopee terbaru;
- SKU ID dan nama varian TikTok yang akan dihapus;
- baris yang tidak berubah karena SKU sudah sesuai template;
- baris yang diblokir beserta alasannya.

Tombol membuka modal konfirmasi yang menegaskan bahwa:

- hanya `model_sku` Shopee yang berubah;
- hanya SKU TikTok yang tercantum pada preview yang dihapus;
- varian TikTok lain tetap dipertahankan;
- varian yang berhasil dibersihkan belum langsung dibuat ulang;
- data dapat ditolak jika katalog berubah sebelum submit.

Setelah proses, UI menampilkan hasil per varian dengan status `updated`, `partial`, `submitted_unverified`, `blocked`, `stale_revision`, atau `failed`, kemudian memuat ulang preview. Baris sukses harus berpindah dari tabel konflik ke tabel kandidat tambah varian.

## Backend Design

### Service Boundary

Tambahkan service khusus cleanup agar controller tidak menampung orkestrasi destructive workflow. Service bertanggung jawab atas:

- membangun preview deterministik;
- menghitung revision;
- mengklaim run satu kali;
- validasi ulang sumber lokal dan katalog marketplace terbaru;
- batch delete TikTok per produk;
- update `model_sku` Shopee;
- verifikasi kedua marketplace;
- audit dan rekonsiliasi cache lokal.

Gunakan tabel `tiktok_reconciliation_runs` dan `tiktok_reconciliation_run_items` yang sudah dirancang di worktree untuk menyimpan revision, status run, item key, source fingerprint, payload tersensor, hasil, dan alasan blokir. Tambahkan action type khusus seperti `shopee_sku_tiktok_delete` tanpa menyimpan token, secret, signature, atau header otorisasi.

### Preview Endpoint

Tambahkan endpoint read-only:

`POST /api/tiktok/bulk-missing-variants/sku-cleanup/preview`

Endpoint membuat reconciliation run dan mengembalikan:

```json
{
  "status": "ready_for_review",
  "run_id": "uuid",
  "revision": "sha256",
  "summary": {
    "products": 18,
    "conflicts": 90,
    "eligible": 83,
    "unchanged": 4,
    "blocked": 3
  },
  "items": []
}
```

Setiap item memakai key stabil berdasarkan item Shopee, model Shopee, produk TikTok, dan SKU ID TikTok. Fingerprint mencakup SKU/nama sumber, SKU target, status aktif, dan identitas kedua marketplace agar perubahan setelah preview dapat dideteksi.

### Submit Endpoint

Tambahkan endpoint:

`POST /api/tiktok/bulk-missing-variants/sku-cleanup/{runId}/submit`

Payload berisi `revision`. Submit hanya menerima run `ready_for_review`, menolak revision lama dengan HTTP 409, dan tidak memproses item `unchanged` atau `blocked`.

Gunakan cache lock atau marketplace-operation lease agar hanya satu cleanup atau mutasi katalog terkait yang berjalan. Token marketplace disegarkan sekali pada awal run.

## Mutation Sequence

Urutan berikut berlaku untuk setiap kelompok produk dan mempertahankan kemampuan retry:

1. Muat ulang detail produk TikTok dan daftar model Shopee dari API marketplace masing-masing tanpa melakukan mutasi katalog.
2. Cocokkan kembali setiap target menggunakan product ID, SKU ID, model ID, seller SKU lama, nama sumber, dan SKU template target.
3. Blokir seluruh kelompok produk jika target ambigu, SKU target bentrok, SKU target sudah dipakai varian lain, atau penghapusan akan menyisakan nol varian TikTok.
4. Bangun satu payload TikTok `partial_edit` per produk yang mempertahankan semua SKU bukan target tanpa perubahan dan menghilangkan hanya SKU ID target.
5. Kirim payload TikTok satu kali per produk. Jangan menghapus target satu per satu.
6. Paksa refresh produk TikTok dan verifikasi semua SKU ID target sudah tidak aktif serta seluruh SKU bukan target masih ada.
7. Hanya untuk target TikTok yang terverifikasi terhapus, kirim Shopee `update_model` yang mengubah `model_sku` ke SKU template. Payload tidak boleh membawa nama, gambar, harga, stok, atau field model lain.
8. Paksa refresh item Shopee dan verifikasi model ID yang sama memakai SKU template, sementara identitas model tetap ada.
9. Setelah kedua sisi terverifikasi, perbarui cache dan mapping lokal:
   - `shopee_product_model.model_sku` dan `stock_master.shopee_seller_sku` menjadi SKU target;
   - pertahankan `stock_master.tiktok_product_id` sebagai tujuan pembuatan ulang;
   - kosongkan `stock_master.tiktok_sku` dan `stock_master.tiktok_seller_sku`;
   - pertahankan `sku_mappings.tiktok_product_id`, tetapi kosongkan `tiktok_sku_id`, `tiktok_sku_name`, dan data gambar TikTok lama;
   - jangan menandai Stock Master tersembunyi dari mapping.
10. Catat hasil tersensor per varian dan status akhir run, lalu bangun ulang preview bulk tambah varian.

## Failure and Retry Semantics

- Jika TikTok menolak batch delete, Shopee tidak diubah dan cache lokal tidak dibersihkan.
- Jika TikTok berhasil tetapi verifikasi gagal, Shopee tidak diubah. Run berstatus `partial` dan harus melakukan refresh sebelum retry.
- Jika TikTok sudah terhapus tetapi update Shopee gagal, item tetap `partial`. Audit run mempertahankan SKU target dan produk TikTok tujuan sehingga retry hanya mengulangi langkah Shopee setelah memastikan target TikTok tetap tidak ada.
- Jika Shopee merespons sukses tetapi refresh tidak mengonfirmasi SKU target, item tetap `submitted_unverified`; mapping final tidak ditulis sampai verifikasi berhasil.
- Submit ulang pada run yang sudah diklaim tidak boleh mengulangi batch delete. UI harus memuat status run yang ada.
- Item yang gagal tidak membatalkan item produk lain, tetapi satu produk TikTok diproses atomik sebagai kelompok agar SKU bukan target tidak ikut hilang.

## Safety Rules

- Preview tidak melakukan mutasi marketplace.
- Revision dan source fingerprint wajib diperiksa sebelum mutasi.
- Detail TikTok terbaru adalah sumber payload delete; cache lokal tidak boleh digunakan sendirian untuk membangun daftar SKU tersisa.
- Setiap SKU bukan target harus disalin dengan kontrak SKU lengkap yang sudah digunakan helper partial edit saat ini.
- Request TikTok diblokir jika daftar SKU tersisa kosong.
- Request Shopee hanya berisi item ID dan pasangan model ID/model SKU.
- SKU target maksimal 100 karakter dan wajib unik pada item Shopee serta produk TikTok tujuan.
- Semua payload audit menyensor kredensial dan signature.
- Tidak ada aksi live marketplace yang dijalankan oleh test suite.

## Testing

Backend TDD mencakup:

1. SKU target dibangun dari item ID dan nama varian Shopee terbaru.
2. Baris dengan SKU yang sudah sesuai template menjadi `unchanged` dan TikTok tidak ditargetkan.
3. Benturan SKU target pada Shopee atau TikTok menjadi `blocked`.
4. Produk yang akan kehilangan seluruh SKU TikTok menjadi `blocked`.
5. Preview menghasilkan revision stabil dan submit menolak revision stale.
6. Batch delete menghapus hanya SKU ID target dan mempertahankan kontrak lengkap SKU lainnya.
7. Kegagalan TikTok mencegah request Shopee.
8. Payload Shopee hanya mengubah `model_sku`.
9. Verifikasi sukses mempertahankan produk TikTok tujuan sambil mengosongkan identitas SKU TikTok lama.
10. Retry setelah TikTok sukses dan Shopee gagal tidak menghapus ulang SKU TikTok.
11. Audit menyensor token, secret, signature, dan authorization.

Frontend tests mencakup preview counts, modal konfirmasi, disabled state ketika tidak ada item eligible, hasil per varian, stale revision, dan refresh preview setelah submit.

## Deployment and Verification

1. Jalankan migration reconciliation audit bila belum diterapkan.
2. Jalankan focused backend tests dari repository root agar memakai SQLite `:memory:`.
3. Jalankan seluruh backend dan frontend test suite yang relevan.
4. Build frontend dengan Vite dan publikasikan `index.html` serta hashed assets ke `backend/public`.
5. Verifikasi halaman lokal menampilkan preview tanpa mutasi.
6. Jangan menjalankan submit live selama verifikasi pengembangan. Pengguna menjalankan aksi live melalui modal setelah meninjau jumlah dan daftar target aktual.

## Success Criteria

- Hanya model Shopee dengan SKU lama yang berbeda dari template yang diubah.
- Tidak ada field Shopee selain `model_sku` yang dikirim pada request update.
- Hanya SKU TikTok lama yang dipasangkan dengan perubahan Shopee tersebut yang dihapus.
- Seluruh varian TikTok bukan target tetap aktif dan tidak berubah.
- Baris dengan SKU yang sudah sesuai template dan baris bentrok tidak dimutasi.
- Setelah fresh verification, baris sukses tampil sebagai kandidat tambah varian TikTok dengan produk tujuan yang sama.
- Setiap tindakan destructive memiliki preview, revision check, audit tersensor, dan hasil per varian yang dapat dilanjutkan setelah kegagalan parsial.
