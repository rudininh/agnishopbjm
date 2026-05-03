# Strategi Migrasi Go → Laravel: Panduan Lengkap

## Ringkasan Eksekutif

AgniShop akan dimigrasi dari backend Go ke Laravel dengan frontend Vue 3. Menggunakan **Strangler Pattern** untuk menghindari downtime dan risk.

**Timeline Estimasi**: 6-8 minggu
**Approach**: Blue-Green Deployment (parallel old & new systems)

---

## Fase 1: Persiapan & Perencanaan (Minggu 1-2)

### 1.1 Inventory Sistem Go Lama

**Endpoints yang perlu dimigrasikan:**
- Shopee Integration
- TikTok Integration
- Product Management
- Cart Management
- Order Management
- Authentication

**Database Objects Go:**
```sql
-- Assume existing Go database structure:
-- users, products, categories, cart, orders, order_items
```

### 1.2 Setup Backend Laravel

✅ **Sudah selesai:**
- Project structure clean architecture
- Database schema PostgreSQL
- Service + Repository layer
- Authentication dengan Laravel Sanctum
- API Resources untuk response normalization
- Migration files lengkap

**Langkah eksekusi:**
```bash
cd backend

# 1. Install dependencies
composer install

# 2. Setup environment
cp .env.example .env
php artisan key:generate

# 3. Migrate database (PostgreSQL Neon)
php artisan migrate --seed

# 4. Verify API
php artisan serve
# Test: http://localhost:8000/api/products
```

### 1.3 Setup Database PostgreSQL (Neon)

**Langkah:**
```bash
# 1. Create Neon database
# Go to https://console.neon.tech
# Create project: agnishop-production

# 2. Get connection string
postgresql://user:password@ep-xxx-xxx.neon.tech/agnishop?sslmode=require

# 3. Set di Laravel .env
DB_CONNECTION=pgsql
DB_HOST=ep-xxx-xxx.neon.tech
DB_DATABASE=agnishop
DB_USERNAME=user
DB_PASSWORD=password
DB_SSLMODE=require

# 4. Run migrations
php artisan migrate --seed
```

---

## Fase 2: Data Migration (Minggu 2-3)

### 2.1 Export Data dari Go Database

```bash
# Backup Go database (dari aplikasi lama)
pg_dump --verbose \
  --dbname=postgresql://user:pass@old-server:5432/agnishop_old \
  > backup_go.sql

# Atau gunakan mysqldump jika Go pakai MySQL
mysqldump -u root -p agnishop_old > backup_go.sql
```

### 2.2 Transform & Clean Data

**Script Python untuk data transformation:**
```python
# scripts/migrate_data.py
import psycopg2
import uuid
from datetime import datetime

old_conn = psycopg2.connect("dbname=agnishop_old user=postgres")
new_conn = psycopg2.connect("dbname=agnishop user=postgres host=neon.tech")

old_cur = old_conn.cursor()
new_cur = new_conn.cursor()

# Migrate users
old_cur.execute("SELECT id, name, email, password FROM users")
for id, name, email, password in old_cur.fetchall():
    new_uuid = str(uuid.uuid4())
    new_cur.execute(
        "INSERT INTO users (uuid, name, email, password, created_at, updated_at) VALUES (%s, %s, %s, %s, %s, %s)",
        (new_uuid, name, email, password, datetime.now(), datetime.now())
    )
    # Store id mapping untuk foreign key
    # id_mapping[id] = new_uuid

# Commit changes
new_conn.commit()
new_cur.close()
new_conn.close()
old_cur.close()
old_conn.close()
```

### 2.3 Validasi Data Integrity

```sql
-- Check record counts
SELECT 'users' as table_name, COUNT(*) as count FROM users
UNION ALL
SELECT 'products', COUNT(*) FROM products
UNION ALL
SELECT 'orders', COUNT(*) FROM orders;

-- Check foreign keys
SELECT * FROM orders WHERE user_id NOT IN (SELECT uuid FROM users);
SELECT * FROM products WHERE category_id NOT IN (SELECT uuid FROM categories);

-- Check duplicate emails
SELECT email, COUNT(*) FROM users GROUP BY email HAVING COUNT(*) > 1;
```

