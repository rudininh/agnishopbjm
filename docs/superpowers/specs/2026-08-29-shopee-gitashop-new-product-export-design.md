# Shopee Gitashop New Product Export Design

**Goal:** Memisahkan katalog Shopee Agni Shop Banjarmasin yang sudah mempunyai identitas target Gitashopcollection dari produk atau varian yang belum ada di toko target, sehingga Mass Update tidak lagi melewatkan data secara diam-diam dan item baru dapat disiapkan melalui alur pembuatan produk yang sesuai.

## Context

Generator pada halaman `/marketplace/import` saat ini menyalin enam workbook Mass Update Gitashopcollection dari `backend/storage/app/import-marketplace/shopee-gita`, lalu hanya memperbarui sel pada baris target yang sudah ada. Baris baru tidak pernah ditambahkan.

Snapshot pada 29 Agustus 2026 menunjukkan:

- katalog sumber mempunyai 65 produk dan 1.992 varian;
- template Sales Info Gitashopcollection mempunyai pemetaan 60 produk dan 1.730 baris;
- 5 produk sumber dengan total 82 varian tidak mempunyai produk target;
- 217 SKU pada produk yang sudah dikenal template tidak cocok dengan pemetaan target saat ini;
- `Khiban series Rayon Bandana tali Rayon Airflow premium` adalah produk sumber baru dengan 17 varian;
- `NINJA NON RESLETING.ninja pinguin.ninja malay.inner hijab premium(bahan kaos rayon)` adalah produk sumber baru dengan 12 varian;
- seluruh template Mass Update lokal terakhir diperbarui pada 13 Juni 2026.

Kedua produk contoh dibuat pada akun sumber pada 28 Agustus 2026 dan belum ada di Gitashopcollection. Karena itu keduanya belum memiliki `item_id` dan `model_id` target. File Mass Update Shopee tidak boleh digunakan untuk membuat identitas target tersebut.

## Scope

Alur baru mencakup:

1. preflight coverage read-only untuk membandingkan katalog sumber terbaru dengan pemetaan target pada template Gitashopcollection;
2. klasifikasi setiap varian sebagai `mass_update_ready`, `new_product`, `new_variant`, `sku_changed`, atau `blocked`;
3. laporan lengkap yang selalu menyertai download dan tidak membiarkan baris sumber hilang tanpa status;
4. ZIP Mass Update yang hanya berisi target dengan `item_id` dan `model_id` Gitashopcollection yang valid;
5. workbook pembuatan produk baru berdasarkan template resmi Shopee yang dipasang dan divalidasi secara eksplisit;
6. review terpisah sebelum workbook pembuatan produk baru diunggah ke Seller Centre;
7. penyegaran pemetaan target setelah produk atau varian baru selesai dibuat di Gitashopcollection.

## Non-Goals

- Tidak memasukkan ID sumber Agni Shop Banjarmasin ke kolom ID target Gitashopcollection.
- Tidak membuat `item_id` atau `model_id` Shopee buatan.
- Tidak menambahkan baris tanpa target ke workbook Mass Update.
- Tidak mengunggah workbook pembuatan produk baru secara otomatis pada iterasi pertama.
- Tidak menebak kategori, atribut wajib, merek, logistik, berat, dimensi, atau data wajib lain yang tidak tersedia atau belum diverifikasi.
- Tidak mengubah alur TikTok, Stock Master, atau sinkronisasi stok marketplace.

## User Experience

Bagian **Download Mass Update Shopee** menampilkan kartu preflight dengan:

- waktu refresh katalog sumber;
- jumlah produk dan varian sumber;
- jumlah produk/varian siap Mass Update;
- jumlah produk baru;
- jumlah varian baru pada produk lama;
- jumlah perubahan SKU yang perlu ditinjau;
- jumlah baris diblokir;
- usia dan waktu pemasangan template target/creation yang aktif.

Daftar pengecualian dapat dicari berdasarkan nama produk, nama varian, atau seller SKU. Setiap baris menampilkan alasan dan tindakan berikutnya.

Tersedia tiga download terpisah:

1. **Download Mass Update Produk Lama** untuk baris `mass_update_ready` saja;
2. **Download Produk Baru Gitashop** untuk produk `new_product` yang lulus validasi creation;
3. **Download Laporan Pengecualian** berisi seluruh `new_product`, `new_variant`, `sku_changed`, dan `blocked`.

Jika workbook creation resmi belum dipasang, tombol produk baru dinonaktifkan dengan instruksi memasang template. Mass Update lama tetap dapat diunduh hanya jika nama file dan ringkasan dengan jelas menyatakan bahwa file tersebut parsial. Download ZIP lama tidak boleh lagi menyampaikan kesan bahwa seluruh katalog sudah tercakup.

