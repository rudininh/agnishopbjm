# DEPLOYMENT GUIDE - AgniShop

Panduan lengkap deployment untuk backend Laravel, frontend Vue 3, dan database PostgreSQL.

---

## 1. Deployment Backend (Laravel API)

### Option A: Docker (Recommended)

#### Prerequisites
- Docker Engine 20.10+
- Docker Compose 2.0+

#### Langkah-langkah

1. **Set environment variables**
   ```bash
   cd backend
   cp .env.example .env
   
   # Generate application key
   php artisan key:generate
   
   # Update .env dengan database connection
   DB_HOST=postgres
   DB_DATABASE=agnishop
   DB_USERNAME=agnishop
   DB_PASSWORD=secure_password_here
   ```

2. **Build dan run dengan Docker Compose**
   ```bash
   docker-compose up -d --build
   
   # Run migrations
   docker-compose exec app php artisan migrate --seed
   
   # Check logs
   docker-compose logs -f app
   ```

3. **Verify deployment**
   ```bash
   curl http://localhost/api/health
   ```

#### Stop & cleanup
```bash
docker-compose down
# Remove volumes (destructive!)
docker-compose down -v
```

---

### Option B: Traditional VPS / Cloud Server

#### Prerequisites
- Ubuntu 20.04+ / CentOS 8+
- PHP 8.2
- PostgreSQL 12+
- Nginx
- Composer

#### Langkah-langkah

1. **SSH ke server**
   ```bash
   ssh deploy@api.agnishop.local
   ```

2. **Install dependencies**
   ```bash
   # Update system
   sudo apt update && sudo apt upgrade -y
   
   # Install PHP & extensions
   sudo apt install -y php8.2-fpm php8.2-cli php8.2-pgsql php8.2-zip php8.2-intl
   
   # Install PostgreSQL
   sudo apt install -y postgresql postgresql-contrib
   
   # Install Nginx
   sudo apt install -y nginx
   
   # Install Composer
   curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer
   ```

3. **Setup PostgreSQL**
   ```bash
   sudo -u postgres psql
   
   -- Inside psql:
   CREATE USER agnishop WITH PASSWORD 'secure_password';
   CREATE DATABASE agnishop OWNER agnishop;
   GRANT ALL PRIVILEGES ON DATABASE agnishop TO agnishop;
   \q
   ```

4. **Deploy aplikasi**
   ```bash
   cd /var/www
   sudo git clone git@github.com:agnishop/api.git agnishop-api
   sudo chown -R deploy:deploy agnishop-api
   cd agnishop-api
   
   # Install PHP dependencies
   composer install --no-dev --optimize-autoloader
   
   # Setup environment
   cp .env.example .env
   php artisan key:generate
   
   # Update .env database credentials
   nano .env
   # DB_HOST=localhost
   # DB_DATABASE=agnishop
   # DB_USERNAME=agnishop
   # DB_PASSWORD=secure_password
   
   # Run migrations
   php artisan migrate --seed
   ```

5. **Configure Nginx**
   ```bash
   sudo tee /etc/nginx/sites-available/agnishop-api > /dev/null <<EOF
   server {
       listen 80;
       server_name api.agnishop.local;
       
       root /var/www/agnishop-api/public;
       index index.php;
       
       location / {
           try_files \$uri \$uri/ /index.php?\$query_string;
       }
       
       location ~ \.php$ {
           fastcgi_pass unix:/run/php/php8.2-fpm.sock;
           fastcgi_index index.php;
           fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
           include fastcgi_params;
       }
       
       location ~ /\.ht {
           deny all;
       }
   }
   EOF
   
   # Enable site
   sudo ln -s /etc/nginx/sites-available/agnishop-api /etc/nginx/sites-enabled/
   
   # Test & reload
   sudo nginx -t
   sudo systemctl reload nginx
   ```

