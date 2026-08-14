<template>
  <section class='page-shell'>
    <header class='page-header'>
      <div><p>Marketplace</p><h1>Pesanan Gita</h1></div>
      <div class='header-actions'>
        <RouterLink class='ghost mass-upload-link' :to='gitaShopMassUploadRoute'>Upload Otomatis Gitashop</RouterLink>
        <button class='ghost' type='button' :disabled='wakingScraperWorker' @click='wakeScraperWorker'>{{ wakingScraperWorker ? 'Menjalankan...' : 'Jalankan Scraper PC' }}</button>
        <button class='primary' type='button' :disabled='loading || syncingAll || !hasSyncableItems' @click='syncAll'>{{ syncingAll ? 'Menyinkronkan...' : 'Sinkronkan Semua' }}</button>
        <button class='ghost' type='button' :disabled='loading || syncingAll' @click='loadReport(pagination.page)'>{{ loading ? 'Memuat...' : 'Muat ulang' }}</button>
      </div>
    </header>

    <p v-if='errorMessage' class='notice error'>{{ errorMessage }}</p>
    <p v-if='syncMessage' class='notice success'>{{ syncMessage }}</p>
    <p v-if='launcherMessage' :class='[notice, launcherMessage.type]'>{{ launcherMessage.text }}</p>

    <section class='guide'>
      <div><p>Collector lokal</p><h2>Login browser pertama kali</h2><pre>{{ gitaCollectorCalibrationCommand }}</pre></div>
      <div><p>Collector harian</p><h2>Ambil pesanan Gita</h2><pre>{{ dailyGitaCollectorCommand }}</pre></div>
      <div><p>Worker PC</p><h2>Fallback jika launcher otomatis gagal</h2><pre>{{ gitaOrderScraperManualCommand }}</pre><small>Jalankan satu kali saja. Login atau verifikasi tetap perlu diselesaikan manusia di browser Gita.</small></div>
    </section>

    <section v-if='latestRun' class='run-overview'>
      <div>
        <span :class='[run-status, latestRun.status]'>{{ runStatusLabel(latestRun.status) }}</span>
        <strong>Pengambilan terakhir: {{ formatDate(latestRun.finished_at) }}</strong>
        <small v-if='latestRun.message'>{{ latestRun.message }}</small>
      </div>
      <small>Mulai {{ formatDate(latestRun.started_at) }}</small>
    </section>
    <section v-else-if='!loading' class='run-overview empty-overview'>Belum ada riwayat pengambilan pesanan Gita.</section>

    <section class='summary-grid'>
      <article><span>Baris pesanan</span><strong>{{ latestSummary.item_count }}</strong></article>
      <article><span>Jumlah item</span><strong>{{ latestSummary.quantity_count }}</strong></article>
      <article><span>SKU cocok</span><strong>{{ latestSummary.matched_count }}</strong></article>
      <article><span>Tidak cocok</span><strong>{{ latestSummary.unmatched_count }}</strong></article>
      <article><span>SKU master ganda</span><strong>{{ latestSummary.duplicate_master_count }}</strong></article>
    </section>

    <section class='panel'>
      <div class='filter-row'>
        <label>
          <span>Status pesanan</span>
          <select v-model='filters.tabStatus' :disabled='loading' @change='loadReport(1)'>
            <option value=''>Semua status</option>
            <option value='to_ship'>Perlu Dikirim</option>
            <option value='shipped'>Dikirim</option>
          </select>
        </label>
        <label>
          <span>Status pencocokan</span>
          <select v-model='filters.matchStatus' :disabled='loading' @change='loadReport(1)'>
            <option value=''>Semua status</option>
            <option value='matched'>Cocok</option>
            <option value='unmatched'>Tidak ditemukan di Stock Master</option>
            <option value='duplicate_master_sku'>SKU Stock Master ganda</option>
          </select>
        </label>
      </div>

      <div class='table-wrap'>
        <table>
          <thead><tr><th>No. Pesanan</th><th>Status</th><th>SKU Seller</th><th>Produk</th><th>Varian</th><th>Qty</th><th>Stock Master ID</th><th>Pencocokan</th><th>Sinkronisasi</th><th>Waktu tangkap</th><th>Aksi</th></tr></thead>
          <tbody>
            <tr v-for='item in items' :key='item.id'>
              <td><strong>{{ item.seller_order_id }}</strong></td>
              <td><span :class='[tab-status, item.tab_status]'>{{ gitaOrderTabStatusLabel(item.tab_status) }}</span></td>
              <td><strong>{{ item.seller_sku }}</strong></td>
              <td>{{ item.product_title }}</td><td>{{ item.variant_label || '-' }}</td><td>{{ item.quantity }}</td><td>{{ item.stock_master_id ?? '-' }}</td>
              <td><span :class='[match-status, item.match_status]'>{{ gitaOrderMatchStatusLabel(item.match_status) }}</span></td>
              <td><span :class='[sync-status, item.sync_status]'>{{ gitaOrderSyncStatusLabel(item.sync_status) }}</span><small v-if='item.sync_message' class='sync-message'>{{ item.sync_message }}</small></td><td>{{ formatDate(item.captured_at) }}</td>
              <td><button v-if='canSyncGitaOrder(item)' class='small-primary' type='button' :disabled='syncingItemId === item.id || syncingAll' @click='syncItem(item)'>{{ syncingItemId === item.id ? 'Memproses...' : gitaOrderSyncActionLabel(item) }}</button><span v-else>-</span></td>
            </tr>
            <tr v-if='!items.length'><td colspan='11' class='empty'>{{ loading ? 'Memuat riwayat pesanan Gita...' : 'Tidak ada pesanan untuk filter ini.' }}</td></tr>
          </tbody>
        </table>
      </div>

      <div class='pagination'>
        <button class='ghost' type='button' :disabled='loading || pagination.page <= 1' @click='loadReport(pagination.page - 1)'>Sebelumnya</button>
        <span>Halaman {{ pagination.page }} / {{ pagination.last_page }} | {{ pagination.total }} baris</span>
        <button class='ghost' type='button' :disabled='loading || pagination.page >= pagination.last_page' @click='loadReport(pagination.page + 1)'>Berikutnya</button>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'