## Architecture

### Coverage Service

Tambahkan service khusus, misalnya `ShopeeGitaExportCoverageService`, yang membaca:

- katalog sumber dari `shopee_product`, `shopee_product_model`, `stock_master`, gambar, dan mapping aktif;
- pemetaan target dari workbook Sales Info Gitashopcollection yang terpasang;
- metadata template aktif dari penyimpanan aplikasi.

Service menghasilkan snapshot deterministik dengan summary, item list, dan SHA-256 revision. Normalisasi seller SKU bersifat trim dan case-insensitive hanya untuk pencarian; nilai asli tetap dipertahankan dalam output.

Klasifikasi dilakukan per varian:

- `mass_update_ready`: pasangan source item + seller SKU mempunyai tepat satu target item/model;
- `new_product`: tidak ada satu pun pemetaan target untuk source item;
- `sku_changed`: source item sudah dikenal dan tepat satu baris target pada source item yang sama mempunyai nama varian ternormalisasi yang sama, tetapi seller SKU berbeda;
- `new_variant`: source item sudah dikenal, seller SKU tidak cocok, dan tidak ada tepat satu kecocokan nama varian yang dapat membuktikan perubahan SKU;
- `blocked`: identitas duplikat, ambigu, kosong, template rusak, atau data wajib tidak lengkap.

Nama varian target dibaca dari kolom Variation Name pada Sales Info. Normalisasi nama hanya merapikan Unicode, kapitalisasi, dan spasi; tanda baca tidak dibuang. Lebih dari satu kecocokan nama menjadi `blocked`, bukan dipilih berdasarkan posisi baris.

Service tidak melakukan HTTP marketplace dan tidak mengubah database.

### API

Tambahkan endpoint read-only:

`GET /api/marketplace/import/shopee-gita/coverage`

Respons memuat `revision`, metadata template, summary, dan item pengecualian yang dipaginasi.

Endpoint download menerima revision:

- `GET /api/marketplace/import/shopee-gita/mass-update?revision=...`
- `GET /api/marketplace/import/shopee-gita/new-products?revision=...`
- `GET /api/marketplace/import/shopee-gita/exceptions?revision=...`

Download ditolak dengan HTTP 409 jika katalog atau template berubah setelah preview.

### Existing Mass Update Generator

Generator Mass Update memakai snapshot coverage yang sama. Writer hanya memproses `mass_update_ready` dan memverifikasi bahwa setiap baris yang diharapkan ditemukan tepat satu kali pada workbook target.

Writer membangun ulang area data setiap workbook agar baris yang tidak termasuk snapshot tidak tertinggal: Basic Info dan Media Info difilter per target product, sedangkan Sales Info, Shipping Info, dan DTS Info difilter per pasangan target item/model. Republish Items tetap memakai kontrak nol baris yang sudah ada. Header, formula, style, validation, dan sheet pendukung dari template resmi dipertahankan.

ZIP menyertakan `coverage_report.csv` dengan jumlah sumber, jumlah tertulis, dan seluruh pengecualian. Header respons dan nama arsip membedakan export lengkap dari export parsial.

Generator menolak template target yang mempunyai key kosong, key duplikat, atau target item/model duplikat yang ambigu.

### Official Creation Template

Workbook creation tidak dibuat dari asumsi kolom. Admin memasang file resmi Shopee melalui kontrol **Pasang Template Produk Baru**. Backend menyimpan file di storage, menghitung SHA-256, mencatat waktu pemasangan, lalu memvalidasi signature workbook, sheet yang diperlukan, baris header, dan kolom wajib yang didukung adapter.

Template dianggap tidak aktif jika formatnya tidak dikenal. File lama tetap dipertahankan sampai file baru lulus validasi dan ditukar secara atomik.

Adapter creation mengisi hanya field yang dapat dibuktikan dari detail sumber terbaru, antara lain judul, deskripsi, kategori, seller SKU, nama variasi, harga, stok, dan gambar. Berat, dimensi, atribut kategori, merek, dan logistik hanya ditulis jika tersedia dan valid. Produk dengan satu field wajib yang belum lengkap menjadi `blocked` dan dicantumkan dalam laporan; generator tidak menulis placeholder palsu.

Saat download creation diminta, backend mengambil detail produk sumber secara read-only dari Shopee Agni Shop Banjarmasin menggunakan integrasi sumber yang sudah ada. Detail segar tersebut harus tetap cocok dengan item/model/SKU pada revision preview. Respons detail menjadi sumber kategori, atribut, berat, dimensi, logistik, gambar, harga, dan variasi yang tidak disimpan lengkap di cache lokal saat ini. Kegagalan membaca detail atau perubahan identitas membatalkan workbook produk terkait; tidak ada HTTP mutasi ke akun sumber maupun target.