6. **Setup systemd service (optional)**
   ```bash
   sudo tee /etc/systemd/system/agnishop-api.service > /dev/null <<EOF
   [Unit]
   Description=AgniShop API
   After=network.target
   
   [Service]
   Type=notify
   User=deploy
   WorkingDirectory=/var/www/agnishop-api
   ExecStart=/usr/local/bin/php artisan serve --host=127.0.0.1 --port=9000
   Restart=always
   
   [Install]
   WantedBy=multi-user.target
   EOF
   
   sudo systemctl daemon-reload
   sudo systemctl start agnishop-api
   sudo systemctl enable agnishop-api
   ```

7. **SSL/TLS dengan Let's Encrypt**
   ```bash
   sudo apt install -y certbot python3-certbot-nginx
   sudo certbot certonly --nginx -d api.agnishop.local
   
   # Update Nginx config untuk HTTPS
   sudo certbot --nginx -d api.agnishop.local
   ```

---

## 2. Deployment Frontend (Vue 3)

### Option A: Vercel (Recommended)

#### Prerequisites
- GitHub account
- Vercel account

#### Langkah-langkah

1. **Push ke GitHub**
   ```bash
   cd frontend
   git add .
   git commit -m "Initial commit"
   git push origin main
   ```

2. **Connect ke Vercel**
   - Buka https://vercel.com
   - Click "New Project"
   - Select repository
   - Configure:
     - Framework: Vite
     - Build Command: `npm run build`
     - Output Directory: `dist`

3. **Set environment variables**
   ```
   VITE_API_URL = https://api.agnishop.local
   ```

4. **Deploy**
   - Vercel akan auto-deploy setiap kali push ke main

---

### Option B: Static Hosting (Netlify, AWS S3, etc)

#### Build production
```bash
cd frontend
npm run build

# Output akan di folder dist/
ls dist/
```

#### Upload ke hosting

**Netlify:**
```bash
npm install -g netlify-cli
netlify deploy --prod --dir=dist
```

**AWS S3 + CloudFront:**
```bash
aws s3 sync dist/ s3://agnishop-frontend/
aws cloudfront create-invalidation --distribution-id E1234567890 --paths "/*"
```

---

## 3. Database Deployment

### Option A: PostgreSQL Neon (Cloud)

1. **Create project**
   - Buka https://console.neon.tech
   - Create project: "agnishop"
   - Copy connection string

2. **Update environment**
   ```
   DB_CONNECTION=pgsql
   DB_HOST=ep-xxx-xxx.neon.tech
   DB_DATABASE=agnishop
   DB_USERNAME=user
   DB_PASSWORD=password
   DB_PORT=5432
   DB_SSLMODE=require
   ```

3. **Run migrations**
   ```bash
   php artisan migrate --seed
   ```

### Option B: Self-hosted PostgreSQL

```bash
# Create database & user
sudo -u postgres psql
CREATE DATABASE agnishop;
CREATE USER agnishop WITH PASSWORD 'secure_password';
GRANT ALL PRIVILEGES ON DATABASE agnishop TO agnishop;
\q

# Backup & restore
pg_dump -U agnishop agnishop > backup.sql
psql -U agnishop agnishop < backup.sql
```

---

## 4. Monitoring & Logging

### Backend Logs

```bash
# Docker
docker-compose logs -f app

# Traditional
tail -f storage/logs/laravel.log
```

### Application Monitoring

**Setup Supervisor untuk queue workers (jika ada):**
```ini
; /etc/supervisor/conf.d/agnishop.conf
[program:agnishop-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/agnishop-api/artisan queue:work --queue=default --tries=3
autostart=true
autorestart=true
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/agnishop-worker.log
```

---

## 5. SSL/TLS Certificate

### Let's Encrypt (Free)

```bash
# Using Certbot
sudo certbot certonly --standalone -d api.agnishop.local
sudo certbot certonly --standalone -d agnishop.vercel.app

# Auto-renew
sudo certbot renew --dry-run
```

---

## 6. Backup Strategy

### Database Backup

