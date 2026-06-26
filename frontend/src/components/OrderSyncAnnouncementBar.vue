<template>
  <section class="order-sync-bar" aria-label="Order sync terbaru">
    <div class="bar-title">
      <strong>Order Sync</strong>
      <span v-if="loading">Updating...</span>
      <span v-else-if="errorMessage" class="error">Offline</span>
      <span v-else>Live</span>
    </div>

    <div v-if="latest" class="bar-grid">
      <div><span>Time</span><b>{{ formatDate(latest.created_at) }}</b></div>
      <div><span>Runner</span><b>{{ runnerLabel(latest) }}</b><small v-if="latest.machine_name">{{ latest.machine_name }}</small></div>
      <div><span>Jenis</span><b>{{ labelMarketplace(latest.source_marketplace) }}</b></div>
      <div><span>Target</span><b>{{ labelMarketplace(latest.target_marketplace) }}</b></div>
      <div class="wide"><span>Order/SKU</span><b>{{ latest.sku || '-' }}</b></div>
      <div><span>Old Stock</span><b>{{ stockValue(latest.old_stock) }}</b></div>
      <div><span>New Stock</span><b>{{ stockValue(latest.new_stock) }}</b></div>
      <div><span>Status</span><b :class="['status', statusClass(latest.status)]">{{ latest.status || '-' }}</b></div>
      <div class="message"><span>Message</span><b>{{ latest.message || '-' }}</b></div>
    </div>

    <div v-else class="empty">
      {{ errorMessage || 'Belum ada histori order sync.' }}
    </div>
  </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { omnichannelService } from '@/services'

const latest = ref(null)
const loading = ref(false)
const errorMessage = ref('')
let refreshTimer = null

const formatDate = (value) => {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value

  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date)
}

const labelMarketplace = (value) => {
  const text = String(value || '').toLowerCase()
  const labels = {
    shopee_order: 'Shopee Order',
    shopee_stock_refresh: 'Shopee Stock Refresh',
    tiktok_order: 'TikTok Order',
    tiktok_stock_refresh: 'TikTok Stock Refresh',
    shopee: 'Shopee',
    tiktok: 'TikTok'
  }

  return labels[text] || value || '-'
}

const runnerLabel = (row) => {
  if (row?.runner_label) return row.runner_label
  if (row?.runner === 'stb' || row?.runner_source === 'remote_stb') return 'STB'
  if (row?.runner === 'pc') return 'PC'
  if (row?.runner === 'online_backup') return 'Online'

  return row?.runner || '-'
}

const stockValue = (value) => value === null || value === undefined || value === '' ? '-' : value

const statusClass = (status) => {
  if (status === 'success') return 'success'
  if (status === 'error') return 'error'
  return 'neutral'
}

const loadLatest = async () => {
  loading.value = true
  try {
    const { data } = await omnichannelService.autoSyncOrderSync({ page: 1, per_page: 1 })
    latest.value = data.items?.[0] || null
    errorMessage.value = ''
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || error?.message || 'Order sync terbaru gagal dimuat.'
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadLatest()
  refreshTimer = window.setInterval(loadLatest, 10000)
})

onBeforeUnmount(() => {
  if (refreshTimer) {
    window.clearInterval(refreshTimer)
  }
})
</script>

<style scoped>
.order-sync-bar {
  position: sticky;
  top: 0;
  z-index: 18;
  margin-left: 240px;
  border-bottom: 1px solid #d9e2ec;
  background: #ffffff;
  box-shadow: 0 8px 20px rgba(15, 23, 42, .08);
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 12px;
  align-items: stretch;
  padding: 8px 14px;
}

.bar-title {
  display: grid;
  align-content: center;
  gap: 2px;
  min-width: 92px;
}

.bar-title strong {
  color: #0f172a;
  font-size: 13px;
  font-weight: 900;
  line-height: 1.1;
}

.bar-title span {
  color: #15803d;
  font-size: 11px;
  font-weight: 800;
}

.bar-title .error { color: #b91c1c; }

.bar-grid {
  display: grid;
  grid-template-columns: minmax(94px, .8fr) minmax(70px, .7fr) minmax(96px, .9fr) minmax(62px, .6fr) minmax(150px, 1fr) 68px 68px 70px minmax(280px, 2fr);
  gap: 8px;
  align-items: stretch;
  min-width: 0;
}

.bar-grid div,
.empty {
  min-width: 0;
  border: 1px solid #edf2f7;
  border-radius: 6px;
  background: #f8fafc;
  padding: 6px 8px;
}

.bar-grid span {
  display: block;
  color: #64748b;
  font-size: 10px;
  font-weight: 800;
  line-height: 1;
  margin-bottom: 4px;
  text-transform: uppercase;
}

.bar-grid b {
  display: block;
  color: #0f172a;
  font-size: 12px;
  font-weight: 900;
  line-height: 1.2;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bar-grid small {
  color: #475569;
  display: block;
  font-size: 10px;
  font-weight: 700;
  margin-top: 2px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.bar-grid .message b {
  white-space: nowrap;
}

.status {
  display: inline-flex !important;
  align-items: center;
  border-radius: 999px;
  justify-content: center;
  min-height: 22px;
  padding: 3px 8px;
  width: fit-content;
}

.status.success { background: #dcfce7; color: #166534; }
.status.error { background: #fee2e2; color: #b91c1c; }
.status.neutral { background: #e2e8f0; color: #334155; }

.empty {
  color: #64748b;
  font-size: 12px;
  font-weight: 700;
}

@media (max-width: 1180px) {
  .bar-grid {
    grid-template-columns: repeat(4, minmax(120px, 1fr));
    overflow-x: auto;
  }

  .bar-grid .message {
    grid-column: span 2;
  }
}

@media (max-width: 820px) {
  .order-sync-bar {
    grid-template-columns: 1fr;
    margin-left: 0;
    position: static;
  }

  .bar-grid {
    grid-template-columns: repeat(2, minmax(140px, 1fr));
  }
}
</style>
