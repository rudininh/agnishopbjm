<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Eksperimen TikTok</p>
        <h1>Tambah Varian</h1>
        <small class="subtitle">Halaman uji untuk satu etalase: create / update / mapping varian.</small>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="loadData" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" @click="save" :disabled="!selectedItem || !form.stock_master_id || saving">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
      </div>
    </header>

    <p v-if="loadError" class="notice error">{{ loadError }}</p>
    <p v-else-if="loading && !items.length" class="notice">Memuat data etalase uji...</p>

    <div class="control-band">
      <label>
        <span>Nama etalase uji</span>
        <input v-model.trim="filters.search" type="search" placeholder="Azara Hijab Segi Empat Polos Paris Packing Pouch Metal Logo" @keyup.enter="loadData" />
      </label>
      <button class="ghost" @click="loadData" :disabled="loading">Muat etalase ini</button>
    </div>

    <div class="summary-grid" v-if="activeGroup">
      <article class="metric">
        <span>Etalase</span>
        <strong>{{ activeGroup.name }}</strong>
      </article>
      <article class="metric">
        <span>Shopee</span>
        <strong>{{ activeGroup.shopee.present }} / {{ activeGroup.variants.length }}</strong>
        <small>{{ activeGroup.shopee.total_stock }} stok aktif</small>
      </article>
      <article class="metric">
        <span>TikTok</span>
        <strong>{{ activeGroup.tiktok.present }} / {{ activeGroup.variants.length }}</strong>
        <small>{{ activeGroup.tiktok.total_stock }} stok aktif</small>
      </article>
      <article class="metric">
        <span>Status</span>
        <strong>{{ labelStatus(activeGroup.status) }}</strong>
        <small>{{ activeGroup.tiktok.missing }} varian belum ada di TikTok</small>
      </article>
    </div>

    <div class="layout" v-if="activeGroup">
      <div class="panel list-panel">
        <div class="group-head">
          <div class="group-title">
            <img v-if="activeGroup.image_url" :src="activeGroup.image_url" class="thumb large" :alt="activeGroup.name" />
            <div v-else class="thumb large fallback">{{ initials(activeGroup.name) }}</div>
            <div>
              <strong>{{ activeGroup.name }}</strong>
              <small>Shopee: {{ activeGroup.shopee.present }} varian, stok {{ activeGroup.shopee.total_stock }}</small>
              <small>TikTok: {{ activeGroup.tiktok.present }} varian, stok {{ activeGroup.tiktok.total_stock }}</small>
            </div>
          </div>
          <span :class="['badge', activeGroup.status]">{{ labelStatus(activeGroup.status) }}</span>
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
                <th>Varian</th>
                <th>Shopee</th>
                <th>TikTok</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in activeGroup.variants"
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
                          {{ channelStatusLabel(item.tiktok.status) }}
                        </span>
                      </div>
                      <small>{{ tiktokPresenceLabel(item) }}</small>
                      <small>{{ item.tiktok?.product_name || '-' }}</small>
                      <small>Product ID: {{ hasTiktokActual(item) ? (item.tiktok?.product_id || '-') : '-' }}</small>
                      <small>SKU ID: {{ hasTiktokActual(item) ? (item.tiktok?.sku_id || '-') : '-' }}</small>
                      <small>Nama Varian Asli: {{ item.variant_name || item.tiktok?.variant_name || '-' }}</small>
                      <small>Kode Variasi: {{ item.tiktok?.seller_sku || item.seller_sku || '-' }}</small>
                      <small v-if="hasTiktokActual(item)">Kode SKU TikTok: {{ item.tiktok?.sku_name || '-' }}</small>
                      <small v-else>Kode ini belum menunjuk ke varian TikTok yang aktif.</small>
                      <small>Stok: {{ hasTiktokActual(item) ? displayStock(item.tiktok?.stock_qty) : '-' }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <span :class="['badge', item.status]">{{ labelStatus(item.status) }}</span>
                  <button class="mini" @click.stop="selectItem(item)">Edit</button>
                  <button
                    v-if="canPrepareMissingVariant(item)"
                    class="mini"
                    :disabled="preparing || item.status === 'submitted'"
                    @click.stop="prepareMissingVariant(item)"
                  >
                    {{ preparing ? 'Menyiapkan...' : variantActionLabel(item) }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <aside class="panel detail-panel" v-if="selectedItem">
        <div class="detail-head">
          <div>
            <span>Nama Varian Asli</span>
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
          <span>Warehouse ID TikTok</span>
          <input v-model="form.warehouse_id" placeholder="7068517275539719942" />
        </label>
        <label>
          <span>Stok TikTok</span>
          <input v-model="form.inventory_qty" type="number" min="0" />
        </label>
        <label>
          <span>Notes</span>
          <textarea v-model="form.notes" rows="4"></textarea>
        </label>
        <p class="notice">{{ shopeeDetailHint(selectedItem) }} {{ tiktokDetailHint(selectedItem) }}</p>

        <div class="actions stacked">
          <button class="ghost" @click="fillFromSelected">Reset</button>
          <button class="primary" @click="save" :disabled="saving || !form.stock_master_id">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
        </div>
        <div class="action-grid">
          <button class="ghost" @click="runTiktokAction('upload_image')" :disabled="actionBusy || !selectedItem">Upload Gambar</button>
          <button class="ghost" @click="runTiktokAction('save_product')" :disabled="actionBusy || !selectedItem">Simpan Produk</button>
          <button class="ghost" @click="runTiktokAction('update_inventory')" :disabled="actionBusy || !selectedItem">Update Stok</button>
          <button class="primary" @click="runTiktokAction('full_sync')" :disabled="actionBusy || !selectedItem">{{ actionBusy ? 'Memproses...' : 'Full Sync TikTok' }}</button>
        </div>
        <p v-if="actionLog" class="notice">{{ actionLog }}</p>
        <p v-if="!form.stock_master_id" class="notice">Baris ini hanya ada di TikTok. Save Mapping aktif setelah dipasangkan ke varian Shopee.</p>
      </aside>
    </div>

    <p v-else class="notice">Tidak ada data etalase yang cocok dengan nama uji ini.</p>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const DEFAULT_PRODUCT_NAME = 'Azara Hijab Segi Empat Polos Paris Packing Pouch Metal Logo'

const loading = ref(false)
const saving = ref(false)
const preparing = ref(false)
const actionBusy = ref(false)
const loadError = ref('')
const actionLog = ref('')
const items = ref([])
const selectedItem = ref(null)
const filters = reactive({
  search: DEFAULT_PRODUCT_NAME
})
const form = reactive({
  stock_master_id: null,
  shopee_item_id: '',
  shopee_model_id: '',
  seller_sku: '',
  tiktok_product_id: '',
  tiktok_sku_id: '',
  tiktok_sku_name: '',
  warehouse_id: '',
  inventory_qty: 0,
  notes: ''
})

const normalizeText = (value) => String(value || '').trim().toLowerCase().replace(/\s+/g, ' ')
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '-'
const initials = (name) => String(name || 'SK').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const labelStatus = (status) => status === 'ready_to_sync' ? 'Siap disinkronkan' : status === 'submitted' ? 'Dikirim ke TikTok' : status === 'failed' ? 'Gagal kirim' : status === 'ready_to_create' ? 'Siap dibuat' : status === 'needs_creation' ? 'Perlu dibuat' : status === 'both' ? 'Shopee + TikTok' : status === 'shopee_only' ? 'Hanya Shopee' : status === 'tiktok_only' ? 'Hanya TikTok' : 'Belum dipasangkan'
const channelStatusLabel = (status) => status === 'mapped' ? 'Tersimpan' : status === 'suggested' ? 'Kandidat kode variasi' : 'Belum'
const displayStock = (value) => value === null || value === undefined || value === '' ? '-' : Number(value)
const hasTiktokActual = (item) => Boolean(item?.tiktok?.product_id || item?.tiktok?.sku_id || item?.tiktok?.image_url || item?.tiktok?.stock_qty !== null && item?.tiktok?.stock_qty !== undefined)
const hasTiktokCandidate = (item) => Boolean(item?.tiktok?.seller_sku || item?.seller_sku)
const hasShopeeActual = (item) => Boolean(item?.shopee?.item_id || item?.shopee?.model_id || item?.shopee?.image_url || item?.shopee?.stock_qty !== null && item?.shopee?.stock_qty !== undefined)
const hasShopeeCandidate = (item) => Boolean(item?.shopee?.seller_sku || item?.seller_sku)
const hasTiktok = (item) => hasTiktokActual(item) || hasTiktokCandidate(item)
const hasShopee = (item) => hasShopeeActual(item) || hasShopeeCandidate(item)
const shopeePresenceLabel = (item) => hasShopeeActual(item) ? 'Ada di Shopee' : hasShopeeCandidate(item) ? 'Kode variasi cocok, varian Shopee belum ada' : 'Varian ini tidak ada di Shopee'
const tiktokPresenceLabel = (item) => hasTiktokActual(item) ? 'Ada di TikTok' : hasTiktokCandidate(item) ? 'Kode variasi cocok, varian TikTok belum ada' : 'Varian ini tidak ada di TikTok'
const missingTargetChannel = (item) => {
  if (hasShopeeActual(item) && !hasTiktokActual(item)) return 'tiktok'
  if (hasTiktokActual(item) && !hasShopeeActual(item)) return 'shopee'
  return null
}
const canPrepareMissingVariant = (item) => Boolean(missingTargetChannel(item))
const variantActionLabel = (item) => item?.status === 'ready_to_create' ? 'Siap dibuat' : item?.status === 'submitted' ? 'Dikirim ke TikTok' : item?.status === 'failed' ? 'Coba Lagi' : 'Buat Varian Hilang'
const variantSortRank = (item) => {
  const tiktokCandidateOnly = hasTiktokCandidate(item) && !hasTiktokActual(item)

  if (tiktokCandidateOnly) return 0
  if (item?.status === 'ready_to_sync') return 1
  if (item?.status === 'submitted') return 2
  if (item?.status === 'ready_to_create') return 3
  if (item?.status === 'failed') return 4
  return 5
}
const tiktokDetailHint = (item) => hasTiktokActual(item)
  ? 'Data TikTok aktif sudah tersedia.'
  : hasTiktokCandidate(item)
    ? 'Yang cocok baru kode variasinya, belum ada varian TikTok aktif.'
    : 'Varian ini memang belum ada di TikTok.'
const shopeeDetailHint = (item) => hasShopeeActual(item)
  ? 'Data Shopee aktif sudah tersedia.'
  : hasShopeeCandidate(item)
    ? 'Yang cocok baru kode variasinya, belum ada varian Shopee aktif.'
    : 'Varian ini memang belum ada di Shopee.'

const groupedItems = computed(() => {
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

const activeGroup = computed(() => {
  if (!groupedItems.value.length) return null
  const exact = groupedItems.value.find((group) => normalizeText(group.name) === normalizeText(filters.search))
  const group = exact || groupedItems.value[0]

  return {
    ...group,
    variants: [...group.variants].sort((a, b) => {
      const rankDiff = variantSortRank(a) - variantSortRank(b)
      if (rankDiff !== 0) return rankDiff

      const aName = normalizeText(a.variant_name || a.tiktok?.variant_name || '')
      const bName = normalizeText(b.variant_name || b.tiktok?.variant_name || '')
      return aName.localeCompare(bName)
    })
  }
})

const loadData = async () => {
  loading.value = true
  loadError.value = ''
  try {
    const response = await omnichannelService.skuMapping({
      search: filters.search,
      status: 'all',
      sort: 'updated_desc'
    })
    items.value = response.data.items || []
    const first = activeGroup.value?.variants?.[0] || null
    if (first) selectItem(first)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Data tambah varian gagal dimuat.'
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
  form.tiktok_sku_name = item.tiktok?.variant_name || item.tiktok?.sku_name || item.variant_name || ''
  form.warehouse_id = item.tiktok?.warehouse_id || form.warehouse_id || ''
  form.inventory_qty = Number(item.tiktok?.stock_qty ?? item.stock_qty ?? 0)
  form.notes = item.notes || ''
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
    const refreshed = activeGroup.value?.variants?.find((candidate) => candidate.stock_master_id === item.stock_master_id) || activeGroup.value?.variants?.[0]
    if (refreshed) selectItem(refreshed)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Draft varian gagal disiapkan.'
  } finally {
    preparing.value = false
  }
}

const runTiktokAction = async (action) => {
  if (!form.stock_master_id && selectedItem.value) {
    form.stock_master_id = selectedItem.value.stock_master_id || selectedItem.value.id
  }

  if (!form.stock_master_id) return
  if ((action === 'update_inventory' || action === 'full_sync') && !String(form.warehouse_id || '').trim()) {
    loadError.value = 'Warehouse ID TikTok wajib diisi untuk update stok.'
    return
  }

  actionBusy.value = true
  loadError.value = ''
  actionLog.value = ''
  try {
    const response = await omnichannelService.tiktokVariantAction({
      stock_master_id: form.stock_master_id,
      action,
      warehouse_id: form.warehouse_id,
      quantity: form.inventory_qty
    })

    actionLog.value = response.data?.message || 'Aksi TikTok berhasil diproses.'
    await loadData()
    const refreshed = activeGroup.value?.variants?.find((candidate) => candidate.stock_master_id === form.stock_master_id) || activeGroup.value?.variants?.[0]
    if (refreshed) selectItem(refreshed)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Aksi TikTok gagal diproses.'
  } finally {
    actionBusy.value = false
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
.page-header p { color:#64748b; margin-bottom:4px; }
.subtitle { display:block; margin-top:4px; color:#64748b; }
.header-actions { display:flex; gap:10px; }
.primary,.ghost,.mini { border:0; border-radius:6px; cursor:pointer; }
.primary,.ghost { padding:10px 14px; }
.primary { background:#0f5fc7; color:#fff; }
.ghost { background:#fff; border:1px solid #d9e2ec; color:#334155; }
.primary:disabled,.ghost:disabled { cursor:wait; opacity:.72; }
.notice { color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px; }
.notice.error { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
.control-band { display:flex; gap:12px; align-items:end; margin-bottom:12px; padding:12px; background:#fff; border:1px solid #d9e2ec; border-radius:8px; }
.control-band label { flex:1; }
.control-band span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.control-band input { width:100%; border:1px solid #d7dde8; border-radius:6px; padding:10px; font-size:13px; }
.summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin-bottom:12px; }
.metric { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.metric span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.metric strong { display:block; font-size:18px; color:#111827; line-height:1.2; }
.metric small { color:#64748b; display:block; margin-top:6px; }
.layout { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:12px; }
.panel { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.group-head { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:12px; }
.group-title { display:flex; gap:12px; align-items:flex-start; min-width:0; }
.group-title strong { display:block; color:#111827; font-size:18px; line-height:1.25; margin-bottom:4px; }
.group-title small { color:#64748b; display:block; margin-top:3px; }
.table-wrap { max-height:72vh; overflow:auto; border:1px solid #e5e7eb; border-radius:8px; }
.mapping-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1240px; table-layout:fixed; }
.col-product { width:28%; }
.col-channel { width:31%; }
.col-status { width:10%; }
th,td { border-bottom:1px solid #e5e7eb; padding:10px; vertical-align:top; text-align:left; }
thead th { position:sticky; top:0; background:#1f2937; color:#fff; z-index:1; }
tbody:last-child tr:last-child td { border-bottom:0; }
td small { color:#64748b; display:block; margin-top:3px; }
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
.thumb.large { width:82px; height:82px; }
.fallback { display:grid; place-items:center; color:#64748b; font-weight:800; font-size:12px; }
.mini { display:block; margin-top:8px; padding:6px 9px; background:#e2e8f0; color:#334155; font-size:12px; }
.badge { display:inline-block; border-radius:999px; padding:4px 8px; font-size:12px; font-weight:700; }
.badge.both { background:#d1fae5; color:#047857; }
.badge.shopee_only { background:#e0f2fe; color:#0369a1; }
.badge.tiktok_only { background:#fae8ff; color:#a21caf; }
.badge.submitted { background:#dbeafe; color:#1d4ed8; }
.badge.failed { background:#fee2e2; color:#b91c1c; }
.badge.ready_to_sync { background:#d1fae5; color:#047857; }
.badge.ready_to_create { background:#fef3c7; color:#b45309; }
.badge.needs_creation { background:#eef2f7; color:#475569; }
.detail-panel label { display:block; margin-bottom:10px; }
.detail-panel span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.detail-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
.detail-head strong { display:block; color:#111827; line-height:1.25; }
.actions { display:flex; gap:10px; margin-top:12px; }
.actions.stacked { margin-top: 10px; }
.action-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px; }
@media (max-width: 1180px) { .layout { grid-template-columns:1fr; } .detail-panel { order:-1; } }
@media (max-width: 820px) { .page-shell { margin-left:0; padding:16px; } .summary-grid,.control-band { grid-template-columns:1fr; flex-direction:column; align-items:stretch; } .page-header { align-items:flex-start; flex-direction:column; } .action-grid { grid-template-columns:1fr; } }
</style>