import { buildGitaOrderScrapeQuery, canSyncGitaOrder, dailyGitaCollectorCommand, formatGitaOrderDate, gitaCollectorCalibrationCommand, gitaOrderMatchStatusLabel, gitaOrderScraperLauncherMessage, gitaOrderScraperManualCommand, gitaOrderSyncActionLabel, gitaOrderSyncStatusLabel, gitaOrderTabStatusLabel, gitaShopMassUploadRoute } from './gitaOrderScrapeState'

const loading = ref(false)
const errorMessage = ref('')
const syncMessage = ref('')
const launcherMessage = ref(null)
const syncingAll = ref(false)
const syncingItemId = ref(null)
const wakingScraperWorker = ref(false)
const latestRun = ref(null)
const items = ref([])
const pagination = ref({ page: 1, last_page: 1, total: 0 })
const filters = reactive({ matchStatus: '', tabStatus: '' })
const latestSummary = computed(() => latestRun.value?.summary || {
  item_count: 0,
  quantity_count: 0,
  matched_count: 0,
  unmatched_count: 0,
  duplicate_master_count: 0
})
const hasSyncableItems = computed(() => items.value.some(canSyncGitaOrder))
let scraperPollTimer = null

const loadReport = async (page = 1) => {
  loading.value = true
  errorMessage.value = ''

  try {
    const query = buildGitaOrderScrapeQuery({
      matchStatus: filters.matchStatus,
      tabStatus: filters.tabStatus,
      page
    })
    const [latestResponse, itemsResponse] = await Promise.all([
      omnichannelService.gitaOrderScrapeLatest(),
      omnichannelService.gitaOrderScrapeItems(query)
    ])
    latestRun.value = latestResponse.data?.data || null
    items.value = itemsResponse.data?.items || []
    pagination.value = itemsResponse.data?.pagination || pagination.value
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || error?.message || 'Riwayat pesanan Gita tidak dapat dimuat.'
  } finally {
    loading.value = false
  }
}

