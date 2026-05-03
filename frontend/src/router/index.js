import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/authStore'

import Login from '@/pages/Login.vue'
import Dashboard from '@/pages/Dashboard.vue'
import ShopeeStock from '@/pages/ShopeeStock.vue'
import TiktokStock from '@/pages/TiktokStock.vue'
import StockMaster from '@/pages/StockMaster.vue'
import SyncShopeeTiktok from '@/pages/SyncShopeeTiktok.vue'

const routes = [
  {
    path: '/login',
    name: 'login',
    component: Login,
    meta: { requiresAuth: false }
  },
  {
    path: '/',
    redirect: '/dashboard'
  },
  {
    path: '/dashboard',
    name: 'dashboard',
    component: Dashboard,
    meta: { requiresAuth: true }
  },
  {
    path: '/stok-shopee',
    name: 'shopee-stock',
    component: ShopeeStock,
    meta: { requiresAuth: true }
  },
  {
    path: '/stok-tiktok',
    name: 'tiktok-stock',
    component: TiktokStock,
    meta: { requiresAuth: true }
  },
  {
    path: '/stock-master',
    name: 'stock-master',
    component: StockMaster,
    meta: { requiresAuth: true }
  },
  {
    path: '/sync-shopee-to-tiktok',
    name: 'sync-shopee-to-tiktok',
    component: SyncShopeeTiktok,
    meta: { requiresAuth: true }
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

router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next('/login')
  } else if (to.path === '/login' && authStore.isAuthenticated) {
    next('/dashboard')
  } else {
    next()
  }
})

export default router
