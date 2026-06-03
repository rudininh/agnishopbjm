<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Sinkronisasi Otomatis</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" type="button" @click="loadAll" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" type="button" @click="runSafetyCheck" :disabled="runningSafety">
          {{ runningSafety ? 'Menjalankan...' : 'Run Safety Check Now' }}
        </button>
      </div>
    </header>

    <p v-if="notice" :class="['notice', noticeType]">{{ notice }}</p>

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
    </div>

    <div class="tabs">
      <button :class="{ active: activeTab === 'webhook' }" @click="activeTab = 'webhook'">Webhook Monitor</button>
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

    <section v-if="activeTab === 'sync'" class="panel">
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
  </section>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const runningSafety = ref(false)
const activeTab = ref('webhook')
const notice = ref('')
const noticeType = ref('success')
const dashboard = ref({ statuses: {}, engine: {}, safety: {} })
const webhookLogs = ref([])
const syncLogs = ref([])
const webhookPagination = ref({ page: 1, last_page: 1, total: 0 })
const syncPagination = ref({ page: 1, last_page: 1, total: 0 })
const safety = ref({ summary: {}, items: [], pagination: { page: 1, last_page: 1, total: 0 } })
const webhookFilters = reactive({ marketplace: '', status: '', date: '' })

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
  if (text === 'all') return 'Shopee + TikTok'
  return text
}
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '-'

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
  const { data } = await omnichannelService.autoSyncLogs({ page, per_page: 20 })
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

const loadAll = async () => {
  loading.value = true
  notice.value = ''
  try {
    await Promise.all([loadDashboard(), loadWebhookLogs(1), loadSyncLogs(1), loadSafety(1)])
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
    await Promise.all([loadDashboard(), loadSyncLogs(1), loadSafety(1)])
    activeTab.value = 'safety'
  } catch (error) {
    notice.value = error?.response?.data?.message || error?.message || 'Safety check gagal dijalankan.'
    noticeType.value = 'error'
  } finally {
    runningSafety.value = false
  }
}

onMounted(loadAll)
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
.ghost { background:#fff; color:#0f172a; border:1px solid #dbe3ef; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:14px; border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.notice.error { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
.status-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; margin-bottom:14px; }
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
.tabs { display:flex; gap:8px; margin:14px 0; border-bottom:1px solid #e2e8f0; }
.tabs button { background:transparent; color:#475569; border-radius:6px 6px 0 0; border:1px solid transparent; }
.tabs button.active { background:#fff; color:#0f172a; border-color:#e2e8f0; border-bottom-color:#fff; margin-bottom:-1px; }
.panel { padding:14px; }
.filter-row { display:grid; grid-template-columns:repeat(3,minmax(160px,1fr)); gap:10px; margin-bottom:12px; }
select,input { width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:9px 10px; background:#fff; }
.table-wrap { overflow:auto; border:1px solid #e2e8f0; border-radius:6px; }
table { width:100%; border-collapse:collapse; font-size:13px; min-width:900px; }
th,td { padding:10px 12px; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top; }
th { background:#f8fafc; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
td { color:#0f172a; }
.empty { text-align:center; color:#64748b; padding:22px; }
.pagination { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:12px; color:#475569; font-size:13px; }
.safety-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:12px; }
.safety-summary div { border:1px solid #e2e8f0; border-radius:6px; padding:12px; background:#f8fafc; }
.safety-summary span { display:block; color:#64748b; font-size:12px; margin-bottom:4px; }
.safety-summary strong { font-size:15px; }
@media (max-width:1180px) { .status-grid,.safety-summary { grid-template-columns:1fr; } }
@media (max-width:820px) { .page-shell { margin-left:0; padding:16px; } .page-header,.header-actions { flex-direction:column; align-items:stretch; } .filter-row { grid-template-columns:1fr; } }
</style>
