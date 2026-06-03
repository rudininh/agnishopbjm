<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Sinkronisasi Otomatis</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" type="button" @click="loadAll" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" type="button" @click="pollShopeeOrders" :disabled="runningOrderPoll">
          {{ runningOrderPoll ? 'Cek pesanan...' : 'Cek Pesanan Shopee Sekarang' }}
        </button>
        <button class="primary" type="button" @click="pollTiktokOrders" :disabled="runningTiktokOrderPoll">
          {{ runningTiktokOrderPoll ? 'Cek TikTok...' : 'Cek Pesanan TikTok Sekarang' }}
        </button>
        <button :class="browserAutoSyncEnabled ? 'danger' : 'primary'" type="button" @click="toggleBrowserAutoSync">
          {{ browserAutoSyncEnabled ? 'Matikan Auto Browser' : 'Aktifkan Auto Browser' }}
        </button>
        <button class="danger" type="button" @click="syncShopeeToTiktok" :disabled="runningShopeeToTiktok">
          {{ runningShopeeToTiktok ? 'Sinkron real...' : 'Sync Real Shopee -> TikTok' }}
        </button>
        <button class="primary" type="button" @click="runSafetyCheck" :disabled="runningSafety">
          {{ runningSafety ? 'Menjalankan...' : 'Run Safety Check Now' }}
        </button>
      </div>
    </header>

    <p v-if="notice" :class="['notice', noticeType]">{{ notice }}</p>

    <section class="browser-auto-strip">
      <div>
        <span>Auto Browser</span>
        <strong :class="['badge', browserAutoSyncEnabled ? 'success' : 'neutral']">{{ browserAutoSyncEnabled ? 'Active' : 'Off' }}</strong>
      </div>
      <div><span>Running</span><strong>{{ browserAutoSyncRunning ? 'Sedang cek order' : '-' }}</strong></div>
      <div><span>Last run</span><strong>{{ formatDate(browserAutoSyncLastRun) }}</strong></div>
      <div><span>Countdown</span><strong>{{ browserAutoSyncCountdownLabel }}</strong></div>
      <div><span>Next run</span><strong>{{ browserAutoSyncNextRunLabel }}</strong></div>
    </section>

    <div class="status-grid">
      <article class="status-card">
        <div class="card-head">
          <span>Status Shopee</span>
          <strong :class="['badge', marketplaceStatus('shopee').connected ? 'success' : 'error']">{{ marketplaceStatus('shopee').connected ? 'Connected' : 'Disconnected' }}</strong>
        </div>
        <dl>
          <div><dt>Last webhook received</dt><dd>{{ formatDate(marketplaceStatus('shopee').last_webhook_at) }}</dd></div>
          <div><dt>Last stock update</dt><dd>{{ formatDate(marketplaceStatus('shopee').last_sync_at) }}</dd></div>
          <div><dt>Total webhook today</dt><dd>{{ marketplaceStatus('shopee').total_webhook_today || 0 }}</dd></div>
        </dl>
      </article>

      <article class="status-card">
        <div class="card-head">
          <span>Status TikTok</span>
          <strong :class="['badge', marketplaceStatus('tiktok').connected ? 'success' : 'error']">{{ marketplaceStatus('tiktok').connected ? 'Connected' : 'Disconnected' }}</strong>
        </div>
        <dl>
          <div><dt>Last webhook received</dt><dd>{{ formatDate(marketplaceStatus('tiktok').last_webhook_at) }}</dd></div>
          <div><dt>Last stock update</dt><dd>{{ formatDate(marketplaceStatus('tiktok').last_sync_at) }}</dd></div>
          <div><dt>Total webhook today</dt><dd>{{ marketplaceStatus('tiktok').total_webhook_today || 0 }}</dd></div>
        </dl>
      </article>

      <article class="status-card">
        <div class="card-head">
          <span>Auto Sync Engine</span>
          <strong :class="['badge', dashboard.engine?.status === 'active' ? 'success' : 'error']">{{ dashboard.engine?.status || 'inactive' }}</strong>
        </div>
        <dl>
          <div><dt>Realtime Sync</dt><dd>{{ dashboard.engine?.realtime_sync ? 'Active' : 'Inactive' }}</dd></div>
          <div><dt>Live Push</dt><dd>{{ dashboard.engine?.live_push ? 'Active' : 'Dry Run' }}</dd></div>
          <div><dt>Safety Check</dt><dd>{{ dashboard.engine?.safety_check ? dashboard.engine?.cron_interval : 'Inactive' }}</dd></div>
          <div><dt>Interval cron</dt><dd>{{ dashboard.engine?.cron_interval || '-' }}</dd></div>
        </dl>
      </article>

      <article class="status-card">
        <div class="card-head">
          <span>Order Sync Backup</span>
          <strong :class="['badge', dashboard.order_sync?.status === 'active' ? 'success' : 'error']">{{ dashboard.order_sync?.status || 'inactive' }}</strong>
        </div>
        <dl>
          <div><dt>Polling Order</dt><dd>{{ dashboard.order_sync?.polling_interval || '-' }}</dd></div>
          <div><dt>Last order sync</dt><dd>{{ formatDate(dashboard.order_sync?.last_order_sync_at) }}</dd></div>
          <div><dt>Shopee orders today</dt><dd>{{ dashboard.order_sync?.shopee_orders_processed_today || 0 }}</dd></div>
          <div><dt>TikTok orders today</dt><dd>{{ dashboard.order_sync?.tiktok_orders_processed_today || 0 }}</dd></div>
          <div><dt>TikTok -> Shopee today</dt><dd>{{ dashboard.order_sync?.tiktok_to_shopee_pushes_today || 0 }}</dd></div>
          <div><dt>Open issues</dt><dd>{{ dashboard.order_sync?.open_issues || 0 }}</dd></div>
        </dl>
      </article>
    </div>

    <section class="webhook-strip">
      <div>
        <span>Webhook Shopee</span>
        <code>{{ dashboard.webhook_urls?.shopee || '-' }}</code>
      </div>
      <div>
        <span>Webhook TikTok</span>
        <code>{{ dashboard.webhook_urls?.tiktok || '-' }}</code>
      </div>
    </section>

    <div class="tabs">
      <button :class="{ active: activeTab === 'webhook' }" @click="activeTab = 'webhook'">Webhook Monitor</button>
      <button :class="{ active: activeTab === 'order' }" @click="activeTab = 'order'">Order Sync</button>
      <button :class="{ active: activeTab === 'sync' }" @click="activeTab = 'sync'">Sync Log</button>
      <button :class="{ active: activeTab === 'safety' }" @click="activeTab = 'safety'">Cron Safety Check</button>
    </div>

    <section v-if="activeTab === 'webhook'" class="panel">
      <div class="filter-row">
        <select v-model="webhookFilters.marketplace" @change="loadWebhookLogs(1)">
          <option value="">Semua marketplace</option>
          <option value="shopee">Shopee</option>
          <option value="tiktok">TikTok</option>
        </select>
        <select v-model="webhookFilters.status" @change="loadWebhookLogs(1)">
          <option value="">Semua status</option>
          <option value="success">Success</option>
          <option value="error">Error</option>
          <option value="pending">Pending</option>
        </select>
        <input v-model="webhookFilters.date" type="date" @change="loadWebhookLogs(1)" />
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Marketplace</th><th>Event</th><th>SKU</th><th>Qty</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="row in webhookLogs" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.marketplace) }}</td>
              <td>{{ row.event_type || '-' }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.qty ?? '-' }}</td>
              <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
            </tr>
            <tr v-if="!webhookLogs.length"><td colspan="6" class="empty">Belum ada webhook log.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="webhookPagination" @change="loadWebhookLogs" />
    </section>

    <section v-if="activeTab === 'order'" class="panel">
      <div class="safety-summary order-summary">
        <div><span>Last order sync</span><strong>{{ formatDate(orderSync.summary?.last_order_sync_at) }}</strong></div>
        <div><span>Shopee orders today</span><strong>{{ orderSync.summary?.shopee_orders_processed_today || 0 }}</strong></div>
        <div><span>TikTok orders today</span><strong>{{ orderSync.summary?.tiktok_orders_processed_today || 0 }}</strong></div>
        <div><span>TikTok -> Shopee today</span><strong>{{ orderSync.summary?.tiktok_to_shopee_pushes_today || 0 }}</strong></div>
        <div><span>Open issues</span><strong>{{ orderSync.summary?.open_issues || 0 }}</strong></div>
      </div>
      <div v-if="orderSync.summary?.latest_open_issue_message" class="issue-banner">
        <div>
          <span>Masalah aktif terakhir</span>
          <strong>{{ formatDate(orderSync.summary?.latest_open_issue_at) }} - {{ orderSync.summary?.latest_open_issue_status || '-' }}</strong>
        </div>
        <p>{{ orderSync.summary.latest_open_issue_message }}</p>
      </div>
      <div class="filter-row">
        <select v-model="orderFilters.type" @change="loadOrderSync(1)">
          <option value="">Semua jenis</option>
          <option value="shopee_order">Shopee Order</option>
          <option value="shopee_stock_refresh">Shopee Stock Refresh</option>
          <option value="tiktok_order">TikTok Order</option>
        </select>
        <select v-model="orderFilters.status" @change="loadOrderSync(1)">
          <option value="">Semua status</option>
          <option value="success">Success</option>
          <option value="skipped">Skipped / Dilewati</option>
          <option value="error">Error</option>
        </select>
        <input v-model="orderFilters.date" type="date" @change="loadOrderSync(1)" />
      </div>
      <div class="table-actions">
        <button class="ghost" type="button" @click="exportOrderSync" :disabled="exportingOrderSync">
          {{ exportingOrderSync ? 'Mengekspor...' : 'Export Order Sync CSV' }}
        </button>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Jenis</th><th>Target</th><th>Order/SKU</th><th>Old Stock</th><th>New Stock</th><th>Status</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in orderSync.items" :key="row.id" class="clickable-row" @click="openOrderSyncDetail(row.id)">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.source_marketplace) }}</td>
              <td>{{ labelMarketplace(row.target_marketplace) }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.old_stock ?? '-' }}</td>
              <td>{{ row.new_stock ?? '-' }}</td>
              <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!orderSync.items.length"><td colspan="8" class="empty">Belum ada histori order sync.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="orderSync.pagination" @change="loadOrderSync" />
    </section>

    <section v-if="activeTab === 'sync'" class="panel">
      <div class="filter-row">
        <select v-model="syncFilters.marketplace" @change="loadSyncLogs(1)">
          <option value="">Semua marketplace</option>
          <option value="shopee">Shopee</option>
          <option value="tiktok">TikTok</option>
          <option value="manual_shopee_master">Manual Shopee Master</option>
          <option value="shopee_order">Shopee Order</option>
          <option value="shopee_stock_refresh">Shopee Stock Refresh</option>
          <option value="tiktok_order">TikTok Order</option>
          <option value="safety_check">Safety Check</option>
        </select>
        <select v-model="syncFilters.status" @change="loadSyncLogs(1)">
          <option value="">Semua status</option>
          <option value="success">Success</option>
          <option value="skipped">Skipped / Dilewati</option>
          <option value="error">Error</option>
        </select>
        <input v-model="syncFilters.date" type="date" @change="loadSyncLogs(1)" />
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>Source Marketplace</th><th>Target Marketplace</th><th>SKU</th><th>Old Stock</th><th>New Stock</th><th>Status</th><th>Message</th></tr></thead>
          <tbody>
            <tr v-for="row in syncLogs" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ labelMarketplace(row.source_marketplace) }}</td>
              <td>{{ labelMarketplace(row.target_marketplace) }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.old_stock ?? '-' }}</td>
              <td>{{ row.new_stock ?? '-' }}</td>
              <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!syncLogs.length"><td colspan="8" class="empty">Belum ada sync log.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="syncPagination" @change="loadSyncLogs" />
    </section>

    <section v-if="activeTab === 'safety'" class="panel">
      <div class="safety-summary">
        <div><span>Last run</span><strong>{{ formatDate(safety.summary?.last_run) }}</strong></div>
        <div><span>Next run</span><strong>{{ formatDate(safety.summary?.next_run) }}</strong></div>
        <div><span>Total checked</span><strong>{{ safety.summary?.total_checked || 0 }}</strong></div>
        <div><span>Total corrected</span><strong>{{ safety.summary?.total_corrected || 0 }}</strong></div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Time</th><th>SKU</th><th>Shopee Stock</th><th>TikTok Stock</th><th>Action</th></tr></thead>
          <tbody>
            <tr v-for="row in safety.items" :key="row.id">
              <td>{{ formatDate(row.created_at) }}</td>
              <td>{{ row.sku || '-' }}</td>
              <td>{{ row.old_stock ?? '-' }}</td>
              <td>{{ row.new_stock ?? '-' }}</td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!safety.items.length"><td colspan="5" class="empty">Belum ada histori safety check.</td></tr>
          </tbody>
        </table>
      </div>
      <Pagination :pagination="safety.pagination" @change="loadSafety" />
    </section>

    <div v-if="detailModal.open" class="modal-backdrop" @click.self="closeOrderSyncDetail">
      <section class="detail-modal">
        <header class="modal-head">
          <div>
            <p>Order Sync Detail</p>
            <h2>{{ detailModal.data?.order_ref || detailModal.data?.log?.sku || '-' }}</h2>
          </div>
          <div class="modal-actions">
            <button class="primary" type="button" @click="retryOrderSyncDetail" :disabled="detailModal.retrying || detailModal.loading">
              {{ detailModal.retrying ? 'Retry...' : 'Retry Sync' }}
            </button>
            <button class="ghost" type="button" @click="closeOrderSyncDetail">Tutup</button>
          </div>
        </header>

        <p v-if="detailModal.loading" class="empty">Memuat detail...</p>
        <template v-else>
          <div class="detail-grid">
            <div><span>Status Order</span><strong>{{ detailModal.data?.order?.order_status || '-' }}</strong></div>
            <div><span>Created</span><strong>{{ formatDate(detailModal.data?.order?.create_time) }}</strong></div>
            <div><span>Updated</span><strong>{{ formatDate(detailModal.data?.order?.update_time) }}</strong></div>
            <div><span>Log Status</span><strong>{{ detailModal.data?.log?.status || '-' }}</strong></div>
          </div>

          <h3>Produk Order</h3>
          <div class="detail-items">
            <article v-for="(item, index) in detailModal.data?.order?.items || []" :key="`${item.item_id}-${item.model_id}-${index}`">
              <img v-if="item.image_url" :src="item.image_url" alt="" />
              <div>
                <strong>{{ item.product_name || '-' }}</strong>
                <span>Varian: {{ item.variant_name || '-' }}</span>
                <span>Qty: {{ item.qty ?? '-' }}</span>
                <span>SKU: {{ item.seller_sku || '-' }}</span>
                <span>Item/Model: {{ item.item_id || '-' }} / {{ item.model_id || '-' }}</span>
              </div>
            </article>
            <p v-if="!(detailModal.data?.order?.items || []).length" class="empty">Detail produk order belum tersedia untuk log ini.</p>
          </div>

          <h3>Update Stok</h3>
          <div class="table-wrap detail-table">
            <table>
              <thead><tr><th>Time</th><th>Jenis</th><th>Target</th><th>SKU</th><th>Perubahan</th><th>Status</th><th>Message</th></tr></thead>
              <tbody>
                <tr v-for="row in detailModal.data?.stock_updates || []" :key="row.id">
                  <td>{{ formatDate(row.time) }}</td>
                  <td>{{ labelMarketplace(row.type) }}</td>
                  <td>{{ labelMarketplace(row.target) }}</td>
                  <td>{{ row.sku || '-' }}</td>
                  <td>{{ row.old_stock ?? '-' }} -> {{ row.new_stock ?? '-' }}</td>
                  <td><span :class="['badge', row.status === 'success' ? 'success' : row.status === 'error' ? 'error' : 'neutral']">{{ row.status }}</span></td>
                  <td>{{ row.message || '-' }}</td>
                </tr>
                <tr v-if="!(detailModal.data?.stock_updates || []).length"><td colspan="7" class="empty">Belum ada update stok terkait.</td></tr>
              </tbody>
            </table>
          </div>
        </template>
      </section>
    </div>
  </section>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const BROWSER_AUTO_SYNC_KEY = 'marketplace_auto_sync_browser_enabled'
