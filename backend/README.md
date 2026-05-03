# AgniShop Backend - Laravel API

Backend API application untuk AgniShop Banjarmasin. Built with Laravel 11, PostgreSQL, dan Laravel Sanctum authentication.

## Teknologi

- **Framework**: Laravel 11
- **Database**: PostgreSQL (optimized untuk Neon)
- **Authentication**: Laravel Sanctum (Token-based)
- **Architecture**: Clean Architecture + Domain Driven Design
- **Code Pattern**: Service Layer + Repository Pattern

## Requirements

- PHP 8.2+
- Composer
- PostgreSQL 12+
- Docker & Docker Compose (untuk deployment)

## Setup Local Development

### 1. Install Dependencies
```bash
composer install
```

### 2. Environment Configuration
```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` dan set database credentials:
```
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=agnishop
DB_USERNAME=agnishop
DB_PASSWORD=your_password
```

### 3. Database Setup
```bash
php artisan migrate --seed
```

### 4. Start Development Server
```bash
php artisan serve
```

API akan berjalan di `http://localhost:8000/api`

## Project Structure

```
app/
├── Http/
│   ├── Controllers/        # API Controllers
│   ├── Requests/          # Form Request Validation
│   └── Resources/         # API Response Resources
├── Services/              # Business Logic Layer
├── Repositories/          # Database Query Layer
├── Models/                # Eloquent Models
└── DTOs/                  # Data Transfer Objects (optional)

routes/
└── api.php               # API Routes

database/
├── migrations/           # Database Migrations
└── seeders/             # Database Seeders

tests/
├── Feature/             # Feature Tests
└── Unit/               # Unit Tests
```

## Architecture Pattern

### Clean Architecture Layers

```
┌─────────────────────────────────────────┐
│         HTTP Controllers                │
├─────────────────────────────────────────┤
│            Services                     │ (Business Logic)
├─────────────────────────────────────────┤
│          Repositories                   │ (Data Access)
├─────────────────────────────────────────┤
│         Eloquent Models                 │
├─────────────────────────────────────────┤
│           Database                      │
└─────────────────────────────────────────┘
```

### Flow Contoh: Create Product

```
Request → ProductController
   ↓
ProductRequest (Validation)
   ↓
ProductService::create()
   ↓
ProductRepository::create()
   ↓
Product Model + Database
   ↓
ProductResource (Response)
```

## API Endpoints

### Authentication

```
POST   /api/auth/register
POST   /api/auth/login
POST   /api/auth/logout         (requires: auth)
```

### Products (requires: auth)

```
GET    /api/products             (list dengan pagination)
POST   /api/products             (create)
GET    /api/products/{uuid}      (detail)
PUT    /api/products/{uuid}      (update)
DELETE /api/products/{uuid}      (delete)
```

### Categories (requires: auth)

```
GET    /api/categories
POST   /api/categories
GET    /api/categories/{uuid}
PUT    /api/categories/{uuid}
DELETE /api/categories/{uuid}
```

### Cart (requires: auth)

```
GET    /api/cart                    (get current cart)
POST   /api/cart/items              (add item)
PUT    /api/cart/items/{item}       (update quantity)
DELETE /api/cart/items/{item}       (remove item)
```

### Orders (requires: auth)

```
GET    /api/orders                (list user orders)
GET    /api/orders/{uuid}         (detail order)
POST   /api/orders                (checkout)
```

## Testing

### Run All Tests
```bash
php artisan test
```

### Run Specific Test
```bash
php artisan test tests/Feature/AuthTest.php
```

### Test Coverage
```bash
php artisan test --coverage
```

## Database Migrations

### Create Migration
```bash
php artisan make:migration create_table_name --table=table_name
```

### Run Migrations
```bash
php artisan migrate
```

### Rollback
```bash
php artisan migrate:rollback
```

### Refresh (Caution: Destructive!)
```bash
php artisan migrate:refresh --seed
```

## Deployment

### Docker Deployment

