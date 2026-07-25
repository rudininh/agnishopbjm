# 📱 Mobile Product Management - Dokumentasi

## Deskripsi
Halaman mobile-friendly untuk mengelola produk Shopee dan TikTok dengan kemampuan:
1. ✅ Tambah produk di Shopee dan TikTok
2. ✅ Tambah variant produk di Shopee dan TikTok
3. ✅ Mengubah stok varian dan harganya langsung di Shopee dan TikTok
4. ✅ Memasukkan harga modal (cost price) untuk perhitungan laba

## URL Akses
```
http://localhost/mobile/kelola-produk
```

## Fitur Utama

### 1. Daftar Produk
- **Filter Marketplace**: Semua / Shopee / TikTok
- **Pencarian**: Cari produk berdasarkan nama atau SKU
- **Infinite Scroll**: Muat lebih banyak produk dengan tombol "Muat Lebih Banyak"
- **Badge Marketplace**: Indikator visual untuk produk di Shopee dan/atau TikTok

### 2. Detail Produk & Varian
Klik produk untuk melihat:
- Gambar produk
- Daftar semua varian
- Info harga jual, harga modal, dan laba kotor per varian
- Stok per varian

### 3. Edit Varian (Per Varian)
Untuk setiap varian, Anda bisa edit:
- **Stok**: Jumlah stok yang tersedia
- **Harga Jual**: Harga jual ke customer
- **Harga Modal**: Harga beli/modal (untuk perhitungan profit)

### 4. Perhitungan Otomatis
- **Laba Kotor** = Harga Jual - Harga Modal
- **Margin Laba** = (Laba Kotor / Harga Jual) × 100%

## Struktur Backend

### Database Migration
File: `backend/database/migrations/2026_07_18_214404_add_cost_price_to_marketplace_tables.php`

Menambahkan kolom `cost_price` ke tabel:
- `shopee_products`
- `shopee_models` (untuk varian Shopee)
- `tiktok_products`
- `tiktok_skus` (untuk varian TikTok)
- `products` (tabel produk umum)

### API Controller
File: `backend/app/Http/Controllers/MobileProductController.php`

#### Endpoints:

1. **GET /api/mobile/products**
   - Mendapatkan daftar produk dengan filter
   - Parameters:
     - `search`: Pencarian nama/SKU
     - `marketplace`: all/shopee/tiktok
     - `page`: Halaman pagination
     - `per_page`: Jumlah item per halaman

2. **GET /api/mobile/products/detail**
   - Mendapatkan detail produk dengan varian
   - Parameters:
     - `product_id`: ID produk
     - `marketplace`: shopee/tiktok

3. **POST /api/mobile/products/update-stock-price**
   - Update stok, harga jual, dan harga modal
   - Body:
     ```json
     {
       "marketplace": "shopee",
       "product_id": "123456",
       "variant_id": "789",
       "stock": 100,
       "price": 50000,
       "cost_price": 30000
     }
     ```

4. **POST /api/mobile/products/update-cost-price**
   - Update hanya harga modal
   - Body:
     ```json
     {
       "marketplace": "shopee",
       "product_id": "123456",
       "variant_id": "789",
       "cost_price": 30000
     }
     ```

5. **GET /api/mobile/products/profit-calculation**
   - Kalkulasi profit untuk varian tertentu
   - Parameters:
     - `marketplace`: shopee/tiktok
     - `product_id`: ID produk
     - `variant_id`: ID varian

### Routes
File: `backend/routes/api.php`

```php
Route::prefix('mobile')->group(function () {
    Route::get('products', [MobileProductController::class, 'index']);
    Route::get('products/detail', [MobileProductController::class, 'show']);
    Route::post('products/update-stock-price', [MobileProductController::class, 'updateStockPrice']);
    Route::post('products/update-cost-price', [MobileProductController::class, 'updateCostPrice']);
    Route::get('products/profit-calculation', [MobileProductController::class, 'profitCalculation']);
});
```

## Struktur Frontend

### Halaman Vue
File: `frontend/src/pages/MobileProductManagement.vue`

#### Komponen Utama:
1. **Header**: Judul halaman dengan gradient background
2. **Search & Filter**: Input pencarian dan tab filter marketplace
3. **Product List**: Daftar produk dengan card design
4. **Product Detail Modal**: Bottom sheet modal dengan detail varian
5. **Edit Form**: Form inline untuk edit stok dan harga per varian
6. **Toast Notification**: Notifikasi sukses/error