const BROWSER_AUTO_SYNC_INTERVAL_MS = 60 * 1000
const BROWSER_AUTO_SYNC_SAFETY_EVERY_RUNS = 15

const loading = ref(false)
const runningSafety = ref(false)
const runningShopeeToTiktok = ref(false)
const runningOrderPoll = ref(false)
const runningTiktokOrderPoll = ref(false)
const browserAutoSyncEnabled = ref(false)
const browserAutoSyncRunning = ref(false)
const browserAutoSyncLastRun = ref(null)
const browserAutoSyncNextRun = ref(null)
const browserAutoSyncCountdownSeconds = ref(0)
const exportingOrderSync = ref(false)
const activeTab = ref('webhook')
const notice = ref('')
const noticeType = ref('success')
const dashboard = ref({ statuses: {}, engine: {}, safety: {}, order_sync: {}, webhook_urls: {} })
const webhookLogs = ref([])
const syncLogs = ref([])
const webhookPagination = ref({ page: 1, last_page: 1, total: 0 })
const syncPagination = ref({ page: 1, last_page: 1, total: 0 })
const safety = ref({ summary: {}, items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const orderSync = ref({ summary: {}, items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const detailModal = reactive({ open: false, loading: false, retrying: false, data: null })
const webhookFilters = reactive({ marketplace: '', status: '', date: '' })
const syncFilters = reactive({ marketplace: '', status: '', date: '' })
const orderFilters = reactive({ type: '', status: '', date: '' })
let browserAutoSyncTimer = null
let browserAutoSyncCountdownTimer = null
let browserAutoSyncRunCount = 0

const Pagination = defineComponent({
  props: { pagination: { type: Object, required: true } },
  emits: ['change'],
  setup(props, { emit }) {
    return () => h('div', { class: 'pagination' }, [
      h('button', { class: 'ghost', disabled: props.pagination.page <= 1, onClick: () => emit('change', props.pagination.page - 1) }, 'Prev'),
      h('span', `Halaman ${props.pagination.page || 1} / ${props.pagination.last_page || 1} | ${props.pagination.total || 0} data`),
      h('button', { class: 'ghost', disabled: props.pagination.page >= props.pagination.last_page, onClick: () => emit('change', props.pagination.page + 1) }, 'Next')
    ])
  }
})

const marketplaceStatus = (marketplace) => dashboard.value.statuses?.[marketplace] || {}
const labelMarketplace = (value) => {
  const text = String(value || '-')
  if (text === 'shopee') return 'Shopee'
  if (text === 'tiktok') return 'TikTok'
  if (text === 'safety_check') return 'Safety Check'
  if (text === 'manual_shopee_master') return 'Manual Shopee Master'
  if (text === 'shopee_order') return 'Shopee Order'
  if (text === 'shopee_stock_refresh') return 'Shopee Stock Refresh'
  if (text === 'tiktok_order') return 'TikTok Order'
  if (text === 'all') return 'Shopee + TikTok'
  return text
}
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '-'
const formatTime = (value) => value ? new Intl.DateTimeFormat('id-ID', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(value)) : '-'
const browserAutoSyncCountdownLabel = computed(() => {
  if (!browserAutoSyncEnabled.value) return '-'
  if (browserAutoSyncRunning.value) return 'Sedang berjalan'

  const seconds = Math.max(0, browserAutoSyncCountdownSeconds.value)
  const minutes = Math.floor(seconds / 60)
  const remainingSeconds = seconds % 60

  return `${String(minutes).padStart(2, '0')}:${String(remainingSeconds).padStart(2, '0')}`
})
const browserAutoSyncNextRunLabel = computed(() => browserAutoSyncNextRun.value ? `${formatTime(browserAutoSyncNextRun.value)} WITA` : '-')

const loadDashboard = async () => {
  const { data } = await omnichannelService.autoSyncDashboard()
  dashboard.value = data.data || {}
}

const loadWebhookLogs = async (page = webhookPagination.value.page || 1) => {
  const { data } = await omnichannelService.autoSyncWebhookLogs({ ...webhookFilters, page, per_page: 20 })
  webhookLogs.value = data.items || []
  webhookPagination.value = data.pagination || webhookPagination.value
}

const loadSyncLogs = async (page = syncPagination.value.page || 1) => {
  const { data } = await omnichannelService.autoSyncLogs({ ...syncFilters, page, per_page: 20 })
  syncLogs.value = data.items || []
  syncPagination.value = data.pagination || syncPagination.value
}

const loadSafety = async (page = safety.value.pagination?.page || 1) => {
  const { data } = await omnichannelService.autoSyncSafety({ page, per_page: 20 })
  safety.value = {
    summary: data.summary || {},
    items: data.items || [],
    pagination: data.pagination || safety.value.pagination
  }
}

const loadOrderSync = async (page = orderSync.value.pagination?.page || 1) => {
  const { data } = await omnichannelService.autoSyncOrderSync({ ...orderFilters, page, per_page: 20 })
  orderSync.value = {
    summary: data.summary || {},
    items: data.items || [],
    pagination: data.pagination || orderSync.value.pagination
  }
}

const loadAll = async () => {
  loading.value = true
  notice.value = ''
  try {
    await Promise.all([loadDashboard(), loadWebhookLogs(1), loadOrderSync(1), loadSyncLogs(1), loadSafety(1)])
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Data sinkronisasi gagal dimuat.'
    noticeType.value = 'error'
  } finally {
    loading.value = false
  }
}

const runSafetyCheck = async () => {
  runningSafety.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.runAutoSyncSafetyCheck()
    notice.value = data.message || 'Safety check selesai.'
    noticeType.value = 'success'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1)])
    activeTab.value = 'safety'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Safety check gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningSafety.value = false
  }
}

