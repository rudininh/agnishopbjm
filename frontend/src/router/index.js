import { createRouter, createWebHistory } from 'vue-router'

import Dashboard from '@/pages/Dashboard.vue'
import ShopeeStock from '@/pages/ShopeeStock.vue'
import TiktokStock from '@/pages/TiktokStock.vue'
import StockMaster from '@/pages/StockMaster.vue'
import SkuMapping from '@/pages/SkuMapping.vue'
import TambahVarian from '@/pages/TambahVarian.vue'
import TambahVarianShopee from '@/pages/TambahVarianShopee.vue'
import ProductVariantAnalysis from '@/pages/ProductVariantAnalysis.vue'
import DetailProdukMarketplace from '@/pages/DetailProdukMarketplace.vue'
import SyncShopeeTiktok from '@/pages/SyncShopeeTiktok.vue'
import DokumentasiShopee from '@/pages/DokumentasiShopee.vue'
import DokumentasiTiktok from '@/pages/DokumentasiTiktok.vue'

const routes = [
  {
    path: '/login',
    name: 'login',
    redirect: '/dashboard'
  },
  {
    path: '/',
    redirect: '/dashboard'
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: Dashboard
  },
  {
    path: '/stok-shopee',
    name: 'shopee-stock',
    component: ShopeeStock
  },
  {
    path: '/stok-tiktok',
    name: 'tiktok-stock',
    component: TiktokStock
  },
  {
    path: '/stock-master',
    name: 'stock-master',
    component: StockMaster
  },
  {
    path: '/sku-mapping',
    name: 'sku-mapping',
    component: SkuMapping
  },
  {
    path: '/tambah-varian',
    redirect: '/tambah-varian-tiktok'
  },
  {
    path: '/tambah-varian-tiktok',
    name: 'tambah-varian-tiktok',
    component: TambahVarian,
    meta: { flow: 'shopee-to-tiktok' }
  },
  {
    path: '/tambah-varian-shopee',
    name: 'tambah-varian-shopee',
    component: TambahVarianShopee,
    meta: { flow: 'tiktok-to-shopee' }
  },
  {
    path: '/analisa-product-variant',
    name: 'analisa-product-variant',
    component: ProductVariantAnalysis
  },
  {
    path: '/detail-produk-marketplace',
    name: 'detail-produk-marketplace',
    component: DetailProdukMarketplace
  },
  {
    path: '/sync-shopee-to-tiktok',
    name: 'sync-shopee-to-tiktok',
    component: SyncShopeeTiktok
  },
  {
    path: '/dokumentasi-shopee',
    name: 'dokumentasi-shopee',
    component: DokumentasiShopee
  },
  {
    path: '/dokumentasi-tiktok',
    name: 'dokumentasi-tiktok',
    component: DokumentasiTiktok
  },
  {
    path: '/:pathMatch(.*)*',
    redirect: '/dashboard'
  }
]

const router = createRouter({
  history: createWebHistory(),
  routes
})

export default router
