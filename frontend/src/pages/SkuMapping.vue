<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Omnichannel</p>
        <h1>SKU Mapping</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="loadData" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" @click="save" :disabled="!selectedItem || saving">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
      </div>
    </header>

    <p v-if="loadError" class="notice error">{{ loadError }}</p>
    <p v-else-if="loading && !items.length" class="notice">Memuat data SKU mapping...</p>

    <div class="summary-grid" v-if="summary">
      <article class="metric"><span>Stock Master</span><strong>{{ summary.total || 0 }}</strong></article>
      <article class="metric"><span>Mapped</span><strong>{{ summary.mapped || 0 }}</strong></article>
      <article class="metric"><span>Last Shopee Sync</span><strong>{{ formatDate(summary.last_shopee_sync_at) }}</strong></article>
      <article class="metric"><span>Last TikTok Sync</span><strong>{{ formatDate(summary.last_tiktok_sync_at) }}</strong></article>
    </div>

    <div class="layout">
      <div class="panel list-panel">
        <div class="filter-row">
          <input v-model.trim="filters.search" type="search" placeholder="Cari produk / varian / SKU" @keyup.enter="loadData" />
          <select v-model="filters.status" @change="loadData">
            <option value="all">Semua</option>
            <option value="mapped">Mapped</option>
            <option value="unmapped">Unmapped</option>
          </select>
          <select v-model="filters.sort" @change="loadData">
            <option value="updated_desc">Update Time</option>
            <option value="created_desc">Create Time</option>
            <option value="name_asc">Nama Produk</option>
          </select>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Etalase / Produk</th>
                <th>Shopee</th>
                <th>TikTok</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody v-for="group in productGroups" :key="group.key">
              <tr class="product-row">
                <td>
                  <button class="expand" @click="toggleGroup(group.key)">{{ isExpanded(group.key) ? '-' : '+' }}</button>
                  <img v-if="group.image_url" :src="group.image_url" class="thumb small" :alt="group.name" />
                  <div v-else class="thumb small fallback">{{ initials(group.name) }}</div>
                  <div>
                    <strong>{{ group.name }}</strong>
                    <small>{{ group.variants.length }} varian, stok {{ group.total_stock }}</small>
                  </div>
                </td>
                <td>
                  <strong>{{ group.shopee.mapped }} / {{ group.variants.length }}</strong>
                  <small>{{ group.shopee.product_name || 'Belum terhubung' }}</small>
                </td>
                <td>
                  <strong>{{ group.tiktok.mapped }} / {{ group.variants.length }}</strong>
                  <small>{{ group.tiktok.product_name || 'Belum terhubung' }}</small>
                </td>
                <td>
                  <span :class="['badge', group.status]">{{ labelStatus(group.status) }}</span>
                </td>
              </tr>

              <tr
                v-for="item in group.variants"
                v-show="isExpanded(group.key)"
                :key="item.id"
                :class="['variant-row', { active: selectedItem?.id === item.id }]"
                @click="selectItem(item)"
              >
                <td>
                  <div class="variant-title">
                    <strong>{{ item.variant_name || 'Tanpa Varian' }}</strong>
                    <small>{{ item.internal_sku }}</small>
                    <small>Stock Master: {{ item.stock_qty }}</small>
                  </div>
                </td>
                <td>
                  <div class="channel-cell">
                    <img v-if="item.shopee?.image_url" :src="item.shopee.image_url" class="thumb" :alt="item.shopee.variant_name" />
                    <div v-else class="thumb fallback">SP</div>
                    <div>
                      <strong>{{ item.shopee?.variant_name || item.shopee?.product_name || '-' }}</strong>
                      <small>Item: {{ item.shopee?.item_id || '-' }}</small>
                      <small>SKU/Model: {{ item.shopee?.model_id || '-' }}</small>
                      <small>Stok: {{ item.shopee?.stock_qty ?? '-' }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="channel-cell">
                    <img v-if="item.tiktok?.image_url" :src="item.tiktok.image_url" class="thumb" :alt="item.tiktok.variant_name" />
                    <div v-else class="thumb fallback">TT</div>
                    <div>
                      <strong>{{ item.tiktok?.variant_name || item.tiktok?.sku_name || '-' }}</strong>
                      <small>Product: {{ item.tiktok?.product_id || '-' }}</small>
                      <small>SKU: {{ item.tiktok?.sku_id || item.tiktok?.sku_name || '-' }}</small>
                      <small>Stok: {{ item.tiktok?.stock_qty ?? '-' }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <span :class="['badge', item.status]">{{ labelStatus(item.status) }}</span>
                  <button class="mini" @click.stop="selectItem(item)">Edit</button>
                </td>
              </tr>
            </tbody>
            <tbody v-if="!productGroups.length && !loading">
              <tr>
                <td colspan="4" class="empty">{{ loadError ? 'Data belum bisa dimuat.' : 'Belum ada data mapping.' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <aside class="panel detail-panel" v-if="selectedItem">
        <div class="detail-head">
          <div>
            <span>Selected Variant</span>
            <strong>{{ selectedItem.variant_name || 'Tanpa Varian' }}</strong>
            <small>{{ selectedItem.product_name }}</small>
          </div>
          <img v-if="selectedItem.image_url" :src="selectedItem.image_url" class="thumb large" :alt="selectedItem.product_name" />
          <div v-else class="thumb large fallback">{{ initials(selectedItem.product_name) }}</div>
        </div>

        <label>
          <span>Shopee Item ID</span>
          <input v-model="form.shopee_item_id" />
        </label>
        <label>
          <span>Shopee SKU / Model ID</span>
          <input v-model="form.shopee_model_id" />
        </label>
        <label>
          <span>TikTok Product ID</span>
          <input v-model="form.tiktok_product_id" />
        </label>
        <label>
          <span>TikTok SKU ID</span>
          <input v-model="form.tiktok_sku_id" />
        </label>
        <label>
          <span>TikTok SKU Name</span>
          <input v-model="form.tiktok_sku_name" />
        </label>
        <label>
          <span>Notes</span>
          <textarea v-model="form.notes" rows="4"></textarea>
        </label>

        <div class="actions">
          <button class="ghost" @click="fillFromSelected">Reset</button>
          <button class="primary" @click="save" :disabled="saving">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
        </div>
      </aside>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const summary = ref(null)
const items = ref([])
const selectedItem = ref(null)
const expandedGroups = ref({})
const filters = reactive({ search: '', status: 'all', sort: 'updated_desc' })
const form = reactive({
  stock_master_id: null,
  shopee_item_id: '',
  shopee_model_id: '',
  tiktok_product_id: '',
  tiktok_sku_id: '',
  tiktok_sku_name: '',
  internal_image_url: '',
  shopee_image_url: '',
  tiktok_image_url: '',
  notes: ''
})

const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '-'
const initials = (name) => String(name || 'SK').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const labelStatus = (status) => status === 'fully_mapped' ? 'Fully Mapped' : status === 'partially_mapped' ? 'Partial' : 'Unmapped'

const productGroups = computed(() => {
  const groups = new Map()

  items.value.forEach((item) => {
    const key = item.shopee?.item_id || item.tiktok?.product_id || item.product_name || item.internal_sku

    if (!groups.has(key)) {
      groups.set(key, {
        key,
        name: item.product_name || item.shopee?.product_name || item.tiktok?.product_name || 'Produk',
        image_url: item.image_url || item.shopee?.image_url || item.tiktok?.image_url || '',
        variants: [],
        total_stock: 0,
        shopee: { mapped: 0, product_name: item.shopee?.product_name || '' },
        tiktok: { mapped: 0, product_name: item.tiktok?.product_name || '' },
        status: 'unmapped'
      })
    }

    const group = groups.get(key)
    group.variants.push(item)
    group.total_stock += Number(item.stock_qty || 0)

    if (item.shopee?.status === 'mapped') group.shopee.mapped += 1
    if (item.tiktok?.status === 'mapped') group.tiktok.mapped += 1
    if (!group.image_url) group.image_url = item.image_url || item.shopee?.image_url || item.tiktok?.image_url || ''
    if (!group.shopee.product_name && item.shopee?.product_name) group.shopee.product_name = item.shopee.product_name
    if (!group.tiktok.product_name && item.tiktok?.product_name) group.tiktok.product_name = item.tiktok.product_name
  })

  return Array.from(groups.values()).map((group) => {
    const fullyMapped = group.variants.every((item) => item.status === 'fully_mapped')
    const unmapped = group.variants.every((item) => item.status === 'unmapped')

    return {
      ...group,
      status: fullyMapped ? 'fully_mapped' : (unmapped ? 'unmapped' : 'partially_mapped')
    }
  })
})

const isExpanded = (key) => expandedGroups.value[key] !== false

const toggleGroup = (key) => {
  expandedGroups.value = {
    ...expandedGroups.value,
    [key]: !isExpanded(key)
  }
}

const loadData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const response = await omnichannelService.skuMapping(filters)
    summary.value = response.data.summary
    items.value = response.data.items || []
    if (!selectedItem.value && items.value.length) {
      selectItem(items.value[0])
    }
  } catch (error) {
    loadError.value = error.response?.data?.message || 'SKU mapping gagal memuat data dari API.'
    items.value = []
  } finally {
    loading.value = false
  }
}

const selectItem = (item) => {
  selectedItem.value = item
  form.stock_master_id = item.id
  form.shopee_item_id = item.shopee?.item_id || ''
  form.shopee_model_id = item.shopee?.model_id || ''
  form.tiktok_product_id = item.tiktok?.product_id || ''
  form.tiktok_sku_id = item.tiktok?.sku_id || ''
  form.tiktok_sku_name = item.tiktok?.sku_name || item.tiktok?.variant_name || ''
  form.internal_image_url = item.image_url || ''
  form.shopee_image_url = item.shopee?.image_url || ''
  form.tiktok_image_url = item.tiktok?.image_url || ''
}

const fillFromSelected = () => {
  if (selectedItem.value) selectItem(selectedItem.value)
}

const save = async () => {
  if (!form.stock_master_id) return
  saving.value = true
  loadError.value = ''
  try {
    await omnichannelService.saveSkuMapping({ ...form })
    await loadData()
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Mapping gagal disimpan.'
  } finally {
    saving.value = false
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
.page-header p { color:#64748b; margin-bottom:4px; }
.header-actions { display:flex; gap:10px; }
.primary,.ghost,.mini,.expand { border:0; border-radius:6px; cursor:pointer; }
.primary,.ghost { padding:10px 14px; }
.primary { background:#0f5fc7; color:#fff; }
.ghost { background:#fff; border:1px solid #d9e2ec; color:#334155; }
.primary:disabled,.ghost:disabled { cursor:wait; opacity:.72; }
.notice { color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px; }
.notice.error { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
.summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:12px; }
.metric { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.metric span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.metric strong { font-size:22px; color:#111827; }
.layout { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:12px; }
.panel { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.filter-row { display:grid; grid-template-columns:1.5fr .8fr .8fr; gap:10px; margin-bottom:12px; }
input,select,textarea { width:100%; border:1px solid #d7dde8; border-radius:6px; padding:10px; font-size:13px; }
.table-wrap { max-height:72vh; overflow:auto; border:1px solid #e5e7eb; border-radius:8px; }
table { width:100%; border-collapse:collapse; font-size:13px; min-width:980px; }
th,td { border-bottom:1px solid #e5e7eb; padding:10px; vertical-align:top; text-align:left; }
thead th { position:sticky; top:0; background:#1f2937; color:#fff; z-index:1; }
tbody:last-child tr:last-child td { border-bottom:0; }
td small { color:#64748b; display:block; margin-top:3px; }
.product-row { background:#f8fafc; }
.product-row td:first-child { display:flex; align-items:center; gap:10px; }
.product-row strong { color:#111827; }
.variant-row { cursor:pointer; }
.variant-row:hover,.variant-row.active { background:#eff6ff; }
.variant-title strong { display:block; margin-bottom:4px; }
.channel-cell { display:grid; grid-template-columns:54px minmax(0,1fr); gap:10px; align-items:start; }
.channel-cell strong { display:block; line-height:1.25; margin-bottom:4px; }
.thumb { width:54px; height:54px; border-radius:6px; object-fit:cover; background:#eef2f7; border:1px solid #e2e8f0; }
.thumb.small { width:42px; height:42px; flex:0 0 auto; }
.thumb.large { width:82px; height:82px; }
.fallback { display:grid; place-items:center; color:#64748b; font-weight:800; font-size:12px; }
.expand { width:28px; height:28px; background:#e2e8f0; color:#0f172a; font-weight:800; flex:0 0 auto; }
.mini { display:block; margin-top:8px; padding:6px 9px; background:#e2e8f0; color:#334155; font-size:12px; }
.badge { display:inline-block; border-radius:999px; padding:4px 8px; font-size:12px; font-weight:700; }
.badge.fully_mapped { background:#d1fae5; color:#047857; }
.badge.partially_mapped { background:#fef3c7; color:#b45309; }
.badge.unmapped { background:#eef2f7; color:#64748b; }
.detail-panel label { display:block; margin-bottom:10px; }
.detail-panel span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.detail-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
.detail-head strong { display:block; color:#111827; line-height:1.25; }
.actions { display:flex; gap:10px; margin-top:12px; }
.empty { text-align:center; color:#64748b; padding:20px; }
@media (max-width: 1180px) { .layout { grid-template-columns:1fr; } .detail-panel { order:-1; } }
@media (max-width: 820px) { .page-shell { margin-left:0; padding:16px; } .summary-grid,.filter-row { grid-template-columns:1fr; } .page-header { align-items:flex-start; flex-direction:column; } }
</style>