const syncShopeeToTiktok = async () => {
  const confirmed = window.confirm('Sinkron real semua stok TikTok mengikuti Shopee sekarang? Aksi ini akan mengirim update stok ke TikTok.')
  if (!confirmed) return

  runningShopeeToTiktok.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.syncAutoSyncShopeeToTiktok()
    const parts = [
      data.message || 'Sinkron Shopee ke TikTok selesai.',
      `Dicek: ${data.checked || 0}`,
      `Dikirim: ${data.pushed || 0}`,
      `Sama: ${data.unchanged || 0}`,
      `Dilewati: ${data.skipped || 0}`,
      `Nonaktif TikTok: ${data.skipped_inactive_tiktok || 0}`,
      `Stok Shopee kosong: ${data.skipped_missing_shopee_stock || 0}`,
      `Gagal: ${data.failed || 0}`
    ]
    notice.value = parts.join(' | ')
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    await Promise.all([loadDashboard(), loadSyncLogs(1), loadSafety(1)])
    activeTab.value = 'sync'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Sinkron Shopee ke TikTok gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningShopeeToTiktok.value = false
  }
}

const pollShopeeOrders = async () => {
  runningOrderPoll.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.pollAutoSyncShopeeOrders(24)
    const parts = [
      data.message || 'Polling order Shopee selesai.',
      `Order baru diproses: ${data.processed || 0}`,
      `Berhasil: ${data.success || 0}`,
      `Sudah pernah diproses: ${data.already_processed || 0}`,
      `Dilewati karena data belum lengkap: ${data.skipped || 0}`,
      `Gagal: ${data.failed || 0}`
    ]
    notice.value = parts.join(' | ')
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    syncFilters.marketplace = 'shopee_order'
    orderFilters.type = 'shopee_order'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1)])
    activeTab.value = 'order'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Polling order Shopee gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningOrderPoll.value = false
  }
}

