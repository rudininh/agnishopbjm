<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Stok Shopee</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="resetFilters">Reset Filter</button>
        <button class="primary shopee" @click="syncAndLoad" :disabled="loading">
          {{ loading ? 'Memuat...' : 'Ambil Produk' }}
        </button>
      </div>
    </header>

    <div class="summary-grid">
      <article class="metric">
        <span>Produk Live</span>
        <strong>{{ liveCount }}</strong>
      </article>
      <article class="metric">
        <span>Total Varian</span>
        <strong>{{ variantCount }}</strong>
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
          <span>Stores</span>
          <select v-model="filters.store">
            <option value="all">All</option>
            <option v-for="store in storeOptions" :key="store" :value="store">{{ store }}</option>
          </select>
        </label>
        <label>
          <span>Status</span>
          <select v-model="filters.status">
            <option value="all">Semua Status</option>
            <option value="live">Live</option>
            <option value="soldout">Sold Out</option>
            <option value="inactive">Tidak Live</option>
          </select>
        </label>
        <label>
          <span>Stok Minimum</span>
          <input v-model.number="filters.minimumStock" type="number" min="0" placeholder="0" />
        </label>
        <label>
          <span>Harga</span>
          <select v-model="filters.price">
            <option value="all">Semua Harga</option>
            <option value="promo">Ada Promo</option>
            <option value="high">Di atas Rp50.000</option>
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
              <option value="item_id">Item ID</option>
            </select>
            <input v-model.trim="filters.search" type="search" placeholder="Cari produk Shopee" />
          </div>
        </label>
        <label>
          <span>Sort By</span>
          <select v-model="filters.sort">
            <option value="updated_desc">Update Time</option>
            <option value="created_desc">Create Time</option>
            <option value="stock_desc">Stock</option>
            <option value="sales_desc">Sales</option>
            <option value="name_asc">Product Name</option>
          </select>
        </label>
      </div>
    </div>

    <div class="toolbar">
      <nav class="tabs">
        <button :class="{ active: activeTab === 'live' }" @click="setActiveTab('live')">Live ({{ liveCount }})</button>
        <button :class="{ active: activeTab === 'soldout' }" @click="setActiveTab('soldout')">Sold Out ({{ soldOutCount }})</button>
        <button :class="{ active: activeTab === 'inactive' }" @click="setActiveTab('inactive')">Tidak Live ({{ inactiveCount }})</button>
        <button :class="{ active: activeTab === 'all' }" @click="setActiveTab('all')">Semua ({{ items.length }})</button>
      </nav>
      <span class="result-count">{{ filteredItems.length }} produk tampil</span>
    </div>

    <p v-if="syncMessage" :class="['sync-message', syncTone]">{{ syncMessage }}</p>

    <div class="panel">
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
            <template v-for="item in pagedItems" :key="item.item_id">
              <tr class="product-row">
                <td class="check-col"><input type="checkbox" /></td>
                <td>
                  <div class="product-cell">
                    <img v-if="item.image_url && !brokenImages[item.item_id]" :src="item.image_url" :alt="item.nama" @error="markImageBroken(item.item_id)" />
                    <div v-else class="thumb-fallback">{{ initials(item.nama) }}</div>
                    <div>
                      <strong>{{ item.nama }}</strong>
                      <small>Item ID: {{ item.item_id }}</small>
                      <small>Sales: {{ item.sales || 0 }} | Likes: {{ item.likes || 0 }}</small>
                      <span class="store-pill">{{ item.shop_name || 'Agni Shop Banjarmasin' }}</span>
                    </div>
                  </div>
                </td>
                <td>--</td>
                <td>
                  <strong>{{ item.sku || '--' }}</strong>
                  <small>{{ modelSummary(item) }}</small>
                </td>
                <td>
                  <div class="price-cell">
                    <small v-if="hasDiscount(item)">Harga asli: {{ originalPriceRange(item) }}</small>
                    <strong>{{ hasDiscount(item) ? 'Harga diskon' : 'Harga' }}: {{ discountedPriceRange(item) }}</strong>
                    <span v-if="hasDiscount(item)" class="discount-pill">{{ discountLabel(item) }}</span>
                  </div>
                </td>
                <td>
                  <strong>{{ totalStock(item.models) }}</strong>
                  <small>{{ formatCurrency(totalValue(item.models)) }}</small>
                </td>
                <td>
                  <span :class="['status-badge', rowStatus(item).tone]">{{ rowStatus(item).label }}</span>
                  <small>{{ qualityNote(item) }}</small>
                </td>
                <td>
                  <small>Create Time</small>
                  <strong>{{ formatDate(item.created_at) }}</strong>
                  <small>Update Time</small>
                  <strong>{{ formatDate(item.updated_at) }}</strong>
                </td>
                <td>
                  <div class="actions">
                    <button title="Lihat varian" @click="toggle(item.item_id)">{{ expanded[item.item_id] ? 'Hide' : 'Show' }}</button>
                    <button title="Refresh produk" @click="loadData">Sync</button>
                  </div>
                </td>
              </tr>
              <tr v-if="expanded[item.item_id]" class="variant-row">
                <td></td>
                <td colspan="8">
                  <div class="variant-list">
                    <div v-for="model in item.models" :key="model.model_id" class="variant-item">
                      <span class="variant-name">
                        <img v-if="model.image_url" :src="model.image_url" :alt="model.name || 'Varian Shopee'" />
                        <span v-else class="variant-thumb-fallback">{{ initials(model.name) }}</span>
                        <span>{{ model.name || 'Tanpa Varian' }}</span>
                      </span>
                      <span>SKU ID: {{ model.model_id || '-' }}</span>
                      <span class="variant-price">
                        <small v-if="modelHasDiscount(model)" class="original-price">Harga asli: {{ formatCurrency(modelOriginalPrice(model)) }}</small>
                        <small v-if="modelHasDiscount(model)" class="discount-label">Harga Diskon <span title="Diskon dari harga asli">?</span>:</small>
                        <strong>{{ modelDiscountDetail(model) }}</strong>
                      </span>
                      <strong>Stock {{ model.stock || 0 }}</strong>
                    </div>
                    <div v-if="!item.models?.length" class="variant-empty">Tidak ada varian tersimpan.</div>
                  </div>
                </td>
              </tr>
            </template>
            <tr v-if="!filteredItems.length">
              <td colspan="9" class="empty">{{ loading ? 'Sedang memuat produk...' : 'Belum ada produk yang cocok dengan filter.' }}</td>
            </tr>
          </tbody>
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
const activeTab = ref('live')
const page = ref(1)
const PAGE_SIZE = 20
const brokenImages = ref({})
const syncMessage = ref('')
const syncTone = ref('info')
const filters = reactive({
  store: 'all',
  status: 'all',
  minimumStock: null,
  price: 'all',
  searchBy: 'name',
  search: '',
  sort: 'updated_desc'
})

