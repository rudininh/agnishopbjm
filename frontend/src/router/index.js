import { createRouter, createWebHistory } from 'vue-router'

import Dashboard from '@/pages/Dashboard.vue'
import ShopeeStock from '@/pages/ShopeeStock.vue'
import TiktokStock from '@/pages/TiktokStock.vue'
import StockMaster from '@/pages/StockMaster.vue'
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