const pollTiktokOrders = async () => {
  runningTiktokOrderPoll.value = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.pollAutoSyncTiktokOrders(24)
    const parts = [
      data.message || 'Polling order TikTok selesai.',
      `Order baru diproses: ${data.processed || 0}`,
      `Berhasil: ${data.success || 0}`,
      `Sudah pernah diproses: ${data.already_processed || 0}`,
      `Dilewati karena status belum mengubah stok: ${data.skipped || 0}`,
      `Gagal: ${data.failed || 0}`
    ]
    notice.value = parts.join(' | ')
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    syncFilters.marketplace = 'tiktok_order'
    orderFilters.type = 'tiktok_order'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1)])
    activeTab.value = 'order'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Polling order TikTok gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningTiktokOrderPoll.value = false
  }
}

const clearBrowserAutoSyncTimer = () => {
  if (browserAutoSyncTimer) {
    window.clearTimeout(browserAutoSyncTimer)
    browserAutoSyncTimer = null
  }
}

const clearBrowserAutoSyncCountdown = () => {
  if (browserAutoSyncCountdownTimer) {
    window.clearInterval(browserAutoSyncCountdownTimer)
    browserAutoSyncCountdownTimer = null
  }
}

const updateBrowserAutoSyncCountdown = () => {
  if (!browserAutoSyncNextRun.value) {
    browserAutoSyncCountdownSeconds.value = 0
    return
  }

  browserAutoSyncCountdownSeconds.value = Math.max(0, Math.ceil((new Date(browserAutoSyncNextRun.value).getTime() - Date.now()) / 1000))
}