```bash
# Daily backup
0 2 * * * pg_dump -U agnishop agnishop > /backups/agnishop-$(date +\%Y\%m\%d).sql

# Compress old backups
find /backups -name "*.sql" -mtime +7 -exec gzip {} \;

# Upload to S3
aws s3 cp /backups/ s3://agnishop-backups/ --recursive
```

### Application Backup

```bash
# Backup source code
git clone --mirror git@github.com:agnishop/api.git /backups/api.git
git -C /backups/api.git remote update

# Backup storage
tar -czf /backups/agnishop-storage-$(date +%Y%m%d).tar.gz /var/www/agnishop-api/storage
```

---

## 7. Performance Optimization

### Caching

```bash
# Cache Laravel config
php artisan config:cache
php artisan route:cache

# Clear cache
php artisan cache:clear
php artisan view:clear
```

### Database Optimization

```sql
-- Check slow queries
SELECT query, mean_time FROM pg_stat_statements 
WHERE mean_time > 100 
ORDER BY mean_time DESC;

-- Add indexes
CREATE INDEX idx_orders_user_created ON orders(user_id, created_at);
CREATE INDEX idx_products_category ON products(category_id);
```

### Frontend Optimization

```bash
# Check bundle size
npm run build -- --report

# Production build
npm run build
# Check dist/ size adalah ~200-300KB (gzipped ~50-100KB)
```

---

## 8. Troubleshooting

### Backend Issues

| Error | Solution |
|-------|----------|
| 502 Bad Gateway | Check PHP-FPM: `sudo systemctl restart php8.2-fpm` |
| Database connection error | Verify credentials, check network connectivity |
| Permission denied | `sudo chown -R www-data:www-data storage/` |
| Out of memory | Increase PHP memory: `php_value memory_limit 512M` |

### Frontend Issues

| Error | Solution |
|-------|----------|
| API 404 | Check CORS headers, verify backend is running |
| Blank page | Check console for errors, check build output |
| Slow load time | Enable caching, use CDN, optimize images |

### Database Issues

```bash
# Check PostgreSQL status
sudo systemctl status postgresql

# Connect to database
psql -U agnishop -d agnishop -h localhost

# Check connections
SELECT datname, usename, count(*) FROM pg_stat_activity GROUP BY datname, usename;

# Kill stuck connections
SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'agnishop';
```

---

## 9. Security Checklist

- [ ] Update `APP_KEY` di production
- [ ] Set `APP_DEBUG=false` di production
- [ ] Enable HTTPS/SSL
- [ ] Configure CORS properly
- [ ] Setup rate limiting
- [ ] Enable database encryption (at-rest)
- [ ] Regular security updates
- [ ] Backup encryption
- [ ] VPN/SSH key access only

---

## 10. Post-Deployment Verification

```bash
# 1. Health check
curl -H "Authorization: Bearer {token}" https://api.agnishop.local/api/health

# 2. Database connectivity
php artisan tinker
>>> DB::connection()->getPdo()

# 3. File permissions
ls -l storage/ bootstrap/cache/

# 4. SSL certificate
openssl s_client -connect api.agnishop.local:443

# 5. Frontend accessibility
curl https://agnishop.vercel.app

# 6. API endpoints
curl -X GET https://api.agnishop.local/api/products \
  -H "Authorization: Bearer {token}"
```

---

## Quick Reference

### Docker Commands
```bash
docker-compose up -d           # Start
docker-compose down            # Stop
docker-compose logs -f app     # Logs
docker-compose ps              # Status
docker-compose exec app bash   # Shell
```

### Laravel Commands
```bash
php artisan migrate            # Run migrations
php artisan seed               # Seed data
php artisan tinker             # REPL
php artisan cache:clear        # Clear cache
php artisan storage:link       # Link storage
```

### Git Commands
```bash
git pull origin main           # Pull latest
git log --oneline              # View history
git revert HEAD --no-edit      # Rollback
git tag v1.0.0 && git push --tags
```

---

**Last Updated**: 2026-05-02  
**Status**: Production Ready
