<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Omnichannel</p>
        <h1>Analisa Product & Variant</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="refreshAnalysis" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh Analisa' }}</button>
        <button class="ghost" @click="syncShopee" :disabled="loading || syncing">{{ syncing === 'shopee' ? 'Syncing...' : 'Sync Shopee' }}</button>
        <button class="primary" @click="syncTiktok" :disabled="loading || syncing">{{ syncing === 'tiktok' ? 'Syncing...' : 'Sync TikTok' }}</button>
      </div>
    </header>

    <p v-if="message" :class="['notice', messageTone]">{{ message }}</p>

    <div class="summary-grid">
      <article class="metric">
        <span>Total Temuan</span>
        <strong>{{ summary.total_issues || 0 }}</strong>
      </article>
      <article class="metric">
        <span>Prioritas Tinggi</span>
        <strong>{{ summary.high || 0 }}</strong>
      </article>
      <article class="metric">
        <span>Produk Double</span>
        <strong>{{ summary.duplicate_products || 0 }}</strong>
      </article>
      <article class="metric">
        <span>Varian Double</span>
        <strong>{{ summary.duplicate_variants || 0 }}</strong>
      </article>
      <article class="metric">
        <span>Anomali Varian</span>
        <strong>{{ summary.variant_anomalies || 0 }}</strong>
      </article>
      <article class="metric">
        <span>Bukan Anomali</span>
        <strong>{{ summary.confirmed_not_anomaly || 0 }}</strong>
      </article>
    </div>

    <div class="filter-panel">
      <div class="filter-row">
        <label>
          <span>Channel</span>
          <select v-model="filters.channel" @change="loadData">
            <option value="all">Shopee + TikTok</option>
            <option value="shopee">Shopee</option>
            <option value="tiktok">TikTok</option>
          </select>
        </label>
        <label>
          <span>Prioritas</span>
          <select v-model="filters.severity">
            <option value="all">Semua</option>
            <option value="high">Tinggi</option>
            <option value="warning">Perlu Cek</option>
            <option value="info">Info</option>
          </select>
        </label>
        <label>
          <span>Tipe Temuan</span>
          <select v-model="filters.type">
            <option value="all">Semua Tipe</option>
            <option value="duplicate_product">Produk Double</option>
            <option value="duplicate_variant">Varian Double</option>
            <option value="duplicate_seller_sku">Seller SKU Double</option>
            <option value="variant_anomaly">Anomali Varian</option>
          </select>
        </label>
        <label class="search-field">
          <span>Cari</span>
          <input v-model.trim="filters.search" type="search" placeholder="Nama produk, varian, item ID, SKU" />
        </label>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head">
        <div>
          <span>{{ filteredIssues.length }} temuan tampil</span>
          <strong>Last sync: Shopee {{ formatDate(summary.last_shopee_sync_at) }} | TikTok {{ formatDate(summary.last_tiktok_sync_at) }}</strong>
          <small>Terakhir refresh layar: {{ formatDateTime(lastLoadedAt) }}</small>
        </div>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Prioritas</th>
              <th>Channel</th>
              <th>Temuan</th>
              <th>Produk</th>
              <th>Bukti</th>
              <th>Aksi</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="issue in filteredIssues" :key="issue.id">
              <tr :class="['issue-row', issue.severity]">
                <td>
                  <span :class="['badge', issue.severity]">{{ severityLabel(issue.severity) }}</span>
                </td>
                <td>
                  <span :class="['channel-pill', issue.channel]">{{ channelLabel(issue.channel) }}</span>
                </td>
                <td>
                  <strong>{{ issue.title }}</strong>
                  <small>{{ issue.type_label || typeLabel(issue.type) }}</small>
                  <small>{{ issue.description }}</small>
                  <small v-if="issue.action_plan?.summary" class="solution-summary">Solusi: {{ issue.action_plan.summary }}</small>
                </td>
                <td>
                  <strong>{{ issue.product_name || '-' }}</strong>
                  <small>{{ productIdsLabel(issue) }}</small>
                  <small>{{ productsSummary(issue.products) }}</small>
                </td>
                <td>
                  <small>{{ evidenceLine(issue) }}</small>
                  <small v-if="issue.evidence?.near_miss_tokens?.length">Mirip typo: {{ issue.evidence.near_miss_tokens.join(', ') }}</small>
                </td>
                <td>
                  <div class="action-stack">
                    <button class="ghost tiny" @click="toggle(issue.id)">{{ expanded[issue.id] ? 'Tutup' : 'Detail' }}</button>
                    <button class="confirm tiny" :disabled="loading || confirming[issue.id]" @click="confirmNotAnomaly(issue)">
                      {{ confirming[issue.id] ? 'Menyimpan...' : 'Bukan Anomali' }}
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="expanded[issue.id]" class="detail-row">
                <td colspan="6">
                  <div class="detail-grid">
                    <div>
                      <h3>Produk Terkait</h3>
                      <div v-for="product in issue.products || []" :key="`${issue.id}-${product.id}`" class="mini-row">
                        <strong>{{ product.id || '-' }}</strong>
                        <span>{{ product.name || issue.product_name || '-' }}</span>
                        <small>Status {{ product.status || '-' }} | {{ product.variant_count || 0 }} varian | stok {{ product.stock_total || 0 }}</small>
                      </div>
                    </div>
                    <div>
                      <h3>Varian Terdeteksi</h3>
                      <div v-if="issue.variants?.length" class="variant-list">
                        <div v-for="variant in issue.variants" :key="`${issue.id}-${variant.id}-${variant.name}`" class="mini-row">
                          <strong>{{ variant.name || '-' }}</strong>
                          <span>ID: {{ variant.id || '-' }}</span>
                          <small>Kode: {{ variant.seller_sku || '-' }} | stok {{ variant.stock ?? 0 }} | {{ formatCurrency(variant.price || 0) }}</small>
                        </div>
                      </div>
                      <p v-else class="muted">Temuan ini ada di level produk. Lihat overlap varian di bukti.</p>
                    </div>
                  </div>
                  <div v-if="issue.action_plan" class="solution-panel">
                    <h3>{{ issue.action_plan.title || 'Solusi Disarankan' }}</h3>
                    <ol v-if="issue.action_plan.steps?.length">
                      <li v-for="(step, index) in issue.action_plan.steps" :key="`${issue.id}-step-${index}`">{{ step }}</li>
                    </ol>
                    <p v-if="issue.action_plan.note" class="solution-note">{{ issue.action_plan.note }}</p>
                    <div v-if="skuSuggestions(issue).length" class="sku-examples">
                      <h4>SKU Disarankan</h4>
                      <div v-for="suggestion in skuSuggestions(issue)" :key="`${issue.id}-${suggestion.variant_name}-${suggestion.suggested_sku}`" class="example-row">
                        <span>{{ suggestion.variant_name }}</span>
                        <small>Sekarang: {{ suggestion.current_sku || '-' }}</small>
                        <strong>{{ suggestion.suggested_sku }}</strong>
                      </div>
                    </div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!filteredIssues.length">
              <td colspan="6" class="empty">{{ loading ? 'Sedang menganalisa data...' : 'Tidak ada temuan sesuai filter.' }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const syncing = ref('')
const summary = ref({})
const issues = ref([])
const expanded = ref({})
const confirming = ref({})
const message = ref('')
const messageTone = ref('info')
const lastLoadedAt = ref('')
const filters = reactive({
  channel: 'all',
  severity: 'all',
  type: 'all',
  search: ''
})

const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '-'
const formatDateTime = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(value)) : '-'
const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0))
const channelLabel = (channel) => channel === 'shopee' ? 'Shopee' : channel === 'tiktok' ? 'TikTok' : channel
const severityLabel = (severity) => severity === 'high' ? 'Tinggi' : severity === 'warning' ? 'Perlu Cek' : 'Info'
const typeLabel = (type) => ({
  duplicate_product: 'Produk double',
  duplicate_variant: 'Varian double',
  duplicate_seller_sku: 'Seller SKU double',
  variant_anomaly: 'Anomali varian'
}[type] || type || '-')