---

## Fase 3: API Implementation (Minggu 3-4)

### 3.1 Feature Parity Checklist

**Authentication**
- ✅ Register endpoint
- ✅ Login endpoint  
- ✅ Logout endpoint

**Products**
- ✅ List products (with pagination)
- ✅ Get product detail
- ✅ Create product
- ✅ Update product
- ✅ Delete product

**Categories**
- ✅ List categories
- ✅ Get category detail
- ✅ Create category
- ✅ Update category
- ✅ Delete category

**Cart**
- ✅ Get current cart
- ✅ Add item to cart
- ✅ Update cart item quantity
- ✅ Remove item from cart

**Orders**
- ✅ List user orders
- ✅ Get order detail
- ✅ Checkout (create order)

### 3.2 Testing API Endpoints

**Dengan Postman/Insomnia:**

```
1. Register
POST /api/auth/register
Body: {
  "name": "Test User",
  "email": "test@example.com",
  "password": "Password123",
  "password_confirmation": "Password123"
}

2. Login
POST /api/auth/login
Body: {
  "email": "test@example.com",
  "password": "Password123"
}
Response: { "token": "xxx", "data": { ... } }

3. Get Products
GET /api/products
Header: Authorization: Bearer {token}

4. Add to Cart
POST /api/cart/items
Header: Authorization: Bearer {token}
Body: {
  "product_id": "{uuid}",
  "quantity": 2
}

5. Checkout
POST /api/orders
Header: Authorization: Bearer {token}
Body: {
  "shipping_address": "Jl. Test No. 123",
  "payment_method": "bank_transfer"
}
```

**Run PHP Unit Tests:**
```bash
php artisan test

# With coverage
php artisan test --coverage-html=coverage
```

---

## Fase 4: Frontend Implementation (Minggu 4-6)

### 4.1 Setup Vue 3 Project

✅ **Sudah selesai:**
- Vite configuration
- Pinia store setup (auth, cart)
- Axios API client dengan interceptor
- Router setup dengan auth guard
- Components (Navbar, ProductCard)
- Pages (Login, Products, Cart, Orders)

**Langkah eksekusi:**
```bash
cd frontend

# 1. Install dependencies
npm install

# 2. Development
npm run dev
# Akses: http://localhost:5173

# 3. Build production
npm run build
# Output: dist/
```

### 4.2 Integration Testing

**Skenario test end-to-end:**

1. **User Journey: Login → Browse Products → Add to Cart → Checkout**
   ```javascript
   // tests/integration.spec.js
   describe('User Journey', () => {
     it('should complete full purchase flow', async () => {
       // 1. Navigate to login
       await page.goto('http://localhost:5173/login')
       
       // 2. Login
       await page.fill('input[id="email"]', 'test@example.com')
       await page.fill('input[id="password"]', 'Password123')
       await page.click('.btn-login')
       
       // 3. Wait redirect to products
       await page.waitForURL('**/products')
       
       // 4. Add to cart
       await page.click('button:has-text("Tambah ke Keranjang")')
       
       // 5. Go to cart
       await page.goto('http://localhost:5173/cart')
       
       // 6. Checkout
       await page.fill('textarea[id="address"]', 'Jl. Test')
       await page.selectOption('select[id="payment"]', 'bank_transfer')
       await page.click('.btn-checkout')
       
       // 7. Verify success
       await page.waitForURL('**/orders/**')
       expect(await page.isVisible('.order-card')).toBe(true)
     })
   })
   ```

---

## Fase 5: Strangler Pattern Implementation (Minggu 6-7)

### 5.1 Parallel Deployment Strategy

**Skenario 1: Dual Deployment**

```
┌─ Load Balancer ─────────────────┐
│                                 │
├─ Old API (Go) [90% traffic]    │
│  - /api/shopee/*                │
│  - /api/tiktok/*                │
│  - /api/products (legacy)       │
│                                 │
├─ New API (Laravel) [10% traffic]
│  - /api/products (new)          │
│  - /api/cart                    │
│  - /api/orders                  │
│  - /api/auth                    │
│                                 │
└─ Frontend (Vue 3)              │
   - Calls both APIs via adapter  │
```