Workbook creation menyimpan SKU sumber sebagai identitas rekonsiliasi, tetapi tidak membawa `item_id` atau `model_id` akun sumber sebagai identitas target.

### Target Refresh After Creation

Setelah produk baru berhasil dibuat secara manual di Gitashopcollection, admin mengunduh template Mass Update terbaru dari Seller Centre dan memasangnya melalui kontrol **Perbarui Template Target Gitashop**. Backend memvalidasi keenam workbook sebagai satu set dan menukarnya secara atomik.

Preflight berikutnya harus memindahkan produk yang sudah memperoleh target item/model dari `new_product` atau `new_variant` ke `mass_update_ready`. Jika pemetaan masih ambigu, status tetap diblokir.

## Automatic Upload Interaction

Job **Upload Otomatis Gitashop** tetap hanya memproses enam file Mass Update. Sebelum job dibuat, service coverage wajib memastikan manifest job sama persis dengan kelompok `mass_update_ready` dan mencatat jumlah pengecualian.

Job tidak boleh gagal hanya karena ada produk baru, tetapi UI dan audit harus menyatakan bahwa job bersifat parsial. Workbook creation tidak ikut diunggah otomatis pada iterasi pertama karena pembuatan produk membutuhkan review atribut dan dapat menciptakan listing baru secara permanen.

## Error Handling

- Template target tidak ada/rusak: Mass Update dan automatic upload diblokir dengan pesan tindakan yang spesifik.
- Template creation tidak ada/rusak: laporan tetap tersedia; download produk baru dinonaktifkan.
- Revision berubah: download ditolak HTTP 409 dan UI memuat ulang preflight.
- Source SKU duplikat atau mapping target ambigu: baris `blocked`; tidak dipilih berdasarkan urutan pertama.
- Field wajib creation tidak lengkap: produk diblokir seluruhnya agar workbook tidak membuat listing parsial.
- Salah satu dari enam template target gagal validasi: tidak ada file template aktif yang diganti.
- Kegagalan membuat satu workbook: arsip tidak dikirim sebagai hasil sukses.

## Testing

Backend TDD mencakup:

1. produk sumber tanpa target diklasifikasikan `new_product` dan tidak muncul pada Mass Update;
2. varian sumber baru pada produk target diklasifikasikan `new_variant`;
3. perubahan seller SKU tidak dianggap otomatis sebagai varian baru;
4. dua mapping target untuk key yang sama menjadi `blocked`;
5. summary selalu sama dengan total seluruh klasifikasi;
6. revision berubah ketika katalog atau template berubah;
7. download dengan revision lama ditolak;
8. ZIP Mass Update menyertakan laporan pengecualian dan jumlah yang konsisten;
9. produk baru tidak pernah memakai item/model ID sumber sebagai ID target;
10. template creation tidak valid tidak menggantikan template aktif;
11. field creation wajib yang hilang memblokir seluruh produk;
12. set enam template target diganti secara atomik;
13. manifest automatic upload hanya mencakup `mass_update_ready` dan mengaudit jumlah pengecualian.

Frontend tests mencakup summary preflight, pencarian pengecualian, label export parsial, disabled state ketika template creation belum tersedia, stale revision, dan pesan pemasangan template.

## Deployment and Verification

1. Jalankan focused backend dan frontend tests dari repository root.
2. Pasang fixture template resmi hanya untuk test; jangan memakai workbook produksi dalam test suite.
3. Build frontend dan publikasikan hasil Vite ke `backend/public` sesuai prosedur proyek.
4. Verifikasi halaman lokal menampilkan dua produk contoh sebagai `new_product` dengan masing-masing 17 dan 12 varian.
5. Verifikasi Mass Update tidak memuat kedua produk dan `coverage_report.csv` mencantumkannya.
6. Pasang template creation resmi melalui UI, lalu verifikasi workbook produk baru secara lokal tanpa mengunggahnya.
7. Jangan menjalankan automatic creation/upload selama verifikasi pengembangan.

## Success Criteria

- Tidak ada produk atau varian sumber yang hilang tanpa klasifikasi dan alasan.
- Mass Update hanya membawa item/model target Gitashopcollection yang nyata dan unik.
- Kedua produk contoh tampil pada alur **Produk Baru Gitashop**, bukan dipaksakan ke Mass Update.
- Pengguna dapat membedakan dengan jelas export lengkap, parsial, produk baru, dan baris diblokir.
- Workbook creation hanya tersedia setelah template resmi dan seluruh field wajib tervalidasi.
- Setelah template target diperbarui pascapembuatan, produk baru berpindah ke cakupan Mass Update secara deterministik.
- Automatic upload tidak pernah mengunggah listing baru tanpa review dan tindakan pengguna yang terpisah.
