<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Anomali Stok</h1>
      </div>
      <button class="primary" type="button" @click="loadAnomalies(1)" :disabled="loading">
        {{ loading ? 'Memuat...' : 'Refresh' }}
      </button>
    </header>

    <p v-if="notice.message" :class="['notice', notice.type]">{{ notice.message }}</p>

    <section class="summary-grid">
      <article><span>Total anomali</span><strong>{{ summary.total_anomalies || 0 }}</strong></article>
      <article><span>Stok beda</span><strong>{{ summary.stock_mismatch || 0 }}</strong></article>
      <article><span>Stok Shopee kosong</span><strong>{{ summary.missing_shopee_stock || 0 }}</strong></article>
      <article><span>Stok TikTok kosong</span><strong>{{ summary.missing_tiktok_stock || 0 }}</strong></article>
      <article><span>Mapping belum lengkap</span><strong>{{ summary.incomplete_mapping || 0 }}</strong></article>
      <article><span>Safety terakhir</span><strong>{{ formatDate(summary.last_safety_run) }}</strong></article>
    </section>

    <section class="panel">
      <div class="filter-row">
        <input v-model.trim="filters.search" type="search" placeholder="Cari SKU, produk, atau varian" @keyup.enter="loadAnomalies(1)" />
        <select v-model="filters.type" @change="loadAnomalies(1)">
          <option value="">Semua anomali</option>
          <option value="stock_mismatch">Stok Shopee dan TikTok beda</option>
          <option value="missing_shopee_stock">Stok Shopee belum ada</option>
          <option value="missing_tiktok_stock">Stok TikTok belum ada</option>
          <option value="incomplete_mapping">Mapping marketplace belum lengkap</option>
        </select>
        <button class="ghost" type="button" @click="loadAnomalies(1)" :disabled="loading">Terapkan</button>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>SKU</th>
              <th>Produk</th>
              <th>Varian</th>
              <th>Shopee</th>
              <th>TikTok</th>
              <th>Selisih</th>
              <th>Jenis</th>
              <th>Aksi</th>
              <th>Catatan</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="`${row.sku}-${row.shopee_model_id}-${row.tiktok_sku_id}`" :class="row.severity">
              <td>
                <strong>{{ row.sku || '-' }}</strong>
                <small>{{ row.shopee_product_id || '-' }} / {{ row.tiktok_product_id || '-' }}</small>
              </td>
              <td>{{ row.product_name || '-' }}</td>
              <td>{{ row.variant_name || '-' }}</td>
              <td>{{ stockLabel(row.shopee_stock) }}</td>
              <td>{{ stockLabel(row.tiktok_stock) }}</td>
              <td>{{ row.difference ?? '-' }}</td>
              <td><span :class="['badge', row.severity]">{{ issueLabel(row.issue_type) }}</span></td>
              <td>
                <div v-if="row.issue_type === 'stock_mismatch'" class="row-actions">
                  <button
                    class="mini"
                    type="button"
                    :disabled="Boolean(syncingKey)"
                    @click="syncRow(row, 'shopee')"
                  >
                    {{ isSyncing(row, 'shopee') ? 'Sinkron...' : 'Sinkron dari Shopee' }}
                  </button>
                  <button
                    class="mini tiktok"
                    type="button"
                    :disabled="Boolean(syncingKey)"
                    @click="syncRow(row, 'tiktok')"
                  >
                    {{ isSyncing(row, 'tiktok') ? 'Sinkron...' : 'Sinkron dari TikTok' }}
                  </button>
                </div>
                <span v-else class="muted">-</span>
              </td>
              <td>{{ row.message || '-' }}</td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="9" class="empty">Tidak ada anomali stok untuk filter ini.</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <button class="ghost" type="button" :disabled="pagination.page <= 1" @click="loadAnomalies(pagination.page - 1)">Prev</button>
        <span>Halaman {{ pagination.page || 1 }} / {{ pagination.last_page || 1 }} | {{ pagination.total || 0 }} data</span>
        <button class="ghost" type="button" :disabled="pagination.page >= pagination.last_page" @click="loadAnomalies(pagination.page + 1)">Next</button>
      </div>
    </section>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const notice = ref({ type: '', message: '' })