const filteredIssues = computed(() => {
  const search = filters.search.toLowerCase()
  return issues.value.filter((issue) => {
    if (filters.severity !== 'all' && issue.severity !== filters.severity) return false
    if (filters.type !== 'all' && issue.type !== filters.type) return false
    if (!search) return true

    const haystack = [
      issue.channel,
      issue.type,
      issue.type_label,
      issue.title,
      issue.description,
      issue.action_plan?.summary,
      issue.action_plan?.note,
      ...(issue.action_plan?.steps || []),
      ...skuSuggestions(issue).flatMap((suggestion) => [suggestion.variant_name, suggestion.current_sku, suggestion.suggested_sku]),
      issue.product_name,
      ...(issue.product_ids || []),
      ...(issue.products || []).flatMap((product) => [product.id, product.name, product.status]),
      ...(issue.variants || []).flatMap((variant) => [variant.id, variant.name, variant.seller_sku])
    ].filter(Boolean).join(' ').toLowerCase()

    return haystack.includes(search)
  })
})

const productIdsLabel = (issue) => {
  const ids = issue.product_ids || []
  if (!ids.length) return 'ID produk: -'
  return `ID produk: ${ids.slice(0, 4).join(', ')}${ids.length > 4 ? ` +${ids.length - 4}` : ''}`
}

