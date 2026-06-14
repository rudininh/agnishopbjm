<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Produk</p>
        <h1>Anomali Gambar Variant</h1>
      </div>
      <div class="header-actions">
        <button class="danger" type="button" @click="syncTiktokImagesFromShopee" :disabled="loading || syncingImages">
          {{ syncingImages ? 'Update gambar...' : 'Update Gambar TikTok dari Shopee' }}
        </button>
        <button class="primary" type="button" @click="loadData(1)" :disabled="loading || syncingImages">
          {{ loading ? 'Memuat...' : 'Scan Gambar' }}
        </button>
      </div>
    </header>

    <p v-if="notice.message" :class="['notice', notice.type]">{{ notice.message }}</p>

    <section v-if="syncReport" class="sync-report">
      <div class="sync-report-head">
        <strong>Laporan sinkron gambar</strong>
        <span>Batch {{ syncReport.batches }} | SKU diproses {{ syncReport.matched }} | Warning {{ syncReport.warnings }}</span>
      </div>

      <div v-if="syncReport.failureReasons.length || syncReport.warningReasons.length" class="reason-grid">
        <article v-if="syncReport.failureReasons.length" class="reason-card error">
          <h2>Alasan gagal</h2>
          <ul>
            <li v-for="reason in syncReport.failureReasons" :key="reason.message">
              <strong>{{ reason.count }}x</strong>
              <span>{{ reason.message }}</span>
              <small v-if="reason.samples.length">Contoh: {{ reason.samples.map(formatReasonSample).join(' ; ') }}</small>
            </li>
          </ul>
        </article>

        <article v-if="syncReport.warningReasons.length" class="reason-card warning">
          <h2>Warning</h2>
          <ul>
            <li v-for="reason in syncReport.warningReasons" :key="reason.message">
              <strong>{{ reason.count }}x</strong>
              <span>{{ reason.message }}</span>
              <small v-if="reason.samples.length">Contoh: {{ reason.samples.map(formatReasonSample).join(' ; ') }}</small>
            </li>
          </ul>
        </article>
      </div>

      <p v-if="syncReport.skippedText.length" class="skipped-note">
        Dilewati saat scan terakhir: {{ syncReport.skippedText.join(' | ') }}
      </p>
      <p v-if="syncReport.stoppedByLimit" class="skipped-note">
        Proses berhenti di batas aman 100 batch. Jalankan tombol lagi bila masih ada data yang perlu dilanjutkan.
      </p>
    </section>

    <section class="summary-grid">
      <article><span>Total anomali</span><strong>{{ summary.total_anomalies || 0 }}</strong></article>
      <article><span>Shopee kosong</span><strong>{{ summary.missing_shopee_image || 0 }}</strong></article>
      <article><span>TikTok kosong</span><strong>{{ summary.missing_tiktok_image || 0 }}</strong></article>
      <article><span>Gambar beda</span><strong>{{ summary.image_url_mismatch || 0 }}</strong></article>
      <article><span>Mapping belum lengkap</span><strong>{{ summary.incomplete_mapping || 0 }}</strong></article>
    </section>

    <section class="panel">
      <div class="filter-row">
        <input v-model.trim="filters.search" type="search" placeholder="Cari SKU, produk, varian, atau product ID" @keyup.enter="loadData(1)" />
        <select v-model="filters.type" @change="loadData(1)">
          <option value="">Semua anomali</option>
          <option value="missing_shopee_image">Gambar Shopee kosong</option>
          <option value="missing_tiktok_image">Gambar TikTok kosong</option>
          <option value="image_url_mismatch">Gambar berbeda</option>
          <option value="incomplete_mapping">Mapping belum lengkap</option>
        </select>
        <button class="ghost" type="button" @click="loadData(1)" :disabled="loading">Terapkan</button>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Produk</th>
              <th>Shopee</th>
              <th>TikTok</th>
              <th>Status</th>
              <th>Rekomendasi</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.stock_master_id" :class="row.severity">
              <td>
                <strong>{{ row.product_name || '-' }}</strong>
                <small>{{ row.variant_name || '-' }}</small>
                <small>SKU: {{ row.sku || '-' }} | SM: {{ row.stock_master_id }}</small>
              </td>
              <td>
                <div class="image-cell">
                  <img v-if="row.shopee.image_url" :src="row.shopee.image_url" :alt="row.shopee.variant_name || 'Shopee'" />
                  <div v-else class="image-empty">Kosong</div>
                  <div>
                    <strong>{{ row.shopee.variant_name || '-' }}</strong>
                    <small>{{ row.shopee.product_id || '-' }} / {{ row.shopee.model_id || '-' }}</small>
                    <div class="actions">
                      <a v-if="row.shopee.image_url" :href="row.shopee.image_url" target="_blank" rel="noreferrer">Buka</a>
                      <button type="button" class="link-button" :disabled="!row.shopee.image_url" @click="copyUrl(row.shopee.image_url)">Copy</button>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <div class="image-cell">
                  <img v-if="row.tiktok.image_url" :src="row.tiktok.image_url" :alt="row.tiktok.variant_name || 'TikTok'" />
                  <div v-else class="image-empty">Kosong</div>
                  <div>
                    <strong>{{ row.tiktok.variant_name || '-' }}</strong>
                    <small>{{ row.tiktok.product_id || '-' }} / {{ row.tiktok.sku_id || '-' }}</small>
                    <div class="actions">
                      <a v-if="row.tiktok.image_url" :href="row.tiktok.image_url" target="_blank" rel="noreferrer">Buka</a>
                      <button type="button" class="link-button" :disabled="!row.tiktok.image_url" @click="copyUrl(row.tiktok.image_url)">Copy</button>
                    </div>
                  </div>
                </div>
              </td>
              <td>
                <span :class="['badge', row.severity]">{{ issueLabel(row.issue_type) }}</span>
                <small>{{ row.message }}</small>
              </td>
              <td>
                <strong>{{ recommendation(row) }}</strong>
                <small>Samakan gambar pada marketplace tujuan memakai URL sumber yang benar.</small>
                <div v-if="row.internal_image_url" class="internal-source">
                  <span>Internal</span>
                  <a :href="row.internal_image_url" target="_blank" rel="noreferrer">Buka gambar</a>
                  <button type="button" class="link-button" @click="copyUrl(row.internal_image_url)">Copy</button>
                </div>
              </td>
            </tr>
            <tr v-if="!rows.length">
              <td colspan="5" class="empty">{{ loading ? 'Memindai gambar varian...' : 'Tidak ada anomali gambar untuk filter ini.' }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="pagination">
        <button class="ghost" type="button" :disabled="pagination.page <= 1" @click="loadData(pagination.page - 1)">Prev</button>
        <span>Halaman {{ pagination.page || 1 }} / {{ pagination.last_page || 1 }} | {{ pagination.total || 0 }} data</span>
        <button class="ghost" type="button" :disabled="pagination.page >= pagination.last_page" @click="loadData(pagination.page + 1)">Next</button>
      </div>
    </section>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const syncingImages = ref(false)
const rows = ref([])
const summary = ref({})
const pagination = ref({ page: 1, last_page: 1, total: 0 })
const filters = reactive({ search: '', type: '' })
const notice = ref({ type: '', message: '' })
const syncReport = ref(null)

const issueLabel = (type) => ({
  missing_shopee_image: 'Shopee kosong',
  missing_tiktok_image: 'TikTok kosong',
  image_url_mismatch: 'Gambar beda',
  incomplete_mapping: 'Mapping belum lengkap'
}[type] || type || '-')

const recommendation = (row) => {
  if (row.issue_type === 'missing_shopee_image') return 'Gunakan gambar TikTok untuk Shopee'
  if (row.issue_type === 'missing_tiktok_image') return 'Gunakan gambar Shopee untuk TikTok'
  if (row.issue_type === 'image_url_mismatch') return row.suggested_source === 'shopee' ? 'Review lalu samakan dari Shopee' : 'Review lalu samakan dari TikTok'
  if (row.suggested_source === 'internal') return 'Gunakan gambar internal sebagai acuan'
  return 'Lengkapi mapping varian dulu'
}

const setNotice = (type, message) => {
  notice.value = { type, message }
}

const skippedReasonLabels = {
  missing_shopee_sku: 'SKU Shopee kosong',
  missing_shopee_image: 'Gambar Shopee kosong',
  missing_exact_tiktok_sku: 'SKU TikTok tidak cocok persis',
  conflicting_target_image: 'Target TikTok konflik',
  already_synced: 'Sudah sama'
}

const normalizeReasonGroups = (groups = []) => {
  if (Array.isArray(groups)) return groups
  if (groups && typeof groups === 'object') return Object.values(groups)
  return []
}

const mergeReasonGroups = (target, groups = []) => {
  normalizeReasonGroups(groups).forEach((reason) => {
    const message = String(reason?.message || 'Tanpa pesan detail.')
    if (!target[message]) {
      target[message] = { message, count: 0, samples: [] }
    }

    target[message].count += Number(reason?.count || 0)
    ;(reason?.samples || []).forEach((sample) => {
      const sampleKey = JSON.stringify(sample)
      const exists = target[message].samples.some((item) => JSON.stringify(item) === sampleKey)
      if (!exists && target[message].samples.length < 5) {
        target[message].samples.push(sample)
      }
    })
  })
}

const reasonGroupsToList = (groups) => Object.values(groups)
  .filter((reason) => reason.count > 0)
  .sort((a, b) => b.count - a.count)

const formatSkippedReasons = (reasons = {}) => Object.entries(reasons || {})
  .filter(([, count]) => Number(count) > 0)
  .map(([key, count]) => `${skippedReasonLabels[key] || key}: ${count}`)

const formatReasonSample = (sample = {}) => {
  const parts = [
    sample.seller_sku ? `SKU ${sample.seller_sku}` : '',
    sample.product_id ? `Product ${sample.product_id}` : '',
    sample.sku_id ? `Varian ${sample.sku_id}` : '',
    sample.stock_master_id ? `SM ${sample.stock_master_id}` : ''
  ].filter(Boolean)
  return parts.join(' / ') || '-'
}

const copyUrl = async (url) => {
  if (!url) return
  try {
    await navigator.clipboard.writeText(url)
    setNotice('success', 'URL gambar berhasil disalin.')
  } catch (error) {
    setNotice('error', 'URL gambar gagal disalin.')
  }
}

const syncTiktokImagesFromShopee = async () => {
  const confirmed = window.confirm('Paksa update real semua gambar product dan variant TikTok mengikuti Shopee berdasarkan SKU yang sama persis? Aksi ini hanya mengirim perubahan gambar ke TikTok.')
  if (!confirmed) return

  syncingImages.value = true
  syncReport.value = null
  setNotice('', '')
  try {
    const maxBatches = 100
    const failureReasonMap = {}
    const warningReasonMap = {}
    let skippedReasons = {}
    let stoppedByLimit = false
    const totals = {
      batches: 0,
      matched: 0,
      updatedProducts: 0,
      updatedVariants: 0,
      skipped: 0,
      failed: 0,
      warnings: 0
    }

    for (let batch = 1; batch <= maxBatches; batch += 1) {
      const offset = (batch - 1) * 10
      const { data } = await omnichannelService.syncTiktokImagesFromShopee({ limit: 10, offset, force: true })
      totals.batches += 1
      totals.matched += data?.matched || 0
      totals.updatedProducts += data?.updated_products || 0
      totals.updatedVariants += data?.updated_variants || 0
      totals.skipped = data?.skipped || 0
      totals.failed += data?.failed || 0
      totals.warnings += data?.warnings || 0
      skippedReasons = data?.skipped_reasons || skippedReasons
      mergeReasonGroups(failureReasonMap, data?.failure_reasons)
      mergeReasonGroups(warningReasonMap, data?.warning_reasons)

      setNotice('success', `Update gambar berjalan... batch ${totals.batches}, variant update ${totals.updatedVariants}, gagal ${totals.failed}`)

      if ((data?.matched || 0) === 0 || !data?.has_more) {
        break
      }
      if (batch === maxBatches) {
        stoppedByLimit = true
      }
    }

    const parts = [
      'Sinkron gambar TikTok dari Shopee selesai diproses.',
      `Batch: ${totals.batches}`,
      `SKU cocok: ${totals.matched}`,
      `Produk update: ${totals.updatedProducts}`,
      `Variant update: ${totals.updatedVariants}`,
      `Dilewati: ${totals.skipped}`,
      `Gagal: ${totals.failed}`,
      `Warning: ${totals.warnings}`
    ]
    if (stoppedByLimit) parts.push('Batas batch aman tercapai')

    syncReport.value = {
      ...totals,
      failureReasons: reasonGroupsToList(failureReasonMap),
      warningReasons: reasonGroupsToList(warningReasonMap),
      skippedText: formatSkippedReasons(skippedReasons),
      stoppedByLimit
    }

    const noticeType = totals.failed > 0 ? 'error' : (totals.warnings > 0 ? 'warning' : 'success')
    const noticeMessage = parts.join(' | ')
    await loadData(1)
    setNotice(noticeType, noticeMessage)
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Update gambar TikTok dari Shopee gagal dijalankan.')
  } finally {
    syncingImages.value = false
  }
}

const loadData = async (page = 1) => {
  loading.value = true
  setNotice('', '')
  try {
    const { data } = await omnichannelService.imageVariantAnomalies({
      ...filters,
      page,
      per_page: 30
    })
    rows.value = data.items || []
    summary.value = data.summary || {}
    pagination.value = data.pagination || pagination.value
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Anomali gambar variant gagal dimuat.')
  } finally {
    loading.value = false
  }
}

onMounted(() => loadData(1))
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; color:#0f172a; }
.page-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
.page-header p { color:#64748b; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
.page-header h1 { font-size:28px; line-height:1.15; margin-top:4px; }
.header-actions { display:flex; flex-wrap:wrap; justify-content:flex-end; gap:10px; }
button { border:0; border-radius:6px; padding:9px 13px; font-weight:700; cursor:pointer; }
button:disabled { opacity:.55; cursor:not-allowed; }
.primary { background:#0f5fc7; color:#fff; }
.danger { background:#b91c1c; color:#fff; }
.ghost { background:#fff; color:#0f172a; border:1px solid #dbe3ef; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:14px; }
.notice.error { border:1px solid #fecaca; background:#fef2f2; color:#991b1b; }
.notice.success { border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.notice.warning { border:1px solid #fde68a; background:#fffbeb; color:#92400e; }
.sync-report { border:1px solid #dbe3ef; background:#fff; border-radius:8px; padding:14px; margin-bottom:14px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.sync-report-head { display:flex; flex-wrap:wrap; justify-content:space-between; gap:8px; margin-bottom:10px; }
.sync-report-head strong { font-size:14px; }
.sync-report-head span { color:#475569; font-size:12px; font-weight:700; }
.reason-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:10px; }
.reason-card { border-radius:8px; padding:12px; border:1px solid #e2e8f0; }
.reason-card.error { border-color:#fecaca; background:#fef2f2; }
.reason-card.warning { border-color:#fde68a; background:#fffbeb; }
.reason-card h2 { margin:0 0 8px; font-size:13px; }
.reason-card ul { list-style:none; padding:0; margin:0; display:grid; gap:8px; }
.reason-card li { display:grid; grid-template-columns:auto minmax(0,1fr); gap:6px 8px; align-items:start; }
.reason-card li strong { font-size:12px; }
.reason-card li span { font-size:12px; line-height:1.35; }
.reason-card li small { grid-column:1 / -1; color:#64748b; font-size:11px; }
.skipped-note { margin:10px 0 0; color:#475569; font-size:12px; line-height:1.4; }
.summary-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(170px,1fr)); gap:10px; margin-bottom:14px; }
.summary-grid article { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.summary-grid span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.summary-grid strong { font-size:20px; }
.panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); padding:14px; }
.filter-row { display:grid; grid-template-columns:1fr 250px 120px; gap:10px; margin-bottom:12px; }
select,input { width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:9px 10px; background:#fff; }
.table-wrap { overflow:auto; border:1px solid #e2e8f0; border-radius:6px; }
table { width:100%; border-collapse:collapse; font-size:13px; min-width:1120px; }
th,td { padding:10px 12px; border-bottom:1px solid #edf2f7; text-align:left; vertical-align:top; }
th { background:#f8fafc; color:#475569; font-size:12px; text-transform:uppercase; letter-spacing:.04em; }
td small { display:block; color:#64748b; font-size:11px; margin-top:4px; }
tr.warning td { background:#fffbeb; }
tr.error td { background:#fef2f2; }
.image-cell { display:grid; grid-template-columns:82px minmax(0,1fr); gap:10px; min-width:250px; }
.image-cell img,.image-empty { width:82px; height:82px; border-radius:6px; object-fit:cover; background:#eef2f7; }
.image-empty { display:grid; place-items:center; color:#64748b; font-size:12px; font-weight:800; }
.actions { display:flex; gap:8px; margin-top:8px; }
.actions a,.link-button { color:#0f5fc7; font-size:12px; font-weight:800; text-decoration:none; }
.link-button { background:transparent; border:0; padding:0; }
.internal-source { display:flex; flex-wrap:wrap; gap:8px; margin-top:10px; }
.internal-source span { color:#475569; font-size:12px; font-weight:800; }
.internal-source a { color:#0f5fc7; font-size:12px; font-weight:800; text-decoration:none; }
.badge { display:inline-flex; align-items:center; border-radius:999px; padding:4px 9px; font-size:12px; font-weight:800; white-space:nowrap; }
.badge.warning { background:#fef3c7; color:#92400e; }
.badge.error { background:#fee2e2; color:#991b1b; }
.empty { text-align:center; color:#64748b; padding:22px; }
.pagination { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:12px; color:#475569; font-size:13px; }
@media (max-width:820px) {
  .page-shell { margin-left:0; padding:16px; }
  .page-header { flex-direction:column; align-items:stretch; }
  .header-actions { justify-content:stretch; }
  .header-actions button { width:100%; }
  .filter-row { grid-template-columns:1fr; }
}
</style>