const runStatusLabel = (status) => ({ success: 'Berhasil', needs_login: 'Perlu login', failed: 'Gagal' }[status] || 'Tidak dikenal')
const syncAll = async () => {
  syncingAll.value = true
  errorMessage.value = ''
  syncMessage.value = ''
  try {
    const { data } = await omnichannelService.syncGitaOrderItems()
    const summary = data?.data?.summary || {}
    syncMessage.value = `Sinkronisasi selesai. Berhasil ${summary.synced || 0}, gagal ${summary.failed || 0}, perlu cek ${summary.blocked || 0}.`
    await loadReport(pagination.value.page)
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || error?.message || 'Sinkronisasi semua pesanan Gita gagal.'
  } finally {
    syncingAll.value = false
  }
}

const syncItem = async (item) => {
  syncingItemId.value = item.id
  errorMessage.value = ''
  syncMessage.value = ''
  try {
    const { data } = await omnichannelService.syncGitaOrderItem(item.id)
    syncMessage.value = data?.data?.message || 'Sinkronisasi pesanan Gita selesai.'
    await loadReport(pagination.value.page)
  } catch (error) {
    errorMessage.value = error?.response?.data?.message || error?.message || 'Sinkronisasi pesanan Gita gagal.'
  } finally {
    syncingItemId.value = null
  }
}

const stopScraperPolling = () => {
  if (scraperPollTimer) clearInterval(scraperPollTimer)
  scraperPollTimer = null
}

const refreshForScraperWorker = async () => {
  const previousFinishedAt = latestRun.value?.finished_at || ''
  let attempts = 0

  stopScraperPolling()
  await loadReport(1)
  scraperPollTimer = setInterval(async () => {
    if (loading.value) return

    attempts += 1
    await loadReport(1)
    if (attempts >= 180 || (latestRun.value?.finished_at && latestRun.value.finished_at !== previousFinishedAt)) {
      stopScraperPolling()
    }
  }, 5000)
}

const wakeScraperWorker = async () => {
  wakingScraperWorker.value = true
  errorMessage.value = ''
  launcherMessage.value = null

  try {
    const response = await omnichannelService.wakeGitaOrderScraperWorker()
    const result = response.data?.data || {}
    launcherMessage.value = gitaOrderScraperLauncherMessage(result)

    if (['started', 'already_running'].includes(result.status)) {
      await refreshForScraperWorker()
    }
  } catch (error) {
    const result = error?.response?.data?.data
    launcherMessage.value = gitaOrderScraperLauncherMessage(result)
  } finally {
    wakingScraperWorker.value = false
  }
}
const formatDate = formatGitaOrderDate

