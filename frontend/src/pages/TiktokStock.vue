<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Stok TikTok</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="resetFilters">Reset Filter</button>
        <button class="primary tiktok" @click="loadData" :disabled="loading">
          {{ loading ? 'Memuat...' : 'Ambil Produk' }}
        </button>
      </div>
    </header>

    <div class="summary-grid">
      <article class="metric">
        <span>Produk</span>
        <strong>{{ items.length }}</strong>
      </article>
      <article class="metric">
        <span>Total SKU</span>
        <strong>{{ skuCount }}</strong>
      </article>
      <article class="metric">
        <span>Total Stok</span>
        <strong>{{ grandStock }}</strong>
      </article>
      <article class="metric">
        <span>Nilai Stok</span>
        <strong>{{ formatCurrency(grandValue) }}</strong>
      </article>
    </div>

    <div class="filter-panel">
      <div class="filter-row">
        <label>
          <span>Status</span>
          <select v-model="filters.status">
            <option value="all">Semua Status</option>
            <option value="live">Live</option>
            <option value="inactive">Tidak Live</option>
          </select>
        </label>
        <label>
          <span>Stok Minimum</span>
          <input v-model.number="filters.minimumStock" type="number" min="0" placeholder="0" />
        </label>
        <label>
          <span>Sort By</span>
          <select v-model="filters.sort">
            <option value="updated_desc">Update Time</option>
            <option value="created_desc">Create Time</option>
            <option value="stock_desc">Stock</option>
            <option value="name_asc">Product Name</option>
          </select>
        </label>
      </div>

      <div class="filter-row">
        <label class="search-field">
          <span>Search</span>
          <div class="search-box">
            <select v-model="filters.searchBy">
              <option value="name">Product Name</option>
              <option value="sku">SKU</option>
              <option value="product_id">Product ID</option>
            </select>
            <input v-model.trim="filters.search" type="search" placeholder="Cari produk TikTok" />
          </div>
        </label>
      </div>
    </div>

    <div class="toolbar">
      <nav class="tabs">
        <button :class="{ active: activeTab === 'live' }" @click="setActiveTab('live')">Live ({{ liveCount }})</button>
        <button :class="{ active: activeTab === 'inactive' }" @click="setActiveTab('inactive')">Tidak Live ({{ inactiveCount }})</button>
        <button :class="{ active: activeTab === 'all' }" @click="setActiveTab('all')">Semua ({{ items.length }})</button>
      </nav>
      <span class="result-count">{{ filteredItems.length }} produk tampil</span>
    </div>

    <p v-if="syncMessage" :class="['sync-message', syncTone]">{{ syncMessage }}</p>

    <div class="panel">
      <div class="panel-head">
        <div>
          <span>TikTok Shop</span>
          <strong>AgniShopBJM</strong>
        </div>
        <small>{{ lastSyncLabel }}</small>
      </div>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th class="check-col"><input type="checkbox" /></th>
              <th>Item & Store</th>
              <th>Parent SKU</th>
              <th>SKU</th>
              <th>Price</th>
              <th>Stock</th>
              <th>Quality</th>
              <th>Time</th>
              <th>Operation</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="item in pagedItems" :key="item.product_id">
              <tr class="product-row">
                <td class="check-col"><input type="checkbox" /></td>
                <td>
                  <div class="product-cell">
                    <div class="thumb-fallback">{{ initials(item.product_name) }}</div>
                    <div>
                      <strong>{{ item.product_name }}</strong>
                      <small>Product ID: {{ item.product_id }}</small>
                      <small>SKU: {{ item.skus?.length || 0 }} varian</small>
                      <span class="store-pill">TikTok Shop AgniShopBJM</span>
                    </div>
                  </div>
                </td>
                <td>--</td>
                <td>
                  <strong>{{ item.skus?.[0]?.sku_name || '--' }}</strong>
                  <small>{{ skuSummary(item) }}</small>
                </td>
                <td>{{ priceRange(item.skus) }}</td>
                <td>
                  <strong>{{ totalStock(item.skus) }}</strong>
                  <small>{{ formatCurrency(totalValue(item.skus)) }}</small>
                </td>
                <td>
                  <span :class="['status-badge', rowStatus(item).tone]">{{ rowStatus(item).label }}</span>
                  <small>{{ qualityNote(item) }}</small>
                </td>
                <td>
                  <small>Update Time</small>
                  <strong>{{ formatDate(item.updated_at) }}</strong>
                </td>
                <td>
                  <div class="actions">
                    <button title="Lihat varian" @click="toggle(item.product_id)">{{ expanded[item.product_id] ? 'Hide' : 'Show' }}</button>
                    <button title="Refresh data" @click="loadData">Sync</button>
                  </div>
                </td>
              </tr>
              <tr v-if="expanded[item.product_id]" class="variant-row">
                <td></td>
                <td colspan="8">
                  <div class="variant-list">
                    <div v-for="sku in item.skus" :key="`${item.product_id}-${sku.sku_name}`" class="variant-item">
                      <span>{{ sku.sku_name || '-' }}</span>
                      <span>SKU ID: {{ sku.tiktok_sku || '-' }}</span>
                      <strong>{{ formatCurrency(sku.price || 0) }}</strong>
                      <strong>Stock {{ sku.stock_qty || 0 }}</strong>
                    </div>
                    <div v-if="!item.skus?.length" class="variant-empty">Tidak ada varian tersimpan.</div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!filteredItems.length">
              <td colspan="9" class="empty">{{ loading ? 'Sedang memuat produk...' : 'Belum ada produk yang cocok dengan filter.' }}</td>
            </tr>
          </tbody>
          <tfoot v-if="filteredItems.length">
            <tr>
              <td colspan="5" class="right">Total semua produk</td>
              <td class="center">{{ grandStock }}</td>
              <td></td>
              <td class="right">{{ formatCurrency(grandValue) }}</td>
              <td></td>
            </tr>
          </tfoot>
        </table>
      </div>
      <div v-if="filteredItems.length" class="pagination">
        <button type="button" :disabled="currentPage === 1" @click="setPage(currentPage - 1)">Prev</button>
        <span>Halaman {{ currentPage }} dari {{ totalPages }}</span>
        <button type="button" :disabled="currentPage === totalPages" @click="setPage(currentPage + 1)">Next</button>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const items = ref([])