const productsSummary = (products = []) => {
  if (!products.length) return '-'
  const totalVariants = products.reduce((sum, product) => sum + Number(product.variant_count || 0), 0)
  const totalStock = products.reduce((sum, product) => sum + Number(product.stock_total || 0), 0)
  return `${products.length} produk | ${totalVariants} varian | stok ${totalStock}`
}

const evidenceLine = (issue) => {
  const evidence = issue.evidence || {}
  if (issue.type === 'duplicate_product') {
    return `${evidence.product_count || 0} produk, overlap ${evidence.overlap_variant_count || 0} varian, ${evidence.overlap_seller_sku_count || 0} kode`
  }
  if (issue.type === 'variant_anomaly') {
    return `Pola mayoritas: ${evidence.dominant_token || '-'} (${evidence.dominant_count || 0}/${evidence.variant_count || 0})`
  }
  return `Duplikat: ${evidence.duplicate_count || 0}`
}

const skuSuggestions = (issue) => issue.action_plan?.suggestions || issue.action_plan?.examples || []

const toggle = (id) => {
  expanded.value = { ...expanded.value, [id]: !expanded.value[id] }
}

const confirmationPayload = (issue) => ({
  issue_id: issue.id,
  channel: issue.channel,
  type: issue.type,
  product_name: issue.product_name,
  product_ids: issue.product_ids || [],
  note: 'Dikonfirmasi dari halaman Analisa Product & Variant.',
  issue: {
    id: issue.id,
    channel: issue.channel,
    type: issue.type,
    type_label: issue.type_label,
    severity: issue.severity,
    title: issue.title,
    description: issue.description,
    product_name: issue.product_name,
    product_ids: issue.product_ids || [],
    evidence: issue.evidence || {},
    products: issue.products || [],
    variants: issue.variants || []
  }
})

const confirmNotAnomaly = async (issue) => {
  if (!issue?.id || confirming.value[issue.id]) return

  const confirmed = window.confirm(`Tandai temuan "${issue.title}" pada "${issue.product_name || '-'}" sebagai bukan anomali? Temuan ini tidak akan muncul lagi di analisa.`)
  if (!confirmed) return

  confirming.value = { ...confirming.value, [issue.id]: true }
  try {
    await omnichannelService.confirmProductVariantAnalysisIssue(confirmationPayload(issue))
    await loadData()
    setMessage('Temuan sudah ditandai sebagai bukan anomali dan disembunyikan dari analisa.', 'success')
  } catch (error) {
    setMessage(error.response?.data?.message || error.message || 'Gagal menyimpan konfirmasi bukan anomali.', 'error')
  } finally {
    const next = { ...confirming.value }
    delete next[issue.id]
    confirming.value = next
  }
}

const setMessage = (text, tone = 'info') => {
  message.value = text
  messageTone.value = tone
}

const loadData = async ({ showSuccess = false } = {}) => {
  loading.value = true
  setMessage('')
  try {
    const { data } = await omnichannelService.productVariantAnalysis({ channel: filters.channel, _: Date.now() })
    summary.value = data.summary || {}
    issues.value = data.issues || []
    lastLoadedAt.value = new Date().toISOString()
    if (showSuccess) {
      setMessage(`Analisa diperbarui. ${data.summary?.total_issues || 0} temuan terbaca dari database terbaru.`, 'success')
    }
  } catch (error) {
    setMessage(error.response?.data?.message || error.message || 'Gagal memuat analisa product dan variant.', 'error')
  } finally {
    loading.value = false
  }
}

const refreshAnalysis = () => loadData({ showSuccess: true })

const syncShopee = async () => {
  syncing.value = 'shopee'
  try {
    await omnichannelService.shopeeItems(true)
    await loadData()
    setMessage('Data Shopee berhasil disinkronkan ulang.', 'success')
  } catch (error) {
    setMessage(error.response?.data?.message || error.message || 'Sync Shopee gagal.', 'error')
  } finally {
    syncing.value = ''
  }
}