const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(value || 0)
const totalStock = (models) => (models || []).reduce((sum, item) => sum + Number(item.stock || 0), 0)
const totalValue = (models) => (models || []).reduce((sum, item) => sum + Number(item.stock || 0) * Number(item.price || 0), 0)
const numberValue = (value) => Number(value || 0)
const isSoldOut = (item) => totalStock(item.models) <= 0
const isLive = (item) => Boolean(item.is_live)
const liveCount = computed(() => items.value.filter((item) => isLive(item) && !isSoldOut(item)).length)
const soldOutCount = computed(() => items.value.filter((item) => isSoldOut(item)).length)
const inactiveCount = computed(() => items.value.filter((item) => !isLive(item)).length)
const variantCount = computed(() => items.value.reduce((sum, item) => sum + (item.models?.length || 0), 0))
const grandStock = computed(() => visibleItems.value.reduce((sum, item) => sum + totalStock(item.models), 0))
const grandValue = computed(() => visibleItems.value.reduce((sum, item) => sum + totalValue(item.models), 0))
const storeOptions = computed(() => [...new Set(items.value.map((item) => item.shop_name || 'Agni Shop Banjarmasin'))].sort())

const filteredItems = computed(() => {
  const query = filters.search.toLowerCase()

  return items.value
    .filter((item) => {
      if (activeTab.value === 'live' && (!isLive(item) || isSoldOut(item))) return false
      if (activeTab.value === 'soldout' && !isSoldOut(item)) return false
      if (activeTab.value === 'inactive' && isLive(item)) return false
      if (filters.status === 'live' && (!isLive(item) || isSoldOut(item))) return false
      if (filters.status === 'soldout' && !isSoldOut(item)) return false
      if (filters.status === 'inactive' && isLive(item)) return false
      if (filters.store !== 'all' && (item.shop_name || 'Agni Shop Banjarmasin') !== filters.store) return false
      if (Number(filters.minimumStock || 0) > 0 && totalStock(item.models) < Number(filters.minimumStock)) return false
      if (filters.price === 'high' && Number(item.price_max || item.price_min || 0) <= 50000) return false
      if (filters.price === 'promo' && Number(item.price_min || 0) >= Number(item.price_max || item.price_min || 0)) return false
      if (!query) return true

      const haystack = {
        name: item.nama,
        sku: item.sku,
        item_id: item.item_id
      }[filters.searchBy] || item.nama

      return String(haystack || '').toLowerCase().includes(query)
    })
    .sort((a, b) => {
      if (filters.sort === 'stock_desc') return totalStock(b.models) - totalStock(a.models)
      if (filters.sort === 'sales_desc') return Number(b.sales || 0) - Number(a.sales || 0)
      if (filters.sort === 'name_asc') return String(a.nama || '').localeCompare(String(b.nama || ''))
      if (filters.sort === 'created_desc') return new Date(b.created_at || 0) - new Date(a.created_at || 0)
      return new Date(b.updated_at || 0) - new Date(a.updated_at || 0)
    })
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredItems.value.length / PAGE_SIZE)))
const currentPage = computed(() => Math.min(page.value, totalPages.value))
const visibleItems = filteredItems
const pagedItems = computed(() => {
  const start = (currentPage.value - 1) * PAGE_SIZE

  return filteredItems.value.slice(start, start + PAGE_SIZE)
})