const expanded = ref({})
const loading = ref(false)
const activeTab = ref('all')
const page = ref(1)
const PAGE_SIZE = 20
const syncMessage = ref('')
const syncTone = ref('info')
const lastSyncAt = ref('')
const filters = reactive({
  status: 'all',
  minimumStock: null,
  searchBy: 'name',
  search: '',
  sort: 'updated_desc'
})

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0)
const totalStock = (skus) => (skus || []).reduce((sum, item) => sum + Number(item.stock_qty || 0), 0)
const totalValue = (skus) => (skus || []).reduce((sum, item) => sum + Number(item.subtotal || 0), 0)
const skuCount = computed(() => items.value.reduce((sum, item) => sum + (item.skus?.length || 0), 0))
const grandStock = computed(() => filteredItems.value.reduce((sum, item) => sum + totalStock(item.skus), 0))
const grandValue = computed(() => filteredItems.value.reduce((sum, item) => sum + totalValue(item.skus), 0))
const liveCount = computed(() => items.value.filter((item) => totalStock(item.skus) > 0).length)
const inactiveCount = computed(() => items.value.filter((item) => totalStock(item.skus) <= 0).length)

const filteredItems = computed(() => {
  const query = filters.search.toLowerCase()

  return items.value
    .filter((item) => {
      const stock = totalStock(item.skus)
      if (activeTab.value === 'live' && stock <= 0) return false
      if (activeTab.value === 'inactive' && stock > 0) return false
      if (filters.status === 'live' && stock <= 0) return false
      if (filters.status === 'inactive' && stock > 0) return false
      if (Number(filters.minimumStock || 0) > 0 && stock < Number(filters.minimumStock)) return false
      if (!query) return true

      const haystack = {
        name: item.product_name,
        sku: item.skus?.[0]?.sku_name,
        product_id: item.product_id
      }[filters.searchBy] || item.product_name

      return String(haystack || '').toLowerCase().includes(query)
    })
    .sort((a, b) => {
      if (filters.sort === 'stock_desc') return totalStock(b.skus) - totalStock(a.skus)
      if (filters.sort === 'name_asc') return String(a.product_name || '').localeCompare(String(b.product_name || ''))
      if (filters.sort === 'created_desc') return new Date(b.created_at || 0) - new Date(a.created_at || 0)
      return new Date(b.updated_at || 0) - new Date(a.updated_at || 0)
    })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / PAGE_SIZE)))