onMounted(() => loadReport(1))
onUnmounted(stopScraperPolling)
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; color:#0f172a; }
.page-header,.run-overview,.pagination { align-items:center; display:flex; gap:12px; justify-content:space-between; }
.header-actions { display:flex; flex-wrap:wrap; gap:8px; }
.page-header { align-items:flex-start; margin-bottom:18px; }
.page-header p { color:#64748b; font-size:13px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; }
.page-header h1 { font-size:28px; line-height:1.15; margin-top:4px; }
button { border:0; border-radius:6px; cursor:pointer; font-weight:700; padding:9px 13px; }
button:disabled { cursor:not-allowed; opacity:.6; }
.ghost { background:#fff; border:1px solid #dbe3ef; color:#0f172a; }
.mass-upload-link { align-items:center; display:inline-flex; text-decoration:none; }
.primary,.small-primary { background:#0f766e; color:#fff; }
.small-primary { font-size:12px; padding:7px 9px; }
.notice,.run-overview,.panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; }
.notice { margin-bottom:14px; padding:10px 12px; }
.notice.error { background:#fef2f2; border-color:#fecaca; color:#991b1b; }
.notice.success { background:#ecfdf5; border-color:#a7f3d0; color:#166534; }
.notice.warning { background:#fef3c7; border-color:#fde68a; color:#92400e; }
.guide { display:grid; gap:12px; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); margin-bottom:14px; }
.guide > div { background:#f8fafc; border:1px solid #dbe3ef; border-radius:8px; padding:12px; }
.guide p { color:#0f766e; font-size:12px; font-weight:800; margin-bottom:4px; text-transform:uppercase; }
.guide h2 { font-size:16px; margin-bottom:9px; }
.guide pre { background:#0f172a; border-radius:6px; color:#e2e8f0; font:12px/1.45 Consolas,monospace; margin:0; overflow:auto; padding:10px; white-space:pre-wrap; }
.guide small { color:#475569; line-height:1.45; }
.run-overview { margin-bottom:14px; padding:13px 14px; }
.run-overview > div { align-items:center; display:flex; flex-wrap:wrap; gap:10px; }
.run-overview small,.empty { color:#64748b; }
.empty-overview { color:#64748b; }
.run-status,.match-status,.tab-status,.sync-status { border-radius:999px; display:inline-flex; font-size:12px; font-weight:800; padding:4px 9px; white-space:nowrap; }
.run-status.success,.match-status.matched { background:#dcfce7; color:#166534; }
.run-status.needs_login,.match-status.unmatched,.tab-status.to_ship { background:#fef3c7; color:#92400e; }
.run-status.failed,.match-status.duplicate_master_sku { background:#fee2e2; color:#991b1b; }
.tab-status.shipped { background:#dbeafe; color:#1d4ed8; }
.sync-status.pending,.sync-status.processing { background:#fef3c7; color:#92400e; }
.sync-status.synced { background:#dcfce7; color:#166534; }
.sync-status.failed,.sync-status.blocked { background:#fee2e2; color:#991b1b; }
.sync-message { color:#64748b; display:block; font-size:11px; margin-top:5px; max-width:220px; }
.summary-grid { display:grid; gap:10px; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); margin-bottom:14px; }
.summary-grid article,.panel { box-shadow:0 1px 2px rgba(15,23,42,.05); }
.summary-grid article { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px; }
.summary-grid span { color:#64748b; display:block; font-size:12px; margin-bottom:6px; }
.summary-grid strong { font-size:20px; }
.panel { padding:14px; }
.filter-row { align-items:end; display:flex; flex-wrap:wrap; gap:14px; margin-bottom:12px; }
.filter-row label { display:grid; gap:6px; min-width:260px; }
.filter-row label > span { color:#475569; font-size:12px; font-weight:700; }
select { background:#fff; border:1px solid #cbd5e1; border-radius:6px; color:#0f172a; padding:9px 10px; }
.table-wrap { border:1px solid #e2e8f0; border-radius:6px; overflow:auto; }
table { border-collapse:collapse; font-size:13px; min-width:1560px; width:100%; }
th,td { border-bottom:1px solid #edf2f7; padding:10px 12px; text-align:left; vertical-align:top; }
th { background:#f8fafc; color:#475569; font-size:12px; letter-spacing:.04em; text-transform:uppercase; }
.empty { padding:22px; text-align:center; }
.pagination { color:#475569; font-size:13px; margin-top:12px; }
@media (max-width:820px) { .page-shell { margin-left:0; padding:16px; } .page-header,.run-overview,.pagination { align-items:stretch; flex-direction:column; } .filter-row label { min-width:100%; } }
</style>