const setPage = (nextPage) => {
  page.value = Math.min(Math.max(Number(nextPage) || 1, 1), totalPages.value)
}

const setActiveTab = (tab) => {
  activeTab.value = tab
  page.value = 1
}

const toggle = (id) => {
  expanded.value[id] = !expanded.value[id]
}

const markImageBroken = (id) => {
  brokenImages.value[id] = true
}

const initials = (name) => String(name || 'SP').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const modelSummary = (item) => `${item.models?.length || 0} varian`
const qualityNote = (item) => isSoldOut(item) ? 'Stok perlu dicek' : 'Produk sedang dijual'
const rowStatus = (item) => {
  if (isSoldOut(item)) return { label: 'Sold Out', tone: 'warning' }
  if (!isLive(item)) return { label: item.status || 'Tidak Live', tone: 'muted' }
  return { label: 'Live', tone: 'success' }
}
const priceRange = (item) => {
  const prices = (item.models || []).map((model) => numberValue(model.price)).filter(Boolean)
  const min = Math.min(...prices, numberValue(item.price_min) || Infinity)
  const max = Math.max(...prices, numberValue(item.price_max) || 0)

  if (!Number.isFinite(min) || !max) return '-'
  return min === max ? formatCurrency(min) : `${formatCurrency(min)} - ${formatCurrency(max)}`
}
const modelOriginalPrice = (model) => Math.max(numberValue(model.original_price), numberValue(model.price))
const modelDiscountPrice = (model) => numberValue(model.price)
const modelHasDiscount = (model) => modelOriginalPrice(model) > numberValue(model.price)
const modelDiscountPercent = (model) => {
  const original = modelOriginalPrice(model)
  const current = numberValue(model.price)

  if (!original || current >= original) return 0
  return Math.round(((original - current) / original) * 100)
}
const modelDiscountDetail = (model) => {
  const current = formatCurrency(modelDiscountPrice(model))
  const percent = modelDiscountPercent(model)

  return percent ? `${current} (${percent}% DISKON)` : current
}
const modelPrices = (item, key) => (item.models || [])
  .map((model) => key === 'original' ? modelOriginalPrice(model) : numberValue(model.price))
  .filter(Boolean)
const rangeText = (values, fallback = 0) => {
  const prices = values.filter(Boolean)
  if (fallback) prices.push(numberValue(fallback))
  if (!prices.length) return '-'

  const min = Math.min(...prices)
  const max = Math.max(...prices)
  return min === max ? formatCurrency(min) : `${formatCurrency(min)} - ${formatCurrency(max)}`
}
const discountedPriceRange = (item) => priceRange(item)
const originalPriceRange = (item) => rangeText(modelPrices(item, 'original'), numberValue(item.price_before_discount) || numberValue(item.price_max))
const hasDiscount = (item) => {
  const currentPrices = modelPrices(item, 'current')
  const originalPrices = modelPrices(item, 'original')
  const currentMin = currentPrices.length ? Math.min(...currentPrices) : numberValue(item.price_min)
  const originalMax = originalPrices.length ? Math.max(...originalPrices) : Math.max(numberValue(item.price_before_discount), numberValue(item.price_max))

  return originalMax > currentMin
}
const discountLabel = (item) => {
  const percents = (item.models || []).map(modelDiscountPercent).filter(Boolean)
  if (!percents.length && hasDiscount(item)) {
    const current = numberValue(item.price_min)
    const original = Math.max(numberValue(item.price_before_discount), numberValue(item.price_max))
    const percent = original && current < original ? Math.round(((original - current) / original) * 100) : 0
    return percent ? `-${percent}%` : 'Promo'
  }

  const min = Math.min(...percents)
  const max = Math.max(...percents)
  return min === max ? `-${min}%` : `-${min}% s/d -${max}%`
}
const formatDate = (value) => {
  if (!value) return '-'
  return new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: 'short',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(new Date(value))
}