#### State Management:
- `loading`: Status loading
- `products`: Array produk
- `selectedProductData`: Data produk yang dipilih
- `editForm`: Form data untuk edit varian
- `expandedVariants`: Array ID varian yang di-expand

### Router
File: `frontend/src/router/index.js`

```javascript
{
  path: '/mobile/kelola-produk',
  name: 'mobile-product-management',
  component: MobileProductManagement
}
```

## Design Mobile-First

### Responsif
- **Mobile**: Desain utama untuk layar kecil (< 768px)
- **Tablet/Desktop**: Max-width 600px, centered

### UX Features:
- Touch-friendly buttons dan input
- Bottom sheet modal untuk detail
- Smooth animations
- Loading states yang jelas
- Error handling yang user-friendly

### Styling:
- Modern gradient header
- Rounded corners
- Card-based layout
- Consistent spacing
- Color-coded marketplace badges (Shopee: orange, TikTok: black)

## Cara Penggunaan

### 1. Akses Halaman
Ada 2 cara untuk mengakses halaman ini:

**Cara 1: Melalui Menu Sidebar**
1. Buka aplikasi di browser: `http://localhost:5173/`
2. Klik menu **"Produk"** di sidebar (klik tombol + untuk expand)
3. Pilih **"📱 Kelola Produk Mobile"** (menu paling atas)

**Cara 2: Direct URL**
Buka browser dan kunjungi langsung:
```
http://localhost:5173/mobile/kelola-produk
```

### 2. Cari Produk
- Gunakan search box untuk mencari produk
- Pilih filter marketplace (Semua/Shopee/TikTok)

### 3. Pilih Produk
- Klik pada card produk untuk melihat detail
- Modal akan muncul dari bawah dengan animasi slide-up

### 4. Edit Varian
- Klik header varian untuk expand detail
- Edit field yang diperlukan (Stok, Harga Jual, Harga Modal)
- Klik tombol "💾 Simpan" untuk menyimpan perubahan
- Atau klik "🔄 Reset" untuk membatalkan perubahan

### 5. Lihat Laba
- Laba kotor otomatis terhitung: Harga Jual - Harga Modal
- Ditampilkan dalam warna hijau untuk visibility

## Tujuan Utama
Sistem ini dibangun untuk:
1. **Input Harga Modal**: Memasukkan harga modal ke database untuk setiap varian
2. **Perhitungan Profit**: Menghitung laba kotor dan laba bersih
3. **Manajemen Mudah**: Interface mobile-friendly untuk update cepat
4. **Dual Marketplace**: Mengelola Shopee dan TikTok dalam satu halaman

## Catatan Penting

### Database
- Kolom `cost_price` ditambahkan ke semua tabel marketplace
- Nullable, jadi produk lama tidak akan error
- Tipe: DECIMAL(12, 2) untuk presisi harga

### Update Scope
- Update saat ini hanya ke **database lokal**
- Belum sync otomatis ke API Shopee/TikTok
- Untuk sync ke marketplace, gunakan fitur existing di halaman lain

### Future Enhancements
- [ ] Sync langsung ke API Shopee/TikTok
- [ ] Bulk edit multiple varian
- [ ] Export data profit ke Excel
- [ ] Grafik analisis profit
- [ ] Filter berdasarkan rentang harga/profit
- [ ] History perubahan harga

## Testing

### Manual Testing
1. Buka halaman di browser mobile atau desktop
2. Test pencarian produk
3. Test filter marketplace
4. Test edit varian dan simpan
5. Verifikasi data tersimpan di database

### Database Check
```sql
-- Cek kolom cost_price sudah ada
DESCRIBE shopee_models;
DESCRIBE tiktok_skus;

-- Cek data cost_price
SELECT model_id, model_name, price, cost_price, (price - cost_price) as profit 
FROM shopee_models 
WHERE cost_price IS NOT NULL;
```

## Troubleshooting

### Migration Error
Jika migration gagal:
```bash
cd backend
php artisan migrate:rollback --step=1
php artisan migrate
```

### API Not Found
Pastikan routes sudah di-load:
```bash
cd backend
php artisan route:list | grep mobile
```

### Frontend Error
Clear cache dan rebuild:
```bash
cd frontend
npm run build
```

## Support
Untuk pertanyaan atau issues, hubungi tim development.

---
**Created**: 2026-07-18
**Version**: 1.0.0
**Author**: AgniShop BJM Development Team