const startBrowserAutoSyncCountdown = () => {
  clearBrowserAutoSyncCountdown()
  updateBrowserAutoSyncCountdown()
  browserAutoSyncCountdownTimer = window.setInterval(updateBrowserAutoSyncCountdown, 1000)
}

const scheduleBrowserAutoSync = (delay = BROWSER_AUTO_SYNC_INTERVAL_MS) => {
  clearBrowserAutoSyncTimer()
  if (!browserAutoSyncEnabled.value) return

  browserAutoSyncNextRun.value = new Date(Date.now() + delay).toISOString()
  startBrowserAutoSyncCountdown()
  browserAutoSyncTimer = window.setTimeout(() => {
    runBrowserAutoSync()
  }, delay)
}

const runBrowserAutoSync = async () => {
  if (!browserAutoSyncEnabled.value || browserAutoSyncRunning.value) return

  browserAutoSyncRunning.value = true
  browserAutoSyncNextRun.value = null
  browserAutoSyncCountdownSeconds.value = 0
  try {
    const [shopeeResult, tiktokResult] = await Promise.allSettled([
      omnichannelService.pollAutoSyncShopeeOrders(24),
      omnichannelService.pollAutoSyncTiktokOrders(24)
    ])

    browserAutoSyncRunCount += 1
    if (browserAutoSyncRunCount % BROWSER_AUTO_SYNC_SAFETY_EVERY_RUNS === 0) {
      await omnichannelService.runAutoSyncSafetyCheck()
    }

    const shopeeData = shopeeResult.status === 'fulfilled' ? shopeeResult.value.data : null
    const tiktokData = tiktokResult.status === 'fulfilled' ? tiktokResult.value.data : null
    const failed = shopeeResult.status === 'rejected' || tiktokResult.status === 'rejected' || (shopeeData?.failed || 0) > 0 || (tiktokData?.failed || 0) > 0

    browserAutoSyncLastRun.value = new Date().toISOString()
    notice.value = [
      'Auto Browser sync selesai.',
      `Shopee baru: ${shopeeData?.processed || 0}`,
      `TikTok baru: ${tiktokData?.processed || 0}`,
      `Gagal: ${(shopeeData?.failed || 0) + (tiktokData?.failed || 0)}`
    ].join(' | ')
    noticeType.value = failed ? 'error' : 'success'

    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1), loadSafety(1)])
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Auto Browser sync gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    browserAutoSyncRunning.value = false
    scheduleBrowserAutoSync()
  }
}