### 5.2 Adapter Pattern untuk Multiple APIs

```javascript
// src/services/api-adapter.js
export const apiAdapter = {
  async getProducts() {
    // Try new Laravel API first
    try {
      return await api.get('/products')
    } catch (error) {
      // Fallback to old Go API
      return await legacyApi.get('/api/products')
    }
  },

  async createOrder(data) {
    // Only use new Laravel API
    return await api.post('/orders', data)
  },

  async shopeeSync() {
    // Always use old Go API (not migrated yet)
    return await legacyApi.post('/api/sync-shopee')
  }
}
```

### 5.3 Traffic Shifting

**Day 1-3: 10% Laravel, 90% Go**
```nginx
upstream go_backend {
    server old-api.local:8080;
}

upstream laravel_backend {
    server new-api.local:8000;
}

server {
    location /api {
        access_log /var/log/nginx/api_access.log main;
        
        # 10% to Laravel, 90% to Go
        random two least_conn;
        server old-api.local:8080 weight=9;
        server new-api.local:8000 weight=1;
    }
}
```

**Day 4-7: 50% Laravel, 50% Go**
```nginx
server old-api.local:8080 weight=5;
server new-api.local:8000 weight=5;
```

**Day 8+: 100% Laravel, 0% Go**
```nginx
server new-api.local:8000;
```

---

## Fase 6: Deployment (Minggu 7-8)

### 6.1 Backend Deployment ke VPS

**Using Docker:**
```bash
# 1. Build and push image
docker build -t agnishop-api:1.0.0 .
docker tag agnishop-api:1.0.0 registry.example.com/agnishop-api:1.0.0
docker push registry.example.com/agnishop-api:1.0.0

# 2. SSH ke VPS
ssh deploy@api.agnishop.local

# 3. Deploy dengan docker-compose
cd /opt/agnishop-api
git pull origin main
docker-compose pull
docker-compose up -d
docker-compose exec app php artisan migrate
```

**Using Traditional VPS:**
```bash
# 1. SSH ke VPS
ssh deploy@api.agnishop.local

# 2. Clone dan setup
cd /var/www
git clone git@github.com:agnishop/api.git agnishop-api
cd agnishop-api
composer install --no-dev -o

# 3. Configure Nginx
sudo ln -s /var/www/agnishop-api/nginx.conf /etc/nginx/sites-available/agnishop-api
sudo nginx -t
sudo systemctl reload nginx

# 4. Setup PHP-FPM
sudo systemctl start php8.2-fpm
sudo systemctl enable php8.2-fpm

# 5. Database
php artisan migrate --seed
php artisan cache:clear
php artisan config:cache
```

### 6.2 Frontend Deployment ke Vercel

```bash
# 1. Install Vercel CLI
npm install -g vercel

# 2. Login
vercel login

# 3. Deploy
cd frontend
vercel --prod

# 4. Set environment
vercel env add API_URL https://api.agnishop.local

# 5. Redeploy
vercel --prod
```

**Atau via GitHub:**
1. Push ke GitHub
2. Connect repository di Vercel dashboard
3. Set environment: `VITE_API_URL=https://api.agnishop.local`
4. Deploy

---

## Fase 7: Monitoring & Rollback Plan

### 7.1 Health Checks

```bash
# Backend health check
GET /api/health
Response: { "status": "ok", "database": "connected" }

# Frontend health check
GET /health
Response: { "status": "ok", "api": "reachable" }
```

**Implement di backend:**
```php
// routes/api.php
Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        return response()->json([
            'status' => 'ok',
            'database' => 'connected',
            'timestamp' => now()
        ]);
    } catch (\Exception $e) {
        return response()->json([
            'status' => 'error',
            'database' => 'disconnected'
        ], 500);
    }
});
```

### 7.2 Monitoring & Alerting

**Metrik yang dipantau:**
- API response time (< 200ms)
- Error rate (< 1%)
- Database connection pool
- Memory usage (< 70%)
- Disk space (> 20% free)

