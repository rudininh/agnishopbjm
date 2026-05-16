<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Omnichannel</p>
        <h1>SKU Mapping</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="loadData" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" @click="save" :disabled="!selectedItem || !form.stock_master_id || saving">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
        <button class="ghost" @click.stop="selectedItem && selectItem(selectedItem)" :disabled="!selectedItem">Edit</button>
        <button
          v-if="selectedItem && canPrepareMissingVariant(selectedItem)"
          class="primary"
          :disabled="preparing || selectedItem.status === 'submitted'"
          @click="prepareMissingVariant(selectedItem)"
        >
          {{ preparing ? 'Menyiapkan...' : `Buat Varian Hilang di ${missingTargetChannel(selectedItem) === 'tiktok' ? 'TikTok' : 'Shopee'}` }}
        </button>
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
            <option value="mapped">Ada di satu sisi</option>
            <option value="unmapped">Belum ada di dua sisi</option>
          </select>
          <select v-model="filters.sort" @change="loadData">
            <option value="updated_desc">Update Time</option>
            <option value="created_desc">Create Time</option>
            <option value="name_asc">Nama Produk</option>
          </select>
        </div>

        <div class="table-wrap">
          <table class="mapping-table">
            <colgroup>
              <col class="col-product" />
              <col class="col-channel" />
              <col class="col-channel" />
              <col class="col-status" />
            </colgroup>
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
                    <small>Shopee: {{ group.shopee.present }} varian, stok {{ group.shopee.total_stock }}</small>
                    <small>TikTok: {{ group.tiktok.present }} varian, stok {{ group.tiktok.total_stock }}</small>
                  </div>
                </td>
                <td>
                  <strong>{{ group.shopee.present }} / {{ group.variants.length }}</strong>
                  <small>{{ group.shopee.product_name || 'Belum terhubung' }}</small>
                  <small>{{ group.shopee.missing }} varian belum ada di Shopee</small>
                </td>
                <td>
                  <strong>{{ group.tiktok.present }} / {{ group.variants.length }}</strong>
                  <small>{{ group.tiktok.product_name || 'Belum terhubung' }}</small>
                  <small>{{ group.tiktok.missing }} varian belum ada di TikTok</small>
                  <small v-if="group.tiktok.suggested">{{ group.tiktok.suggested }} kandidat TikTok</small>
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
                    <small>SKU internal: {{ item.internal_sku }}</small>
                    <small>Stock Master: {{ item.stock_qty }}</small>
                  </div>
                </td>
                <td>
                  <div class="channel-cell">
                    <img v-if="item.shopee?.image_url" :src="item.shopee.image_url" class="thumb" :alt="item.shopee.variant_name" />
                    <div v-else class="thumb fallback">SP</div>
                    <div>
                      <strong>{{ item.shopee?.variant_name || item.shopee?.product_name || '-' }}</strong>
                      <small>{{ shopeePresenceLabel(item) }}</small>
                      <small>Item ID: {{ item.shopee?.item_id || '-' }}</small>
                      <small>Model ID: {{ item.shopee?.model_id || '-' }}</small>
                      <small>Kode Variasi: {{ item.shopee?.seller_sku || item.seller_sku || '-' }}</small>
                      <small>Stok: {{ displayStock(item.shopee?.stock_qty) }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <div :class="['channel-cell', { muted: !hasTiktok(item) }]">
                    <img v-if="item.tiktok?.image_url" :src="item.tiktok.image_url" class="thumb" :alt="item.tiktok.variant_name" />
                    <div v-else class="thumb fallback">TT</div>
                    <div>
                      <div class="channel-title-row">
                        <strong>{{ item.variant_name || item.tiktok?.variant_name || '-' }}</strong>
                        <span
                          v-if="item.tiktok?.status && item.tiktok.status !== 'unmapped'"
                          :class="['channel-badge', item.tiktok.status]"
                        >
                          {{ channelStatusLabel(item.tiktok.status, item.tiktok?.source) }}
                        </span>
                      </div>
                      <small>{{ tiktokPresenceLabel(item) }}</small>
                      <small>{{ item.tiktok?.product_name || '-' }}</small>
                      <small>Product ID: {{ hasTiktokActual(item) ? (item.tiktok?.product_id || '-') : hasTiktokProductCandidate(item) ? `${item.tiktok?.product_id || '-'} (kandidat produk)` : '-' }}</small>
                      <small>SKU ID: {{ hasTiktokActual(item) ? (item.tiktok?.sku_id || '-') : '-' }}</small>
                      <small>Nama Varian: {{ item.variant_name || item.tiktok?.variant_name || '-' }}</small>
                      <small>Kode Variasi: {{ item.tiktok?.seller_sku || item.seller_sku || '-' }}</small>
                      <small v-if="hasTiktokActual(item)">Kode SKU TikTok: {{ item.tiktok?.sku_name || '-' }}</small>
                      <small v-else-if="hasTiktokProductCandidate(item)">Produk TikTok sudah ada, tetapi varian ini belum aktif.</small>
                      <small v-else>Kode ini belum menunjuk ke varian TikTok yang aktif.</small>
                      <small>Stok: {{ hasTiktokActual(item) ? displayStock(item.tiktok?.stock_qty) : '-' }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <span :class="['badge', item.status]">{{ labelStatus(item.status) }}</span>
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
          <span>Shopee Model ID</span>
          <input v-model="form.shopee_model_id" />
        </label>
        <label>
          <span>Kode Variasi</span>
          <input v-model="form.seller_sku" />
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
          <span>Nama Varian TikTok</span>
          <input v-model="form.tiktok_sku_name" />
        </label>
        <label>
          <span>Notes</span>
          <textarea v-model="form.notes" rows="4"></textarea>
        </label>
        <p class="notice">{{ shopeeDetailHint(selectedItem) }} {{ tiktokDetailHint(selectedItem) }}</p>

        <div class="actions">
          <button class="ghost" @click="fillFromSelected">Reset</button>
          <button class="primary" @click="save" :disabled="saving || !form.stock_master_id">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
        </div>
        <p v-if="!form.stock_master_id" class="notice">Baris ini hanya ada di TikTok. Save Mapping aktif setelah dipasangkan ke varian Shopee.</p>
      </aside>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const saving = ref(false)
const preparing = ref(false)
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
  seller_sku: '',
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
const labelStatus = (status) => status === 'ready_to_sync' ? 'Siap disinkronkan' : status === 'submitted' ? 'Dikirim ke TikTok' : status === 'failed' ? 'Gagal kirim' : status === 'ready_to_create' ? 'Siap dibuat' : status === 'needs_creation' ? 'Perlu dibuat' : status === 'both' ? 'Shopee + TikTok' : status === 'shopee_only' ? 'Hanya Shopee' : status === 'tiktok_only' ? 'Hanya TikTok' : 'Belum dipasangkan'
const channelStatusLabel = (status, source = '') => source === 'suggested_product'
  ? 'Kandidat produk'
  : status === 'mapped'
    ? (source === 'saved' ? 'Tersimpan' : 'Ada di TikTok')
    : status === 'suggested'
      ? 'Kandidat kode variasi'
      : 'Belum'
const displayStock = (value) => value === null || value === undefined || value === '' ? '-' : Number(value)
const tiktokMatchSource = (item) => String(item?.tiktok?.source || '').trim()
const hasTiktokActual = (item) => {
  const source = tiktokMatchSource(item)
  if (source) {
    return source !== 'suggested_product'
  }

  return Boolean(item?.tiktok?.sku_id || item?.tiktok?.status === 'mapped')
}
const hasTiktokProductCandidate = (item) => tiktokMatchSource(item) === 'suggested_product'
const hasTiktokCandidate = (item) => Boolean(item?.tiktok?.seller_sku || item?.seller_sku)
const hasShopeeActual = (item) => Boolean(item?.shopee?.item_id || item?.shopee?.model_id || item?.shopee?.image_url || item?.shopee?.stock_qty !== null && item?.shopee?.stock_qty !== undefined)
const hasShopeeCandidate = (item) => Boolean(item?.shopee?.seller_sku || item?.seller_sku)
const hasTiktok = (item) => hasTiktokActual(item) || hasTiktokProductCandidate(item) || hasTiktokCandidate(item)
const hasShopee = (item) => hasShopeeActual(item) || hasShopeeCandidate(item)
const shopeePresenceLabel = (item) => hasShopeeActual(item) ? 'Ada di Shopee' : hasShopeeCandidate(item) ? 'Kode variasi cocok, varian Shopee belum ada' : 'Varian ini tidak ada di Shopee'
const tiktokPresenceLabel = (item) => {
  if (hasTiktokActual(item)) return 'Ada di TikTok'
  if (hasTiktokProductCandidate(item)) return 'Produk TikTok ada, varian belum cocok'
  if (hasTiktokCandidate(item)) return 'Kode variasi cocok, varian TikTok belum ada'
  return 'Varian ini tidak ada di TikTok'
}
const missingTargetChannel = (item) => {
  if (hasShopeeActual(item) && !hasTiktokActual(item)) return 'tiktok'
  if (hasTiktokActual(item) && !hasShopeeActual(item)) return 'shopee'
  return null
}
const canPrepareMissingVariant = (item) => Boolean(missingTargetChannel(item))
const tiktokDetailHint = (item) => {
  if (hasTiktokActual(item)) {
    return 'Data TikTok aktif sudah tersedia.'
  }

  if (hasTiktokProductCandidate(item)) {
    return 'Produk TikTok sudah ada, tetapi varian ini belum cocok ke SKU aktif.'
  }

  if (hasTiktokCandidate(item)) {
    return 'Yang cocok baru kode variasinya, belum ada varian TikTok aktif.'
  }

  return 'Varian ini memang belum ada di TikTok.'
}
const shopeeDetailHint = (item) => hasShopeeActual(item)
  ? 'Data Shopee aktif sudah tersedia.'
  : hasShopeeCandidate(item)
    ? 'Yang cocok baru kode variasinya, belum ada varian Shopee aktif.'
    : 'Varian ini memang belum ada di Shopee.'

const productGroups = computed(() => {
  const groups = new Map()

  items.value.forEach((item) => {
    const key = item.group_key || item.shopee?.item_id || item.tiktok?.product_id || item.product_name || item.internal_sku

    if (!groups.has(key)) {
      groups.set(key, {
        key,
        name: item.product_name || item.shopee?.product_name || item.tiktok?.product_name || 'Produk',
        image_url: item.image_url || item.shopee?.image_url || item.tiktok?.image_url || '',
        variants: [],
        total_stock: 0,
        shopee: { present: 0, missing: 0, total_stock: 0, product_name: item.shopee?.product_name || '' },
        tiktok: { present: 0, missing: 0, total_stock: 0, suggested: 0, product_name: item.tiktok?.product_name || '' },
        status: 'unmapped'
      })
    }

    const group = groups.get(key)
    group.variants.push(item)
    group.total_stock += Number(item.stock_qty || 0)

    if (hasShopeeActual(item)) {
      group.shopee.present += 1
      group.shopee.total_stock += Number(item.shopee?.stock_qty || 0)
    } else {
      group.shopee.missing += 1
    }

    if (hasTiktokActual(item)) {
      group.tiktok.present += 1
      group.tiktok.total_stock += Number(item.tiktok?.stock_qty || 0)
    } else {
      group.tiktok.missing += 1
    }

    if (item.tiktok?.status === 'suggested') group.tiktok.suggested += 1
    if (!group.image_url) group.image_url = item.image_url || item.shopee?.image_url || item.tiktok?.image_url || ''
    if (!group.shopee.product_name && item.shopee?.product_name) group.shopee.product_name = item.shopee.product_name
    if (!group.tiktok.product_name && item.tiktok?.product_name) group.tiktok.product_name = item.tiktok.product_name
  })

  return Array.from(groups.values()).map((group) => {
    const hasShopeeSide = group.shopee.present > 0
    const hasTiktokSide = group.tiktok.present > 0

    return {
      ...group,
      status: hasShopeeSide && hasTiktokSide ? 'both' : (hasShopeeSide ? 'shopee_only' : 'tiktok_only')
    }
  })
})

const isExpanded = (key) => expandedGroups.value[key] === true

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
  form.stock_master_id = item.stock_master_id || (typeof item.id === 'number' ? item.id : null)
  form.shopee_item_id = item.shopee?.item_id || ''
  form.shopee_model_id = item.shopee?.model_id || ''
  form.seller_sku = item.seller_sku || item.shopee?.seller_sku || item.tiktok?.seller_sku || ''
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

const prepareMissingVariant = async (item) => {
  const targetChannel = missingTargetChannel(item)
  if (!targetChannel) return

  preparing.value = true
  loadError.value = ''
  try {
    await omnichannelService.prepareMissingVariant({
      stock_master_id: item.stock_master_id || item.id,
      target_channel: targetChannel
    })
    await loadData()
    const refreshed = items.value.find((candidate) => candidate.stock_master_id === item.stock_master_id)
    if (refreshed) {
      selectItem(refreshed)
    }
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Draft varian gagal disiapkan.'
  } finally {
    preparing.value = false
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
.mapping-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1240px; table-layout:fixed; }
.col-product { width:28%; }
.col-channel { width:31%; }
.col-status { width:10%; }
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
.channel-cell { display:grid; grid-template-columns:58px minmax(0,1fr); gap:10px; align-items:start; min-width:0; }
.channel-cell strong { display:block; line-height:1.25; margin-bottom:4px; }
.channel-cell small,.variant-title small { overflow-wrap:anywhere; }
.channel-cell.muted { color:#64748b; }
.channel-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; min-width:0; }
.channel-title-row strong { min-width:0; overflow-wrap:anywhere; }
.channel-badge { flex:0 0 auto; border-radius:999px; padding:3px 7px; font-size:11px; font-weight:800; line-height:1.2; }
.channel-badge.mapped { color:#047857; background:#d1fae5; }
.channel-badge.suggested { color:#b45309; background:#fef3c7; }
.thumb { width:58px; height:58px; border-radius:6px; object-fit:cover; background:#eef2f7; border:1px solid #e2e8f0; }
.thumb.small { width:42px; height:42px; flex:0 0 auto; }
.thumb.large { width:82px; height:82px; }
.fallback { display:grid; place-items:center; color:#64748b; font-weight:800; font-size:12px; }
.expand { width:28px; height:28px; background:#e2e8f0; color:#0f172a; font-weight:800; flex:0 0 auto; }
.mini { display:block; margin-top:8px; padding:6px 9px; background:#e2e8f0; color:#334155; font-size:12px; }
.badge { display:inline-block; border-radius:999px; padding:4px 8px; font-size:12px; font-weight:700; }
.badge.both { background:#d1fae5; color:#047857; }
.badge.shopee_only { background:#e0f2fe; color:#0369a1; }
.badge.tiktok_only { background:#fae8ff; color:#a21caf; }
.badge.info { background:#eff6ff; color:#1d4ed8; }
.badge.submitted { background:#dbeafe; color:#1d4ed8; }
.badge.failed { background:#fee2e2; color:#b91c1c; }
.badge.ready_to_sync { background:#d1fae5; color:#047857; }
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