const syncTiktok = async () => {
  syncing.value = 'tiktok'
  try {
    await omnichannelService.tiktokItems(true)
    await loadData()
    setMessage('Data TikTok berhasil disinkronkan ulang.', 'success')
  } catch (error) {
    setMessage(error.response?.data?.message || error.message || 'Sync TikTok gagal.', 'error')
  } finally {
    syncing.value = ''
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 18px; }
.page-header p { color: #64748b; margin: 0 0 4px; }
.page-header h1 { color: #0f172a; font-size: 26px; margin: 0; }
.header-actions { display: flex; gap: 8px; flex-wrap: wrap; }
button { border: 0; border-radius: 6px; padding: 9px 12px; font-weight: 700; cursor: pointer; }
button:disabled { cursor: wait; opacity: .65; }
.primary { background: #0f766e; color: #fff; }
.ghost { background: #eef2f7; color: #0f172a; }
.confirm { background: #dcfce7; color: #166534; }
.tiny { padding: 6px 9px; font-size: 12px; }
.notice { border-radius: 8px; margin: 0 0 12px; padding: 10px 12px; background: #eff6ff; color: #1d4ed8; }
.notice.error { background: #fee2e2; color: #b91c1c; }
.notice.success { background: #d1fae5; color: #047857; }
.summary-grid { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
.metric { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; }
.metric span { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
.metric strong { color: #111827; font-size: 22px; }
.filter-panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; margin-bottom: 12px; }
.filter-row { display: grid; grid-template-columns: 170px 170px 210px minmax(260px, 1fr); gap: 12px; align-items: end; }
label span { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
input, select { width: 100%; border: 1px solid #cbd5e1; border-radius: 6px; color: #0f172a; padding: 9px 10px; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; overflow: hidden; }
.panel-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 14px 16px; background: #f8fafc; border-bottom: 1px solid #e5e7eb; }
.panel-head span { color: #64748b; display: block; font-size: 12px; margin-bottom: 4px; }
.panel-head strong { color: #0f172a; font-size: 14px; }
.panel-head small { color: #64748b; display: block; font-size: 12px; margin-top: 4px; }
.table-wrap { overflow: auto; }
table { border-collapse: collapse; min-width: 1080px; width: 100%; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 12px; text-align: left; vertical-align: top; }
th { background: #f8fafc; color: #475569; font-size: 12px; text-transform: uppercase; }
td strong { color: #111827; display: block; line-height: 1.3; }
td small { color: #64748b; display: block; line-height: 1.5; margin-top: 2px; }
.solution-summary { color: #0f766e; font-weight: 700; margin-top: 6px; }
.action-stack { align-items: stretch; display: flex; flex-direction: column; gap: 6px; min-width: 112px; }
.issue-row.high { background: #fff7f7; }
.issue-row.warning { background: #fffdf5; }
.badge, .channel-pill { border-radius: 999px; display: inline-block; font-size: 12px; font-weight: 800; padding: 4px 8px; white-space: nowrap; }
.badge.high { background: #fee2e2; color: #b91c1c; }
.badge.warning { background: #fef3c7; color: #b45309; }
.badge.info { background: #e0f2fe; color: #0369a1; }
.channel-pill.shopee { background: #fff7ed; color: #c2410c; }
.channel-pill.tiktok { background: #eef2ff; color: #4338ca; }
.detail-row td { background: #f8fafc; padding: 14px; }
.detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.detail-grid h3 { color: #0f172a; font-size: 14px; margin: 0 0 8px; }
.mini-row { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 8px; padding: 10px; }
.mini-row span { color: #334155; display: block; line-height: 1.4; }
.solution-panel { background: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; margin-top: 14px; padding: 12px 14px; }
.solution-panel h3 { color: #064e3b; font-size: 14px; margin: 0 0 8px; }
.solution-panel ol { color: #0f172a; margin: 0; padding-left: 20px; }
.solution-panel li { line-height: 1.5; margin-bottom: 5px; }
.solution-note { color: #047857; margin: 10px 0 0; }
.sku-examples { border-top: 1px solid #a7f3d0; margin-top: 10px; padding-top: 10px; }
.sku-examples h4 { color: #064e3b; font-size: 12px; margin: 0 0 8px; text-transform: uppercase; }
.example-row { display: grid; grid-template-columns: minmax(120px, 1fr) minmax(120px, .8fr) minmax(160px, 1.2fr); gap: 8px; padding: 4px 0; }
.example-row span { color: #334155; }
.example-row small { color: #64748b; margin: 0; }
.example-row strong { color: #065f46; }
.muted, .empty { color: #64748b; }
.empty { padding: 28px; text-align: center; }
@media (max-width: 1120px) {
  .summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .filter-row, .detail-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 820px) {
  .page-shell { margin-left: 0; padding: 16px; }
  .page-header { align-items: flex-start; flex-direction: column; }
  .summary-grid, .filter-row, .detail-grid { grid-template-columns: 1fr; }
}
</style>