const currentPage = computed(() => Math.min(page.value, totalPages.value))
const pagedItems = computed(() => filteredItems.value.slice((currentPage.value - 1) * PAGE_SIZE, currentPage.value * PAGE_SIZE))
const lastSyncLabel = computed(() => lastSyncAt.value ? `Terakhir sinkron: ${formatDate(lastSyncAt.value)}` : 'Belum pernah sinkron.')

const skuSummary = (item) => `${item.skus?.length || 0} varian`
const initials = (name) => String(name || 'TT').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const qualityNote = (item) => totalStock(item.skus) > 0 ? 'Produk sedang dijual' : 'Stok perlu dicek'
const rowStatus = (item) => totalStock(item.skus) > 0 ? { label: 'Live', tone: 'success' } : { label: 'Sold Out', tone: 'warning' }
const priceRange = (skus) => {
  const prices = (skus || []).map((sku) => Number(sku.price || 0)).filter(Boolean)
  if (!prices.length) return '-'
  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? formatCurrency(min) : `${formatCurrency(min)} - ${formatCurrency(max)}`
}
const formatDate = (value) => {
  if (!value) return '-'
  return new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value))
}

const resetFilters = () => {
  filters.status = 'all'
  filters.minimumStock = null
  filters.searchBy = 'name'
  filters.search = ''
  filters.sort = 'updated_desc'
  activeTab.value = 'all'
  page.value = 1
}

const setActiveTab = (tab) => {
  activeTab.value = tab
  page.value = 1
}

const toggle = (id) => {
  expanded.value[id] = !expanded.value[id]
}

const setPage = (nextPage) => {
  page.value = Math.min(Math.max(Number(nextPage) || 1, 1), totalPages.value)
}