const syncingKey = ref('')
const rows = ref([])
const summary = ref({})
const pagination = ref({ page: 1, last_page: 1, total: 0 })
const filters = reactive({ type: '', search: '' })

const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '-'
const stockLabel = (value) => value === null || value === undefined ? '-' : value
const issueLabel = (type) => ({
  stock_mismatch: 'Stok beda',
  missing_shopee_stock: 'Shopee kosong',
  missing_tiktok_stock: 'TikTok kosong',
  incomplete_mapping: 'Mapping belum lengkap'
}[type] || type || '-')

const rowKey = (row, source) => `${row.sku || ''}:${row.shopee_model_id || ''}:${row.tiktok_sku_id || ''}:${source}`
const isSyncing = (row, source) => syncingKey.value === rowKey(row, source)
const setNotice = (type, message) => {
  notice.value = { type, message }
}

const loadAnomalies = async (page = pagination.value.page || 1) => {
  loading.value = true
  setNotice('', '')
  try {
    const { data } = await omnichannelService.autoSyncStockAnomalies({ ...filters, page, per_page: 30 })
    rows.value = data.items || []
    summary.value = data.summary || {}
    pagination.value = data.pagination || pagination.value
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Data anomali stok gagal dimuat.')
  } finally {
    loading.value = false
  }
}

const syncRow = async (row, sourceMarketplace) => {
  syncingKey.value = rowKey(row, sourceMarketplace)
  setNotice('', '')
  try {
    const { data } = await omnichannelService.syncAutoSyncStockAnomaly({
      sku: row.sku,
      source_marketplace: sourceMarketplace
    })
    await loadAnomalies(pagination.value.page || 1)
    setNotice('success', data?.message || `Sinkron stok dari ${sourceMarketplace === 'shopee' ? 'Shopee' : 'TikTok'} berhasil.`)
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Sinkron stok anomali gagal dijalankan.')
  } finally {
    syncingKey.value = ''
  }
}

onMounted(() => loadAnomalies(1))
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; color:#0f172a; }
.page-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
.page-header p { color:#64748b; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
.page-header h1 { font-size:28px; line-height:1.15; margin-top:4px; }
button { border:0; border-radius:6px; padding:9px 13px; font-weight:700; cursor:pointer; }
button:disabled { opacity:.6; cursor:not-allowed; }
.primary { background:#0f5fc7; color:#fff; }
.ghost { background:#fff; color:#0f172a; border:1px solid #dbe3ef; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:14px; }
.notice.error { border:1px solid #fecaca; background:#fef2f2; color:#991b1b; }
.notice.success { border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-bottom:14px; }
.summary-grid article { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.summary-grid span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.summary-grid strong { font-size:20px; }
.panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); padding:14px; }
.filter-row { display:grid; grid-template-columns:1fr 260px 120px; gap:10px; margin-bottom:12px; }
select,input { width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:9px 10px; background:#fff; }
.table-wrap { overflow:auto; border:1px solid #e2e8f0; border-radius:6px; }
table { width:100%; border-collapse:collapse; font-size:13px; min-width:1080px; }
th,td { padding:10px 12px; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top; }
th { background:#f8fafc; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
td small { display:block; color:#64748b; font-size:11px; margin-top:3px; }
tr.warning td { background:#fffbeb; }
tr.error td { background:#fef2f2; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:800; white-space:nowrap; }
.badge.warning { background:#fef3c7; color:#92400e; }
.badge.error { background:#fee2e2; color:#991b1b; }
.row-actions { display:flex; flex-wrap:wrap; gap:8px; min-width:270px; }
.mini { background:#0f5fc7; color:#fff; padding:7px 10px; font-size:12px; white-space:nowrap; }
.mini.tiktok { background:#111827; }
.muted { color:#94a3b8; }
.empty { text-align:center; color:#64748b; padding:22px; }
.pagination { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:12px; color:#475569; font-size:13px; }
@media (max-width:820px) {
  .page-shell { margin-left:0; padding:16px; }
  .page-header { flex-direction:column; align-items:stretch; }
  .filter-row { grid-template-columns:1fr; }
}
</style>