const startBrowserAutoSync = () => {
  browserAutoSyncEnabled.value = true
  window.localStorage.setItem(BROWSER_AUTO_SYNC_KEY, '1')
  runBrowserAutoSync()
}

const stopBrowserAutoSync = () => {
  browserAutoSyncEnabled.value = false
  window.localStorage.removeItem(BROWSER_AUTO_SYNC_KEY)
  browserAutoSyncNextRun.value = null
  browserAutoSyncCountdownSeconds.value = 0
  clearBrowserAutoSyncTimer()
  clearBrowserAutoSyncCountdown()
}

const toggleBrowserAutoSync = () => {
  if (browserAutoSyncEnabled.value) {
    stopBrowserAutoSync()
    notice.value = 'Auto Browser sync dimatikan.'
    noticeType.value = 'success'
    return
  }

  startBrowserAutoSync()
}

const exportOrderSync = async () => {
  exportingOrderSync.value = true
  notice.value = ''
  try {
    const response = await omnichannelService.exportAutoSyncOrderSync({ ...orderFilters })
    const blob = new Blob([response.data], { type: 'text/csv;charset=utf-8;' })
    const url = window.URL.createObjectURL(blob)
    const link = document.createElement('a')
    link.href = url
    link.download = `order-sync-${new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-')}.csv`
    document.body.appendChild(link)
    link.click()
    link.remove()
    window.URL.revokeObjectURL(url)
    notice.value = 'Export Order Sync CSV selesai.'
    noticeType.value = 'success'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Export Order Sync CSV gagal.'
    noticeType.value = 'error'
  } finally {
    exportingOrderSync.value = false
  }
}