const loadData = async () => {
  loading.value = true
  syncMessage.value = ''
  try {
    const response = await omnichannelService.tiktokItems()
    items.value = response.data.items || []
    lastSyncAt.value = response.data.last_sync_at || response.data.sync?.last_sync_at || ''
    syncMessage.value = response.data.sync?.message || response.data.message || ''
    syncTone.value = response.data.sync?.status === 'empty' ? 'warning' : 'info'
    page.value = 1
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Data TikTok gagal dimuat.'
    syncTone.value = 'error'
  } finally {
    loading.value = false
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 16px; }
.page-header p { color: #64748b; margin-bottom: 4px; font-size: 13px; }
.page-header h1 { font-size: 26px; letter-spacing: 0; }
.header-actions { display: flex; gap: 10px; }
button, input, select { font-size: 13px; }
button { border: 0; border-radius: 6px; padding: 9px 12px; cursor: pointer; }
.primary { color: #fff; }
.primary:disabled { opacity: .65; cursor: wait; }
.tiktok { background: #111827; }
.ghost { color: #334155; background: #fff; border: 1px solid #d7dde8; }
.summary-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
.metric { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; }
.metric span { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
.metric strong { color: #111827; font-size: 22px; }
.filter-panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; margin-bottom: 12px; }
.filter-row { display: grid; grid-template-columns: repeat(4, minmax(150px, 1fr)); gap: 10px; align-items: end; }
.filter-row + .filter-row { margin-top: 10px; grid-template-columns: 2fr 1fr; }
label span { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
select, input { width: 100%; height: 36px; border: 1px solid #d7dde8; border-radius: 4px; background: #fff; color: #111827; padding: 0 10px; }
.search-box { display: grid; grid-template-columns: 160px 1fr; }
.search-box select { border-radius: 4px 0 0 4px; }
.search-box input { border-left: 0; border-radius: 0 4px 4px 0; }
.toolbar { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin: 14px 0 8px; }
.tabs { display: flex; gap: 18px; overflow-x: auto; }
.tabs button { background: transparent; border-radius: 0; color: #64748b; padding: 10px 0; border-bottom: 2px solid transparent; white-space: nowrap; }
.tabs button.active { color: #0f5fc7; border-bottom-color: #0f5fc7; }
.result-count { color: #64748b; font-size: 13px; white-space: nowrap; }
.sync-message { border: 1px solid #d9e2ec; border-radius: 6px; font-size: 13px; margin: 0 0 10px; padding: 10px 12px; }
.sync-message.info { color: #334155; background: #f8fafc; }
.sync-message.success { color: #166534; background: #ecfdf5; border-color: #86efac; }
.sync-message.warning { color: #9a3412; background: #fff7ed; border-color: #fed7aa; }
.sync-message.error { color: #991b1b; background: #fef2f2; border-color: #fecaca; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; overflow: hidden; }
.panel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-bottom: 1px solid #e5e7eb; background: #f8fafc; }
.panel-head span, .panel-head small { color: #64748b; font-size: 12px; }
.panel-head strong { color: #111827; display: block; margin-top: 3px; }
.table-wrap { max-height: 72vh; overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1180px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #1f2937; color: #fff; }
.check-col { width: 34px; text-align: center; }
.product-row:hover { background: #fbfdff; }
.product-cell { display: grid; grid-template-columns: 72px 1fr; gap: 10px; min-width: 380px; }
.thumb-fallback { width: 72px; height: 72px; border-radius: 6px; display: grid; place-items: center; background: #eef2f7; color: #64748b; font-weight: 800; }
strong { display: block; color: #0f172a; line-height: 1.35; }
small { display: block; color: #64748b; line-height: 1.55; }
.store-pill { display: inline-block; margin-top: 6px; padding: 4px 8px; color: #64748b; background: #f6f7fb; border-radius: 4px; font-size: 12px; }
.status-badge { display: inline-block; border-radius: 999px; padding: 4px 8px; margin-bottom: 4px; font-size: 12px; font-weight: 700; }
.success { color: #047857; background: #d1fae5; }
.warning { color: #b45309; background: #fef3c7; }
.muted { color: #64748b; background: #eef2f7; }
.actions { display: grid; gap: 6px; }
.actions button { color: #0f5fc7; background: #eaf1ff; padding: 7px 9px; }
.variant-row td { background: #fafafa; padding-top: 0; }
.variant-list { border-top: 1px dashed #d7dde8; padding-top: 8px; display: grid; gap: 6px; }
.variant-item { display: grid; grid-template-columns: 1.3fr 1fr .7fr .5fr; gap: 10px; padding: 8px; background: #fff; border: 1px solid #edf0f5; border-radius: 6px; }
.variant-empty, .empty { color: #64748b; text-align: center; padding: 24px; }
.pagination { display: flex; align-items: center; justify-content: flex-end; gap: 10px; padding: 12px 14px; border-top: 1px solid #e5e7eb; background: #fff; }
.pagination button { color: #334155; background: #fff; border: 1px solid #cbd5e1; font-weight: 700; }
.pagination button:disabled { cursor: not-allowed; opacity: .45; }
.pagination span { color: #64748b; font-size: 13px; }
@media (max-width: 1100px) {
  .summary-grid, .filter-row, .filter-row + .filter-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 820px) {
  .page-shell { margin-left: 0; padding: 16px; }
  .page-header, .toolbar { align-items: stretch; flex-direction: column; }
  .header-actions { width: 100%; }
  .header-actions button { flex: 1; }
  .summary-grid, .filter-row, .filter-row + .filter-row, .search-box { grid-template-columns: 1fr; }
}
</style>
