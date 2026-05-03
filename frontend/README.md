# AgniShop Frontend

Frontend aplikasi e-commerce AgniShop Banjarmasin dengan Vue 3, Composition API, Pinia, dan Vite.

## Development

```bash
npm install
npm run dev
```

Frontend akan berjalan di `http://localhost:5173`

## Build Production

```bash
npm run build
```

Output akan berada di folder `dist/`.

## Struktur Folder

```
src/
  ├── components/    # Komponen Vue reusable
  ├── pages/         # Halaman aplikasi
  ├── services/      # API services dan business logic
  ├── stores/        # State management Pinia
  ├── router/        # Vue Router configuration
  ├── App.vue        # Root component
  └── main.js        # Entry point

```

## API Endpoint

Backend API tersedia di `http://localhost:8000/api`

### Authentication
- `POST /auth/register` - Register user baru
- `POST /auth/login` - Login user
- `POST /auth/logout` - Logout (requires auth)

### Products
- `GET /products` - List produk (requires auth)
- `POST /products` - Create produk (requires auth)
- `GET /products/{uuid}` - Detail produk (requires auth)
- `PUT /products/{uuid}` - Update produk (requires auth)
- `DELETE /products/{uuid}` - Delete produk (requires auth)

### Cart
- `GET /cart` - Get current cart (requires auth)
- `POST /cart/items` - Add item ke cart (requires auth)
- `PUT /cart/items/{item}` - Update cart item (requires auth)
- `DELETE /cart/items/{item}` - Remove item dari cart (requires auth)

### Orders
- `GET /orders` - List user orders (requires auth)
- `GET /orders/{uuid}` - Detail order (requires auth)
- `POST /orders` - Create order (checkout) (requires auth)

## Deployment ke Vercel

```bash
# 1. Push project ke GitHub
git add .
git commit -m "Initial commit"
git push origin main

# 2. Buka vercel.com dan connect repository
# 3. Set environment variable API_URL
# 4. Deploy
```