**Setup dengan Prometheus + Grafana:**
```yaml
# prometheus.yml
global:
  scrape_interval: 15s

scrape_configs:
  - job_name: 'agnishop-api'
    static_configs:
      - targets: ['api.agnishop.local:8000/metrics']
```

### 7.3 Rollback Procedure

**Jika ada critical issue:**

```bash
# 1. Revert traffic ke Go API (100%)
# Update load balancer config

# 2. Rollback Laravel API
cd /var/www/agnishop-api
git revert HEAD --no-edit
composer install
php artisan migrate:rollback --step=1

# 3. Verify Go API is stable
curl https://api-old.agnishop.local/health

# 4. Notify stakeholders
# Post-mortem & fix
```

---

## Fase 8: Decommission & Optimization (Minggu 8+)

### 8.1 Archive Go API

```bash
# 1. Backup Go database
pg_dump old_db > /backups/agnishop-go-final-$(date +%Y%m%d).sql

# 2. Stop Go services
sudo systemctl stop agnishop-go-api

# 3. Archive code & data
tar -czf /archive/agnishop-go-$(date +%Y%m%d).tar.gz /var/www/agnishop-go

# 4. Document
# - Last commit hash
# - Database schema version
# - Migration notes
```

### 8.2 Performance Optimization

**Database:**
```sql
-- Analyze query plans
EXPLAIN ANALYZE SELECT * FROM products WHERE category_id = $1;

-- Add indexes jika diperlukan
CREATE INDEX idx_orders_user_status ON orders(user_id, status);
```

**Caching:**
```php
// app/Http/Controllers/ProductController.php
public function index(Request $request)
{
    $cacheKey = 'products.page.' . $request->page . '.per_page.' . $request->per_page;
    
    $products = Cache::remember($cacheKey, 3600, function () use ($request) {
        return $this->service->paginate($request->query('per_page', 20));
    });
    
    return ProductResource::collection($products);
}
```

---

## Checklist Migrasi

### Pre-Migration
- [ ] Backup Go database
- [ ] Backup Go source code
- [ ] Data migration script tested
- [ ] API endpoints tested & documented
- [ ] Frontend fully tested
- [ ] Deployment tested (dry-run)

### Migration Day
- [ ] Execute data migration
- [ ] Verify data integrity
- [ ] Deploy Laravel API
- [ ] Deploy Vue 3 frontend
- [ ] Run smoke tests
- [ ] Start traffic shifting (10% → 50% → 100%)

### Post-Migration
- [ ] Monitor error rates & performance
- [ ] Rollback plan ready (24-48 jam)
- [ ] Performance optimization
- [ ] Documentation finalization
- [ ] Decommission old system
- [ ] Team training

---

## Risk Mitigation

| Risk | Mitigation |
|------|-----------|
| Data loss | Backup & verify before migration |
| Service downtime | Blue-green deployment, traffic shifting |
| Performance degradation | Load testing, caching strategy |
| Security issues | CORS config, rate limiting, input validation |
| User confusion | Clear communication, UI consistency |

---

## Support & Troubleshooting

### Common Issues

**Issue: Database migration fails**
```bash
# Solution
php artisan migrate:rollback
php artisan migrate --step=1 --verbose
```

**Issue: CORS error on frontend**
```php
// config/cors.php
'allowed_origins' => ['https://agnishop.vercel.app'],
```

**Issue: 502 Bad Gateway**
```bash
# Check PHP-FPM
sudo systemctl restart php8.2-fpm
# Check Nginx
sudo nginx -t
sudo systemctl reload nginx
```

---

## Timeline

| Minggu | Deliverable |
|--------|-------------|
| 1-2 | Backend Laravel setup, database PostgreSQL |
| 2-3 | Data migration, API endpoints completed |
| 3-4 | Frontend Vue 3, integration testing |
| 4-6 | End-to-end testing, bug fixes |
| 6-7 | Strangler pattern, traffic shifting |
| 7-8 | Deployment production, monitoring |
| 8+ | Optimization, decommission old system |

**Total**: 6-8 minggu

---

**Generated**: 2026-05-02  
**Version**: 1.0  
**Status**: Ready for Implementation