const openOrderSyncDetail = async (id) => {
  detailModal.open = true
  detailModal.loading = true
  detailModal.data = null
  try {
    const { data } = await omnichannelService.autoSyncOrderSyncDetail(id)
    detailModal.data = data
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Detail order sync gagal dimuat.'
    noticeType.value = 'error'
    detailModal.open = false
  } finally {
    detailModal.loading = false
  }
}

const closeOrderSyncDetail = () => {
  detailModal.open = false
  detailModal.loading = false
  detailModal.retrying = false
  detailModal.data = null
}

const retryOrderSyncDetail = async () => {
  const id = detailModal.data?.log?.id
  if (!id) return

  detailModal.retrying = true
  notice.value = ''
  try {
    const { data } = await omnichannelService.retryAutoSyncOrderSync(id)
    notice.value = data.message || 'Retry order sync selesai.'
    noticeType.value = data.status === 'warning' || (data.failed || 0) > 0 ? 'error' : 'success'
    await Promise.all([loadDashboard(), loadOrderSync(1), loadSyncLogs(1)])
    const refreshed = await omnichannelService.autoSyncOrderSyncDetail(id)
    detailModal.data = refreshed.data
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Retry order sync gagal.'
    noticeType.value = 'error'
  } finally {
    detailModal.retrying = false
  }
}

onMounted(async () => {
  await loadAll()
  if (window.localStorage.getItem(BROWSER_AUTO_SYNC_KEY) === '1') {
    startBrowserAutoSync()
  }
})

