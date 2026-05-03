<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>AgniShop Banjarmasin</p>
        <h1>Dashboard Omnichannel</h1>
      </div>
      <button class="primary" @click="loadData" :disabled="loading">
        {{ loading ? 'Memuat...' : 'Refresh' }}
      </button>
    </header>

    <div class="stats">
      <article v-for="card in cards" :key="card.label" class="stat">
        <span>{{ card.label }}</span>
        <strong>{{ formatNumber(card.value) }}</strong>
      </article>
    </div>

    <section class="panel">
      <div class="panel-title">
        <h2>Status Integrasi</h2>
      </div>
      <div class="integration-grid">
        <article class="integration">
          <span>Shopee</span>
          <strong>{{ data.tokens?.shopee ? 'Terhubung' : 'Belum ada token' }}</strong>
          <small>{{ data.tokens?.shopee?.created_at || '-' }}</small>
        </article>
        <article class="integration">
          <span>TikTok Shop</span>
          <strong>{{ data.tokens?.tiktok ? 'Terhubung' : 'Belum ada token' }}</strong>
          <small>{{ data.tokens?.tiktok?.created_at || data.tokens?.tiktok?.expire_at || '-' }}</small>
        </article>
        <article class="integration">
          <span>Database</span>
          <strong>Aktif</strong>
          <small>PostgreSQL Neon</small>
        </article>
      </div>
    </section>

    <section class="panel">
      <div class="panel-title">
        <h2>Aksi Cepat</h2>
      </div>
      <div class="quick-actions">
        <RouterLink class="action shopee" to="/stok-shopee">Stok Shopee</RouterLink>
        <RouterLink class="action tiktok" to="/stok-tiktok">Stok TikTok</RouterLink>
        <RouterLink class="action master" to="/stock-master">Stock Master</RouterLink>
        <RouterLink class="action sync" to="/sync-shopee-to-tiktok">Sync Stok</RouterLink>
      </div>
    </section>

    <section class="panel">
      <div class="panel-title">
        <div>
          <h2>Token Marketplace</h2>
          <p>Kelola auth dan refresh token Shopee/TikTok.</p>
        </div>
      </div>

      <div class="token-actions">
        <button
          v-for="button in tokenButtons"
          :key="button.action"
          :class="['token-button', button.variant]"
          :disabled="busyAction === button.action"
          @click="runTokenAction(button.action)"
        >
          <span v-if="button.icon" class="button-icon">↻</span>
          <span>{{ busyAction === button.action ? 'Memproses...' : button.label }}</span>
        </button>
      </div>

      <p v-if="message" class="action-message">{{ message }}</p>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { omnichannelService } from '@/services'

const loading = ref(false)
const busyAction = ref('')
const message = ref('')
const data = ref({ summary: {}, tokens: {} })

const cards = computed(() => [
  { label: 'Stock Master', value: data.value.summary?.stock_master || 0 },
  { label: 'Produk Shopee', value: data.value.summary?.shopee_products || 0 },
  { label: 'Varian Shopee', value: data.value.summary?.shopee_variants || 0 },
  { label: 'Produk TikTok', value: data.value.summary?.tiktok_products || 0 },
  { label: 'SKU TikTok', value: data.value.summary?.tiktok_skus || 0 },
  { label: 'SKU Mapping', value: data.value.summary?.sku_mappings || 0 }
])

const tokenButtons = [
  { label: 'AUTH SHOPEE', action: 'auth-shopee', variant: 'shopee' },
  { label: 'AUTH TIKTOK', action: 'auth-tiktok', variant: 'tiktok' },
  { label: 'GET TOKEN SHOPEE', action: 'get-token-shopee', variant: 'shopee' },
  { label: 'GET TOKEN TIKTOK', action: 'get-token-tiktok', variant: 'tiktok' },
  { label: 'REFRESH TOKEN SHOPEE', action: 'refresh-token-shopee', variant: 'shopee', icon: true },
  { label: 'REFRESH TOKEN TIKTOK', action: 'refresh-token-tiktok', variant: 'tiktok', icon: true },
  { label: 'GET AUTH SHOP TIKTOK', action: 'get-auth-shop-tiktok', variant: 'tiktok wide' }
]

const formatNumber = (value) => new Intl.NumberFormat('id-ID').format(value || 0)

const loadData = async () => {
  loading.value = true
  try {
    const response = await omnichannelService.dashboard()
    data.value = response.data
  } finally {
    loading.value = false
  }
}

const runTokenAction = async (action) => {
  busyAction.value = action
  message.value = ''

  try {
    const response = await omnichannelService.runTokenAction(action)
    message.value = response.data.message || 'Aksi berhasil diproses.'
  } catch (error) {
    message.value = error.response?.data?.message || 'Aksi gagal diproses.'
  } finally {
    busyAction.value = ''
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 28px; }
.page-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 20px; }
.page-header p { color: #64748b; margin-bottom: 4px; }
h1 { font-size: 28px; }
.primary { background: #0f5fc7; color: #fff; border: 0; border-radius: 6px; padding: 10px 14px; cursor: pointer; }
.primary:disabled { cursor: wait; opacity: .72; }
.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }
.stat, .panel, .integration { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; }
.stat { padding: 16px; }
.stat span, .integration span { color: #64748b; display: block; margin-bottom: 6px; }
.stat strong { font-size: 26px; }
.panel { padding: 18px; margin-bottom: 18px; }
.panel-title { display: flex; align-items: flex-start; justify-content: space-between; gap: 14px; margin-bottom: 12px; }
.panel-title h2 { font-size: 18px; }
.panel-title p { color: #64748b; font-size: 13px; margin-top: 4px; }
.integration-grid, .quick-actions { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.integration { padding: 14px; }
.integration small { color: #64748b; display: block; margin-top: 8px; }
.quick-actions { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.action { color: #fff; text-decoration: none; border-radius: 6px; padding: 14px; font-weight: 700; text-align: center; }
.shopee { background: #f97316; }
.tiktok { background: #111827; }
.master { background: #15803d; }
.sync { background: #6d28d9; }
.token-actions { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.token-button { min-height: 46px; border: 0; border-radius: 6px; color: #fff; padding: 12px 14px; font-size: 14px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; text-align: center; }
.token-button:disabled { cursor: wait; opacity: .72; }
.token-button.shopee { background: #ff5528; }
.token-button.tiktok { background: #000; }
.token-button.wide { grid-column: span 2; }
.button-icon { font-size: 15px; line-height: 1; }
.action-message { color: #334155; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px 12px; margin-top: 12px; font-size: 13px; }
@media (max-width: 1040px) { .token-actions { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
@media (max-width: 820px) { .page-shell { margin-left: 0; padding: 18px; } .stats, .integration-grid, .quick-actions, .token-actions { grid-template-columns: 1fr; } .token-button.wide { grid-column: auto; } }
</style>
