# Upload Otomatis Mass Update Gitashopcollection

## Tujuan

Mengunggah seluruh file Mass Update Shopee yang dibuat dari data terbaru di halaman `/marketplace/import` ke Seller Centre Gitashopcollection secara otomatis dan berurutan.

## Ruang Lingkup

- Target akun dikunci ke `Gitashopcollection`.
- Setiap job membuat ulang enam file dari generator Mass Update yang telah ada.
- Urutan file: Basic Info, Sales Info, Media Info, Shipping Info, DTS Info, lalu Republish Items.
- Republish Items tetap diunggah walaupun memiliki nol baris data.
- Sistem menunggu status pemrosesan Seller Centre untuk setiap file sebelum meneruskan ke file berikutnya.
- Halaman `/marketplace/import` menampilkan tombol mulai, status job aktif, progres per file, dan riwayat job.

## Batasan dan Pengaman

- Browser Seller Centre memakai profil persisten khusus Gitashopcollection; browser ini tidak memakai token API Shopee atau sesi STB.
- Sebelum membuat file dan sebelum submit setiap file, job memeriksa status runtime STB.
- Bila STB masih menjalankan sinkronisasi marketplace atau stok, job menunggu hingga aman. Bila batas tunggu habis, job berakhir dengan status `dibatalkan_aman`.
- Hanya satu job upload dapat aktif. Permintaan baru ditolak saat job lain masih berjalan.
- Identitas toko yang tampil di Seller Centre wajib cocok dengan Gitashopcollection. Ketidakcocokan menghentikan job sebelum file diunggah.
- Login, OTP, CAPTCHA, verifikasi tambahan, perubahan struktur halaman, penolakan file, atau tidak ditemukannya status proses mengakhiri job secara fail-closed.
- File berikutnya tidak boleh diunggah sesudah satu file gagal, tertunda verifikasi, atau gagal diproses Shopee.
- Tidak ada retry otomatis. Retry pengguna membuat job audit baru untuk menghindari submit ganda.

## Alur Job

1. Pengguna menekan `Upload Otomatis Gitashop` di `/marketplace/import`.
2. Backend membuat job tunggal dan mencatat waktu pemicu dalam WITA.
3. Worker memvalidasi runtime STB dan menunggu bila sinkronisasi masih aktif.
4. Worker membuat ulang enam XLSX dari data database terkini dan menyimpan metadata file.
5. Worker membuka atau memakai browser Seller Centre Gitashopcollection yang sudah login.
6. Worker memvalidasi toko aktif, membuka halaman Mass Update Upload, lalu mengunggah satu file.
7. Worker membaca status upload/pemrosesan Shopee sampai `Selesai` atau batas waktu tercapai.
8. Jika sukses, worker lanjut ke tipe file berikutnya; jika tidak, worker menghentikan job dan mencatat penyebab tersanitasi.
9. Setelah file terakhir, job ditandai `selesai`; jika ada kegagalan, job memakai status akhir yang sesuai.

## Audit Data

Setiap job dan item file menyimpan:

- akun tujuan dan identitas toko yang tervalidasi;
- jenis file, nama file, jumlah baris data, dan SHA-256;
- waktu dibuat, mulai upload, submit, dan selesai dalam WITA;
- status internal dan status pemrosesan Shopee;
- pesan error tersanitasi tanpa cookie, token, data login, atau data browser mentah.

Status job: `menunggu_stb`, `berjalan`, `menunggu_verifikasi`, `selesai`, `selesai_dengan_gagal`, atau `dibatalkan_aman`.

Status file: `menunggu`, `dibuat`, `diunggah`, `memproses`, `selesai`, `gagal`, atau `menunggu_verifikasi`.

## Integrasi Data Saat Ini

- Sumber file tetap `MarketplaceImportController` dan template di `backend/storage/app/import-marketplace/shopee-gita`.
- Sales Info memakai SKU dan stok terbaru, tanpa mengubah harga.
- Basic Info memakai nama dan deskripsi yang sudah dibersihkan untuk Shopee.
- Media Info hanya memakai URL gambar `https://cf.shopee.co.id/`.
- Shipping Info dan DTS Info hanya memperbarui row yang dapat dicocokkan dengan ID produk/varian.
- Republish Items saat ini dikosongkan secara eksplisit agar tidak memicu aksi republis yang tidak valid; Shopee harus tetap menerima unggahan nol baris sebelum job dapat dinyatakan selesai.

## Kriteria Penerimaan

- Job tidak dapat mulai ketika job upload lain aktif.
- Job tidak mengunggah saat STB aktif melakukan sinkronisasi yang relevan.
- Job tidak pernah mengunggah ke toko selain Gitashopcollection.
- Enam tipe file diproses serial dan tidak ada tipe lanjutan sesudah kegagalan.
- Riwayat menampilkan hasil dan audit setiap file tanpa data rahasia.
- Kondisi login/OTP/CAPTCHA menghasilkan `menunggu_verifikasi`, bukan submit ulang.
- Tes mencakup penguncian akun, penguncian job, status STB, urutan file, penghentian fail-closed, audit hash/jumlah row, dan kondisi Republish nol baris.