onBeforeUnmount(() => {
  clearBrowserAutoSyncTimer()
  clearBrowserAutoSyncCountdown()
})
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; color:#0f172a; }
.page-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
.page-header p { color:#64748b; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
.page-header h1 { font-size:28px; line-height:1.15; margin-top:4px; }
.header-actions { display:flex; gap:10px; flex-wrap:wrap; }
button { border:0; border-radius:6px; padding:9px 13px; font-weight:700; cursor:pointer; }
button:disabled { opacity:.6; cursor:not-allowed; }
.primary { background:#0f5fc7; color:#fff; }
.danger { background:#b91c1c; color:#fff; }
.ghost { background:#fff; color:#0f172a; border:1px solid #dbe3ef; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:14px; border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.notice.error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
.browser-auto-strip { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.browser-auto-strip div { display:flex; justify-content:space-between; align-items:center; gap:10px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:10px 12px; min-width:0; }
.browser-auto-strip span { color:#64748b; font-size:12px; font-weight:800; }
.browser-auto-strip strong:not(.badge) { color:#0f172a; font-size:13px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.status-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:14px; margin-bottom:14px; }
.status-card,.panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.status-card { padding:16px; }
.card-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:14px; }
.card-head span { color:#475569; font-weight:800; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:800; text-transform:capitalize; }
.badge.success { background:#dcfce7; color:#166534; }
.badge.error { background:#fee2e2; color:#991b1b; }
.badge.neutral { background:#e2e8f0; color:#334155; }
dl { display:grid; gap:10px; }
dt { color:#64748b; font-size:12px; }
dd { color:#0f172a; font-size:14px; font-weight:800; margin-top:2px; }
.webhook-strip { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.webhook-strip div { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:12px; min-width:0; }
.webhook-strip span { display:block; color:#64748b; font-size:12px; font-weight:800; margin-bottom:6px; }
.webhook-strip code { display:block; overflow:auto; color:#0f172a; font-size:12px; white-space:nowrap; }
.issue-banner { display:grid; grid-template-columns:220px 1fr; gap:12px; align-items:start; border:1px solid #fed7aa; background:#fff7ed; color:#7c2d12; border-radius:8px; padding:12px; margin-bottom:12px; }
.issue-banner span { display:block; font-size:12px; font-weight:800; color:#9a3412; margin-bottom:4px; }
.issue-banner strong { font-size:13px; }
.issue-banner p { margin:0; font-size:13px; line-height:1.45; overflow-wrap:anywhere; }
.tabs { display:flex; gap:8px; margin:14px 0; border-bottom:1px solid #e2e8f0; }
.tabs button { background:transparent; color:#475569; border-radius:6px 6px 0 0; border:1px solid transparent; }
.tabs button.active { background:#fff; color:#0f172a; border-color:#e2e8f0; border-bottom-color:#fff; margin-bottom:-1px; }
.panel { padding:14px; }
.filter-row { display:grid; grid-template-columns:repeat(3,minmax(160px,1fr)); gap:10px; margin-bottom:12px; }
.table-actions { display:flex; justify-content:flex-end; margin-bottom:12px; }
select,input { width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:9px 10px; background:#fff; }
.table-wrap { overflow:auto; border:1px solid #e2e8f0; border-radius:6px; }
.clickable-row { cursor:pointer; }
.clickable-row:hover td { background:#f8fafc; }
table { width:100%; border-collapse:collapse; font-size:13px; min-width:900px; }
th,td { padding:10px 12px; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top; }
th { background:#f8fafc; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
td { color:#0f172a; }
.empty { text-align:center; color:#64748b; padding:22px; }
.pagination { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:12px; color:#475569; font-size:13px; }
.safety-summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-bottom:12px; }
.safety-summary div { border:1px solid #e2e8f0; border-radius:6px; padding:12px; background:#f8fafc; }
.safety-summary span { display:block; color:#64748b; font-size:12px; margin-bottom:4px; }
.safety-summary strong { font-size:15px; }
.order-summary { margin-bottom:12px; }
.modal-backdrop { position:fixed; inset:0; z-index:50; background:rgba(15,23,42,.45); display:flex; justify-content:center; align-items:flex-start; padding:48px 18px; overflow:auto; }
.detail-modal { width:min(1120px,100%); background:#fff; border-radius:8px; box-shadow:0 24px 80px rgba(15,23,42,.22); padding:18px; }
.modal-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:14px; }
.modal-head p { color:#64748b; font-size:12px; font-weight:800; text-transform:uppercase; margin:0 0 4px; }
.modal-head h2 { font-size:20px; margin:0; }
.modal-actions { display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end; }
.detail-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.detail-grid div { border:1px solid #e2e8f0; border-radius:6px; padding:10px; background:#f8fafc; }
.detail-grid span { display:block; color:#64748b; font-size:12px; margin-bottom:4px; }
.detail-grid strong { font-size:14px; }
.detail-modal h3 { font-size:15px; margin:16px 0 10px; }
.detail-items { display:grid; gap:10px; }
.detail-items article { display:grid; grid-template-columns:64px 1fr; gap:12px; border:1px solid #e2e8f0; border-radius:6px; padding:10px; }
.detail-items img { width:64px; height:64px; object-fit:cover; border-radius:6px; background:#f1f5f9; }
.detail-items strong { display:block; margin-bottom:5px; }
.detail-items span { display:block; color:#475569; font-size:12px; margin-top:2px; }
.detail-table table { min-width:980px; }
@media (max-width:1360px) { .status-grid { grid-template-columns:repeat(2,minmax(0,1fr)); } }
@media (max-width:1180px) { .status-grid,.safety-summary,.webhook-strip,.browser-auto-strip { grid-template-columns:1fr; } }
@media (max-width:820px) { .page-shell { margin-left:0; padding:16px; } .page-header,.header-actions,.modal-head,.modal-actions { flex-direction:column; align-items:stretch; } .filter-row,.issue-banner,.detail-grid,.detail-items article { grid-template-columns:1fr; } }
</style>