```bash
# Build image
docker-compose build

# Run containers
docker-compose up -d

# Run migrations
docker-compose exec app php artisan migrate --seed

# View logs
docker-compose logs -f app
```

### VPS/Cloud Deployment (without Docker)

```bash
# 1. Clone repository
git clone <repo-url> /var/www/agnishop-api
cd /var/www/agnishop-api

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Set environment
cp .env.example .env
php artisan key:generate

# 4. Setup database
php artisan migrate --seed

# 5. Set permissions
sudo chown -R www-data:www-data /var/www/agnishop-api
sudo chmod -R 755 storage bootstrap/cache

# 6. Supervisor untuk queue (jika menggunakan queues)
# Konfigurasi supervisor.conf
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start agnishop-worker:*
```

### Nginx Configuration
```nginx
server {
    listen 80;
    server_name api.agnishop.local;

    root /var/www/agnishop-api/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## Strategi Migrasi dari Go ke Laravel

### Fase 1: Persiapan (1-2 minggu)
- [ ] Setup backend Laravel dengan struktur lengkap
- [ ] Setup database PostgreSQL Neon
- [ ] Migrasi data dari database Go ke PostgreSQL
- [ ] Implementasi API endpoint di Laravel
- [ ] Testing API endpoints

### Fase 2: Implementasi Frontend (2-3 minggu)
- [ ] Setup Vue 3 project
- [ ] Implementasi Pinia store
- [ ] Implementasi components
- [ ] Implementasi pages (Login, Products, Cart, Orders)
- [ ] Testing aplikasi secara end-to-end

### Fase 3: Strangler Pattern (1-2 minggu)
- [ ] Deploy Laravel API di VPS parallel dengan Go API lama
- [ ] Update frontend untuk consume Laravel API
- [ ] Validasi feature parity
- [ ] Monitoring dan bug fixing

### Fase 4: Cutover & Cleanup (1 minggu)
- [ ] Full migration ke Laravel API
- [ ] Archive/Decommission Go API
- [ ] Performance optimization
- [ ] Documentation finalization

### Database Migration Script

```bash
# Export data dari Go database
pg_dump old_db > backup.sql

# Import ke PostgreSQL Neon
psql postgresql://user:password@host/agnishop < backup.sql

# Validate data integrity
SELECT COUNT(*) FROM products;
SELECT COUNT(*) FROM orders;
```

## Best Practices Implemented

✅ Clean Architecture
✅ Domain Driven Design
✅ Service Layer Pattern
✅ Repository Pattern
✅ Form Request Validation
✅ API Resources (Transform Response)
✅ Dependency Injection
✅ Type Hints
✅ UUID Primary Keys
✅ Database Indexing
✅ Foreign Keys + Cascade
✅ Unit & Feature Tests
✅ Error Handling
✅ Rate Limiting Ready
✅ CORS Support Ready

## Monitoring & Debugging

### Logging
```bash
tail -f storage/logs/laravel.log
```

### Tinker (REPL)
```bash
php artisan tinker

>>> User::count()
=> 10
>>> Product::where('stock', 0)->count()
=> 3
```

## Performa Optimization

### Database Indexing
Sudah diimplementasi pada:
- `users.email`
- `categories.name`
- `products.category_id`
- `carts.user_id`
- `orders.user_id`

### Query Optimization
- Menggunakan `with()` untuk eager loading (prevent N+1)
- Pagination untuk list endpoint
- Selective field loading dengan resources

### Caching (Optional)
```php
// Cache user roles
Cache::remember('user.roles.' . $user->id, 3600, function () {
    return $user->roles;
});
```

## Support & Troubleshooting

### Common Issues

1. **Database connection error**
   ```bash
   php artisan migrate --verbose
   ```

2. **Permission denied**
   ```bash
   chmod -R 755 storage bootstrap/cache
   chown -R www-data:www-data /var/www/agnishop-api
   ```

3. **Composer memory limit**
   ```bash
   COMPOSER_MEMORY_LIMIT=-1 composer install
   ```

## License

Proprietary - AgniShop BJM

## Support

Untuk pertanyaan atau issue, hubungi tim development.