const resetFilters = () => {
  filters.store = 'all'
  filters.status = 'all'
  filters.minimumStock = null
  filters.price = 'all'
  filters.searchBy = 'name'
  filters.search = ''
  filters.sort = 'updated_desc'
  activeTab.value = 'live'
  page.value = 1
}

const loadData = async (syncMode = false) => {
  loading.value = true
  syncMessage.value = ''
  try {
    const response = await omnichannelService.shopeeItems(syncMode)
    items.value = response.data.items || []
    const syncResult = response.data.sync

    if (syncResult?.message) {
      syncMessage.value = syncResult.message
      syncTone.value = syncResult.status === 'error' ? 'error' : syncResult.status === 'partial' ? 'warning' : syncResult.status === 'cached' ? 'info' : 'success'
    }

    page.value = 1
  } catch (error) {
    syncMessage.value = error.response?.data?.message || 'Pengambilan produk Shopee gagal.'
    syncTone.value = 'error'
  } finally {
    loading.value = false
  }
}

const syncAndLoad = async () => {
  await loadData(true)
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
.shopee { background: #ee4d2d; }
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
.tabs button.active { color: #4f2ec7; border-bottom-color: #4f2ec7; }
.result-count { color: #64748b; font-size: 13px; white-space: nowrap; }
.sync-message { border: 1px solid #d9e2ec; border-radius: 6px; font-size: 13px; margin: 0 0 10px; padding: 10px 12px; }
.sync-message.info { color: #334155; background: #f8fafc; }
.sync-message.success { color: #166534; background: #ecfdf5; border-color: #86efac; }
.sync-message.warning { color: #9a3412; background: #fff7ed; border-color: #fed7aa; }
.sync-message.error { color: #991b1b; background: #fef2f2; border-color: #fecaca; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; overflow: hidden; }
.table-wrap { max-height: 68vh; overflow: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; min-width: 1180px; }
th, td { border-bottom: 1px solid #e5e7eb; padding: 10px; text-align: left; vertical-align: top; }
thead th { position: sticky; top: 0; background: #f8fafc; color: #0f172a; z-index: 2; }
.check-col { width: 34px; text-align: center; }
.product-row:hover { background: #fbfdff; }
.product-cell { display: grid; grid-template-columns: 72px 1fr; gap: 10px; min-width: 380px; }
.product-cell img, .thumb-fallback { width: 72px; height: 72px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.thumb-fallback { display: grid; place-items: center; color: #64748b; font-weight: 800; }
strong { display: block; color: #0f172a; line-height: 1.35; }
small { display: block; color: #64748b; line-height: 1.55; }
.store-pill { display: inline-block; margin-top: 6px; padding: 4px 8px; color: #64748b; background: #f6f7fb; border-radius: 4px; font-size: 12px; }
.price-cell, .variant-price { display: grid; gap: 3px; align-content: start; }
.price-cell small, .variant-price .original-price { text-decoration: line-through; color: #94a3b8; }
.variant-price strong { font-size: 13px; }
.discount-label { color: #64748b; font-size: 11px; line-height: 1.25; text-decoration: none; }
.discount-label span { display: inline-grid; place-items: center; width: 13px; height: 13px; border: 1px solid #cbd5e1; border-radius: 999px; color: #64748b; font-size: 9px; font-weight: 700; }
.discount-pill { display: inline-flex; width: max-content; align-items: center; border-radius: 999px; padding: 2px 7px; color: #b42318; background: #fee4e2; font-size: 11px; font-weight: 800; line-height: 1.4; }
.status-badge { display: inline-block; border-radius: 999px; padding: 4px 8px; margin-bottom: 4px; font-size: 12px; font-weight: 700; }
.success { color: #047857; background: #d1fae5; }
.warning { color: #b45309; background: #fef3c7; }
.muted { color: #64748b; background: #eef2f7; }
.actions { display: grid; gap: 6px; }
.actions button { color: #4f2ec7; background: #f1efff; padding: 7px 9px; }
.variant-row td { background: #fafafa; padding-top: 0; }
.variant-list { border-top: 1px dashed #d7dde8; padding-top: 8px; display: grid; gap: 6px; }
.variant-item { display: grid; grid-template-columns: 1.3fr 1fr .7fr .5fr; gap: 10px; padding: 8px; background: #fff; border: 1px solid #edf0f5; border-radius: 6px; }
.variant-name { display: grid; grid-template-columns: 42px minmax(0, 1fr); gap: 10px; align-items: center; }
.variant-name img, .variant-thumb-fallback { width: 42px; height: 42px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.variant-thumb-fallback { display: grid; place-items: center; color: #64748b; font-size: 11px; font-weight: 800; }
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
