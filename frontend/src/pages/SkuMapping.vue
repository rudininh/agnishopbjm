<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Omnichannel</p>
        <h1>SKU Mapping</h1>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="syncMarketplaces" :disabled="loading || syncingMarketplaces">{{ syncingMarketplaces ? 'Sync...' : 'Sync Marketplace' }}</button>
        <button class="ghost" @click="loadData" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" @click="submitSkuUpdate" :disabled="!canSubmitSkuUpdate">
          {{ submitting ? 'Mengirim...' : 'Update SKU Shopee + TikTok' }}
        </button>
      </div>
    </header>

    <p v-if="loadError" class="notice error">{{ loadError }}</p>
    <p v-else-if="loading && !items.length" class="notice">Memuat data SKU mapping...</p>

    <div class="summary-grid">
      <article class="metric"><span>Grup Produk</span><strong>{{ productGroups.length }}</strong></article>
      <article class="metric"><span>Variant Siap</span><strong>{{ readyVariantCount }}</strong></article>
      <article class="metric"><span>Perlu Edit SKU</span><strong>{{ needsSkuVariantCount }}</strong></article>
      <article class="metric"><span>Last Shopee Sync</span><strong>{{ formatDate(summary?.last_shopee_sync_at) }}</strong></article>
      <article class="metric"><span>Last TikTok Sync</span><strong>{{ formatDate(summary?.last_tiktok_sync_at) }}</strong></article>
    </div>
    <p v-if="summary?.auto_hidden_inactive_stock_master" class="notice compact">
      {{ summary.auto_hidden_inactive_stock_master }} stock master lama otomatis disembunyikan karena varian marketplace aktif tidak ditemukan.
    </p>

    <div class="layout">
      <div class="panel list-panel">
        <div class="filter-row">
          <input v-model.trim="filters.search" type="search" placeholder="Cari produk / varian / SKU" @keyup.enter="loadData" />
          <select v-model="filters.status">
            <option value="all">Semua data</option>
            <option value="ready">Siap disinkronkan</option>
            <option value="needs_sku">Perlu edit SKU</option>
            <option value="incomplete">ID marketplace belum lengkap</option>
            <option value="shopee_missing">Belum ada Shopee</option>
            <option value="tiktok_missing">Belum ada TikTok</option>
            <option value="belum_ada_variant">Belum ada dua sisi</option>
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
                <th>Status SKU</th>
              </tr>
            </thead>

            <tbody v-for="group in filteredProductGroups" :key="group.key">
              <tr class="product-row" @click="toggleGroup(group.key, group)">
                <td>
                  <button class="expand" @click.stop="toggleGroup(group.key, group)">{{ isExpanded(group.key) ? '-' : '+' }}</button>
                  <img v-if="group.image_url" :src="group.image_url" class="thumb small" :alt="group.name" />
                  <div v-else class="thumb small fallback">{{ initials(group.name) }}</div>
                  <div>
                    <strong>{{ group.name }}</strong>
                    <small>{{ group.variants.length }} variant dalam grup</small>
                    <small>{{ group.ready_count }} siap, {{ group.needs_sku_count }} perlu edit SKU, {{ group.incomplete_count }} belum lengkap</small>
                  </div>
                </td>
                <td>
                  <strong>{{ group.shopee.product_name || '-' }}</strong>
                  <small>Item ID: {{ group.shopee.item_id || '-' }}</small>
                  <small>{{ group.shopee.present }} variant Shopee aktif</small>
                </td>
                <td>
                  <strong>{{ group.tiktok.product_name || '-' }}</strong>
                  <small>Product ID: {{ group.tiktok.product_id || '-' }}</small>
                  <small>{{ group.tiktok.present }} variant TikTok aktif</small>
                </td>
                <td>
                  <span :class="['badge', group.status]">{{ groupStatusLabel(group.status) }}</span>
                </td>
              </tr>

              <tr
                v-for="item in group.variants"
                v-show="isExpanded(group.key)"
                :key="item.id"
                :class="['variant-row', { active: selectedItem?.id === item.id, 'missing-sku-row': hasMissingRealSku(item) }]"
                @click="selectItem(item)"
              >
                <td>
                  <div class="variant-title">
                    <strong>{{ item.variant_name || 'Tanpa Varian' }}</strong>
                    <small>SKU Internal: {{ displayInternalSku(item) }}</small>
                    <small class="inline-copy">SKU Template: <code>{{ templateSku(item) || '-' }}</code><button type="button" @click.stop="copySkuTemplate(item)" :disabled="!templateSku(item)">Copy</button></small>
                  </div>
                </td>
                <td>
                  <div class="channel-cell">
                    <img v-if="item.shopee?.image_url" :src="item.shopee.image_url" class="thumb" :alt="item.shopee.variant_name" />
                    <div v-else class="thumb fallback">SP</div>
                    <div>
                      <strong>{{ item.shopee?.variant_name || item.variant_name || '-' }}</strong>
                      <small>SKU Real Shopee: {{ channelSku(item, 'shopee') || 'Tidak ada SKU' }}</small>
                      <small>Item ID: {{ item.shopee?.item_id || '-' }}</small>
                      <small>Model ID: {{ item.shopee?.model_id || '-' }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="channel-cell">
                    <img v-if="item.tiktok?.image_url" :src="item.tiktok.image_url" class="thumb" :alt="item.tiktok.variant_name" />
                    <div v-else class="thumb fallback">TT</div>
                    <div>
                      <strong>{{ item.tiktok?.variant_name || item.variant_name || '-' }}</strong>
                      <small>SKU Real TikTok: {{ channelSku(item, 'tiktok') || 'Tidak ada SKU' }}</small>
                      <small>Product ID: {{ item.tiktok?.product_id || '-' }}</small>
                      <small>SKU ID: {{ item.tiktok?.sku_id || '-' }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <span :class="['badge', skuStatus(item)]">{{ skuStatusLabel(skuStatus(item)) }}</span>
                  <small class="status-note">{{ itemStatusLabel(item.status) }}</small>
                  <button
                    v-if="canUpdateMissingSku(item)"
                    type="button"
                    class="row-update-sku"
                    @click.stop="updateMissingSku(item)"
                    :disabled="!canUpdateMissingSku(item) || updatingSkuId === item.stock_master_id"
                  >
                    {{ updatingSkuId === item.stock_master_id ? 'Updating...' : 'Update SKU' }}
                  </button>
                </td>
              </tr>
            </tbody>

            <tbody v-if="!filteredProductGroups.length && !loading">
              <tr>
                <td colspan="4" class="empty">{{ loadError ? 'Data belum bisa dimuat.' : 'Tidak ada data SKU mapping untuk filter ini.' }}</td>
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

        <div class="sku-card">
          <span>SKU Template</span>
          <strong>{{ templateSku(selectedItem) || '-' }}</strong>
          <button class="copy-btn" type="button" @click="copySkuTemplate(selectedItem)" :disabled="!templateSku(selectedItem)">Copy Template</button>
          <small>SKU Internal: {{ displayInternalSku(selectedItem) }}</small>
          <small>Status: {{ skuStatusLabel(skuStatus(selectedItem)) }}</small>
        </div>

        <div class="current-grid">
          <div>
            <span>SKU Real Shopee</span>
            <strong>{{ channelSku(selectedItem, 'shopee') || 'Tidak ada SKU' }}</strong>
          </div>
          <div>
            <span>SKU Real TikTok</span>
            <strong>{{ channelSku(selectedItem, 'tiktok') || 'Tidak ada SKU' }}</strong>
          </div>
        </div>

        <label>
          <span>SKU Target</span>
          <input v-model.trim="form.seller_sku" placeholder="Isi SKU yang akan dikirim ke marketplace" />
        </label>

        <div class="toggle-grid">
          <label class="check-row">
            <input v-model="form.apply_shopee" type="checkbox" :disabled="!hasShopeeIdentity(selectedItem)" />
            <span>Edit Shopee</span>
          </label>
          <label class="check-row">
            <input v-model="form.apply_tiktok" type="checkbox" :disabled="!hasTiktokIdentity(selectedItem)" />
            <span>Edit TikTok</span>
          </label>
          <label class="check-row">
            <input v-model="form.dry_run" type="checkbox" />
            <span>Preview saja</span>
          </label>
        </div>

        <p class="notice compact">{{ selectedHint }}</p>

        <div class="actions">
          <button class="ghost" @click="fillFromSelected">Reset</button>
          <button class="primary" @click="submitSkuUpdate" :disabled="!canSubmitSkuUpdate">
            {{ submitting ? 'Mengirim...' : 'Update SKU' }}
          </button>
        </div>

        <div class="response-grid">
          <section class="response-box">
            <div class="response-head">
              <strong>Shopee Response</strong>
              <div class="response-actions">
                <button class="copy-btn" type="button" @click="copyMarketplaceResponse('shopee')" :disabled="!marketplaceResponse.shopee">Copy</button>
                <span :class="['response-status', marketplaceResponse.shopee?.status || 'idle']">{{ marketplaceResponse.shopee?.status || 'idle' }}</span>
              </div>
            </div>
            <pre>{{ formatJson(marketplaceResponse.shopee || emptyResponse('Shopee')) }}</pre>
          </section>
          <section class="response-box">
            <div class="response-head">
              <strong>TikTok Response</strong>
              <div class="response-actions">
                <button class="copy-btn" type="button" @click="copyMarketplaceResponse('tiktok')" :disabled="!marketplaceResponse.tiktok">Copy</button>
                <span :class="['response-status', marketplaceResponse.tiktok?.status || 'idle']">{{ marketplaceResponse.tiktok?.status || 'idle' }}</span>
              </div>
            </div>
            <pre>{{ formatJson(marketplaceResponse.tiktok || emptyResponse('TikTok')) }}</pre>
          </section>
        </div>
        <p v-if="copyMessage" class="copy-message">{{ copyMessage }}</p>
      </aside>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const submitting = ref(false)
const syncingMarketplaces = ref(false)
const updatingSkuId = ref(null)
const loadError = ref('')
const summary = ref(null)
const items = ref([])
const selectedItem = ref(null)
const expandedGroups = ref({})
const filters = reactive({ search: '', status: 'all', sort: 'updated_desc' })
const form = reactive({
  stock_master_id: null,
  seller_sku: '',
  apply_shopee: true,
  apply_tiktok: true,
  dry_run: false
})
const marketplaceResponse = reactive({
  shopee: null,
  tiktok: null
})
const copyMessage = ref('')

const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '-'
const initials = (name) => String(name || 'SK').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const normalizeSku = (value) => String(value || '').trim()
const sameSku = (left, right) => normalizeSku(left).toLowerCase() === normalizeSku(right).toLowerCase()
const formatJson = (value) => JSON.stringify(value, null, 2)
const emptyResponse = (channel) => ({ message: `Belum ada response ${channel}.` })
const marketplaceSku = (value) => {
  const sku = normalizeSku(value)
  return sku === '-' ? '' : sku
}

const hasShopeeActual = (item) => item?.shopee?.status === 'mapped' && item?.shopee?.source !== 'suggested_product'
const hasTiktokActual = (item) => item?.tiktok?.status === 'mapped' && item?.tiktok?.source !== 'suggested_product'
const hasShopeeIdentity = (item) => Boolean(item?.shopee?.item_id && item?.shopee?.model_id)
const hasTiktokIdentity = (item) => Boolean(item?.tiktok?.product_id && item?.tiktok?.sku_id)
const channelSku = (item, channel) => marketplaceSku(item?.[channel]?.seller_sku)
const matchingMarketplaceSku = (item) => {
  const shopeeSku = channelSku(item, 'shopee')
  const tiktokSku = channelSku(item, 'tiktok')
  return shopeeSku && tiktokSku && sameSku(shopeeSku, tiktokSku) ? shopeeSku : ''
}
const templateSku = (item) => normalizeSku(item?.template_sku) || normalizeSku(item?.internal_sku) || normalizeSku(item?.seller_sku) || matchingMarketplaceSku(item)
const targetSku = (item) => templateSku(item) || matchingMarketplaceSku(item)
const displayInternalSku = (item) => normalizeSku(item?.internal_sku) || '-'
const missingShopeeSku = (item) => hasShopeeIdentity(item) && !channelSku(item, 'shopee')
const missingTiktokSku = (item) => hasTiktokIdentity(item) && !channelSku(item, 'tiktok')
const hasMissingRealSku = (item) => missingShopeeSku(item) || missingTiktokSku(item)
const canUpdateMissingSku = (item) => Boolean(item?.stock_master_id && templateSku(item) && (hasShopeeIdentity(item) || hasTiktokIdentity(item)))

const skuStatus = (item) => {
  const internalSku = normalizeSku(item?.internal_sku)
  const shopeeSku = channelSku(item, 'shopee')
  const tiktokSku = channelSku(item, 'tiktok')

  if (['shopee_missing', 'tiktok_missing', 'belum_ada_variant'].includes(item?.status)) return item.status
  if (!hasShopeeIdentity(item) || !hasTiktokIdentity(item)) return 'incomplete'
  if (matchingMarketplaceSku(item)) return 'ready_to_sync'
  if (!internalSku) return 'internal_empty'
  if (sameSku(shopeeSku, internalSku) && sameSku(tiktokSku, internalSku)) return 'ready_to_sync'
  if (!shopeeSku && !tiktokSku) return 'both_empty'
  if (!shopeeSku) return 'shopee_empty'
  if (!tiktokSku) return 'tiktok_empty'
  return 'mismatch'
}

const skuStatusLabel = (status) => ({
  ready_to_sync: 'Siap disinkronkan',
  both_empty: 'SKU marketplace kosong',
  shopee_empty: 'SKU Shopee kosong',
  tiktok_empty: 'SKU TikTok kosong',
  internal_empty: 'SKU internal kosong',
  incomplete: 'ID belum lengkap',
  shopee_missing: 'Belum ada variant Shopee',
  tiktok_missing: 'Belum ada variant TikTok',
  belum_ada_variant: 'Belum ada variant Shopee/TikTok',
  mismatch: 'SKU belum sama'
}[status] || 'Perlu dicek')

const itemStatusLabel = (status) => ({
  ready_to_sync: 'Shopee dan TikTok terhubung',
  shopee_missing: 'Belum ada variant Shopee',
  tiktok_missing: 'Belum ada variant TikTok',
  belum_ada_variant: 'Belum ada variant Shopee/TikTok'
}[status] || 'Status belum terbaca')

const groupStatusLabel = (status) => ({
  ready_to_sync: 'Siap disinkronkan',
  partially_ready: 'Sebagian siap',
  incomplete: 'ID belum lengkap',
  shopee_missing: 'Belum ada Shopee',
  tiktok_missing: 'Belum ada TikTok',
  belum_ada_variant: 'Belum ada dua sisi',
  needs_sku: 'Perlu edit SKU'
}[status] || 'Perlu edit SKU')

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
        ready_count: 0,
        needs_sku_count: 0,
        incomplete_count: 0,
        shopee_missing_count: 0,
        tiktok_missing_count: 0,
        both_missing_count: 0,
        shopee: { present: 0, item_id: item.shopee?.item_id || '', product_name: item.shopee?.product_name || '' },
        tiktok: { present: 0, product_id: item.tiktok?.product_id || '', product_name: item.tiktok?.product_name || '' },
        status: 'needs_sku'
      })
    }

    const group = groups.get(key)
    const status = skuStatus(item)
    group.variants.push(item)
    if (hasShopeeActual(item)) group.shopee.present += 1
    if (hasTiktokActual(item)) group.tiktok.present += 1
    if (!group.image_url) group.image_url = item.image_url || item.shopee?.image_url || item.tiktok?.image_url || ''
    if (!group.shopee.item_id && item.shopee?.item_id) group.shopee.item_id = item.shopee.item_id
    if (!group.tiktok.product_id && item.tiktok?.product_id) group.tiktok.product_id = item.tiktok.product_id
    if (!group.shopee.product_name && item.shopee?.product_name) group.shopee.product_name = item.shopee.product_name
    if (!group.tiktok.product_name && item.tiktok?.product_name) group.tiktok.product_name = item.tiktok.product_name
    if (status === 'ready_to_sync') group.ready_count += 1
    else group.needs_sku_count += 1
    if (status === 'incomplete') group.incomplete_count += 1
    if (item.status === 'shopee_missing') group.shopee_missing_count += 1
    if (item.status === 'tiktok_missing') group.tiktok_missing_count += 1
    if (item.status === 'belum_ada_variant') group.both_missing_count += 1
  })

  return Array.from(groups.values()).map((group) => {
    const allReady = group.ready_count === group.variants.length
    const hasReady = group.ready_count > 0
    const hasIncomplete = group.incomplete_count > 0
    const allShopeeMissing = group.shopee_missing_count === group.variants.length
    const allTiktokMissing = group.tiktok_missing_count === group.variants.length
    const allBothMissing = group.both_missing_count === group.variants.length
    return {
      ...group,
      status: allReady
        ? 'ready_to_sync'
        : allShopeeMissing
          ? 'shopee_missing'
          : allTiktokMissing
            ? 'tiktok_missing'
            : allBothMissing
              ? 'belum_ada_variant'
              : hasIncomplete
                ? 'incomplete'
                : hasReady
                  ? 'partially_ready'
                  : 'needs_sku'
    }
  })
})

const filteredProductGroups = computed(() => {
  const search = normalizeSku(filters.search).toLowerCase()

  return productGroups.value.filter((group) => {
    const matchesStatus = filters.status === 'all'
      || (filters.status === 'ready' && group.status === 'ready_to_sync')
      || (filters.status === 'needs_sku' && group.status !== 'ready_to_sync' && group.status !== 'incomplete')
      || (filters.status === 'incomplete' && group.status === 'incomplete')
      || (filters.status === 'shopee_missing' && group.shopee_missing_count > 0)
      || (filters.status === 'tiktok_missing' && group.tiktok_missing_count > 0)
      || (filters.status === 'belum_ada_variant' && group.both_missing_count > 0)

    if (!matchesStatus) return false
    if (!search) return true

    const haystack = [
      group.name,
      group.shopee.product_name,
      group.shopee.item_id,
      group.tiktok.product_name,
      group.tiktok.product_id,
      ...group.variants.flatMap((item) => [
        item.variant_name,
        item.internal_sku,
        channelSku(item, 'shopee'),
        channelSku(item, 'tiktok'),
        item.shopee?.model_id,
        item.tiktok?.sku_id
      ])
    ].join(' ').toLowerCase()

    return haystack.includes(search)
  })
})

const readyVariantCount = computed(() => productGroups.value.reduce((total, group) => total + group.ready_count, 0))
const needsSkuVariantCount = computed(() => productGroups.value.reduce((total, group) => total + group.needs_sku_count, 0))

const selectedHint = computed(() => {
  if (!selectedItem.value) return ''
  const hints = []
  if (!hasShopeeIdentity(selectedItem.value)) hints.push('Shopee belum punya Item ID/Model ID lengkap.')
  if (!hasTiktokIdentity(selectedItem.value)) hints.push('TikTok belum punya Product ID/SKU ID lengkap.')
  if (!hints.length) hints.push('Aksi ini hanya mengirim SKU, tidak mengubah stok atau harga.')
  return hints.join(' ')
})

const canSubmitSkuUpdate = computed(() => {
  return Boolean(
    selectedItem.value
    && !submitting.value
    && form.stock_master_id
    && normalizeSku(form.seller_sku)
    && ((form.apply_shopee && hasShopeeIdentity(selectedItem.value)) || (form.apply_tiktok && hasTiktokIdentity(selectedItem.value)))
  )
})

const isExpanded = (key) => expandedGroups.value[key] === true

const toggleGroup = (key, group = null) => {
  expandedGroups.value = {
    ...expandedGroups.value,
    [key]: !isExpanded(key)
  }

  if (group?.variants?.length && !selectedItem.value) {
    selectItem(group.variants[0])
  }
}

const clearResponses = () => {
  marketplaceResponse.shopee = null
  marketplaceResponse.tiktok = null
  copyMessage.value = ''
}

const selectItem = (item, options = {}) => {
  selectedItem.value = item
  form.stock_master_id = item.stock_master_id || (typeof item.id === 'number' ? item.id : null)
  form.seller_sku = targetSku(item) || ''
  form.apply_shopee = hasShopeeIdentity(item)
  form.apply_tiktok = hasTiktokIdentity(item)
  if (options.clearResponses !== false) {
    clearResponses()
  }

  const key = item.group_key || item.shopee?.item_id || item.tiktok?.product_id || item.product_name || item.internal_sku
  if (key) {
    expandedGroups.value = {
      ...expandedGroups.value,
      [key]: true
    }
  }
}

const fillFromSelected = () => {
  if (selectedItem.value) selectItem(selectedItem.value)
}

const loadData = async (options = {}) => {
  loading.value = true
  loadError.value = ''
  const preserveResponses = options?.preserveResponses === true
  const previousStockMasterId = selectedItem.value?.stock_master_id
  const perPage = 5000
  const baseParams = {
    search: filters.search,
    status: 'all',
    sort: filters.sort,
    per_page: perPage,
    compact: 1
  }

  try {
    const firstResponse = await omnichannelService.skuMapping({
      ...baseParams,
      page: 1
    })
    const firstPagination = firstResponse.data.pagination || {}
    const lastPage = Number(firstPagination.last_page || 1)
    const responses = [firstResponse]

    if (lastPage > 1) {
      const remainingResponses = await Promise.all(
        Array.from({ length: lastPage - 1 }, (_, index) => omnichannelService.skuMapping({
          ...baseParams,
          page: index + 2
        }))
      )
      responses.push(...remainingResponses)
    }

    summary.value = firstResponse.data.summary
    items.value = responses.flatMap((response) => response.data.items || [])
    await nextTick()

    const firstGroup = filteredProductGroups.value[0]
    const refreshed = previousStockMasterId
      ? items.value.find((item) => item.stock_master_id === previousStockMasterId)
      : null

    if (refreshed) {
      selectItem(refreshed, { clearResponses: !preserveResponses })
    } else if (firstGroup?.variants?.length) {
      selectItem(firstGroup.variants[0], { clearResponses: !preserveResponses })
    } else {
      selectedItem.value = null
      clearResponses()
    }
  } catch (error) {
    loadError.value = error.response?.data?.message || 'SKU mapping gagal memuat data dari API.'
    items.value = []
    selectedItem.value = null
  } finally {
    loading.value = false
  }
}

const submitSkuUpdate = async () => {
  if (!canSubmitSkuUpdate.value) return

  submitting.value = true
  loadError.value = ''
  marketplaceResponse.shopee = { status: 'pending', message: 'Menunggu response Shopee...' }
  marketplaceResponse.tiktok = { status: 'pending', message: 'Menunggu response TikTok...' }

  try {
    const response = await omnichannelService.updateSkuMappingMarketplaceSku({
      stock_master_id: form.stock_master_id,
      seller_sku: form.seller_sku,
      apply_shopee: form.apply_shopee,
      apply_tiktok: form.apply_tiktok,
      dry_run: form.dry_run
    })

    marketplaceResponse.shopee = response.data?.shopee || null
    marketplaceResponse.tiktok = response.data?.tiktok || null

    if (response.data?.status === 'partial_error') {
      loadError.value = response.data?.message || 'Sebagian request SKU gagal.'
      return
    }

    if (!form.dry_run) {
      await loadData({ preserveResponses: true })
    }
  } catch (error) {
    const data = error.response?.data || { message: error.message || 'Update SKU gagal diproses.' }
    loadError.value = data.message || 'Update SKU gagal diproses.'
    marketplaceResponse.shopee = data.shopee || { status: 'error', response: data }
    marketplaceResponse.tiktok = data.tiktok || { status: 'error', response: data }
  } finally {
    submitting.value = false
  }
}

const updateMissingSku = async (item) => {
  if (!canUpdateMissingSku(item)) return

  updatingSkuId.value = item.stock_master_id
  loadError.value = ''

  try {
    const sellerSku = templateSku(item)
    const applyShopee = hasShopeeIdentity(item)
    const applyTiktok = hasTiktokIdentity(item)
    const response = await omnichannelService.updateSkuMappingMarketplaceSku({
      stock_master_id: item.stock_master_id,
      seller_sku: sellerSku,
      apply_shopee: applyShopee,
      apply_tiktok: applyTiktok,
      dry_run: false
    })

    if (applyShopee && response.data?.shopee?.status === 'ok') {
      item.shopee.seller_sku = sellerSku
    }
    if (applyTiktok && response.data?.tiktok?.status === 'ok') {
      item.tiktok.seller_sku = sellerSku
    }
    if (selectedItem.value?.stock_master_id === item.stock_master_id) {
      selectedItem.value = item
    }

    marketplaceResponse.shopee = response.data?.shopee || null
    marketplaceResponse.tiktok = response.data?.tiktok || null
    copyMessage.value = response.data?.message || 'SKU marketplace berhasil diupdate.'

    if (response.data?.status === 'partial_error') {
      loadError.value = response.data?.message || 'Sebagian request SKU gagal.'
    }
  } catch (error) {
    const data = error.response?.data || { message: error.message || 'Update SKU gagal diproses.' }
    loadError.value = data.message || 'Update SKU gagal diproses.'
    marketplaceResponse.shopee = data.shopee || null
    marketplaceResponse.tiktok = data.tiktok || null
  } finally {
    updatingSkuId.value = null
  }
}

const syncMarketplaces = async () => {
  syncingMarketplaces.value = true
  loadError.value = ''

  try {
    const response = await omnichannelService.syncSkuMappingMarketplaces()
    const result = response.data || {}

    summary.value = {
      ...(summary.value || {}),
      last_shopee_sync_at: result.last_shopee_sync_at,
      last_tiktok_sync_at: result.last_tiktok_sync_at,
      auto_hidden_inactive_stock_master: result.auto_hidden_inactive_stock_master || 0
    }

    if (result.status === 'partial_error') {
      loadError.value = [
        result.shopee?.status === 'error' ? `Shopee: ${result.shopee?.message || 'sync gagal'}` : '',
        result.tiktok?.status === 'error' ? `TikTok: ${result.tiktok?.message || 'sync gagal'}` : ''
      ].filter(Boolean).join(' ')
    }

    await loadData()
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Sync marketplace gagal diproses.'
  } finally {
    syncingMarketplaces.value = false
  }
}

const copyText = async (text, successMessage) => {
  const value = normalizeSku(text)
  if (!value) return

  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(value)
    } else {
      const textarea = document.createElement('textarea')
      textarea.value = value
      textarea.setAttribute('readonly', '')
      textarea.style.position = 'fixed'
      textarea.style.opacity = '0'
      document.body.appendChild(textarea)
      textarea.select()
      document.execCommand('copy')
      document.body.removeChild(textarea)
    }

    copyMessage.value = successMessage
  } catch {
    copyMessage.value = 'Copy gagal. Browser tidak memberi akses clipboard.'
  }
}

const copySkuTemplate = (item) => copyText(templateSku(item), 'SKU template disalin.')

const copyMarketplaceResponse = async (channel) => {
  const payload = marketplaceResponse[channel]
  if (!payload) return

  await copyText(formatJson(payload), `${channel === 'shopee' ? 'Shopee' : 'TikTok'} response disalin.`)
}

onMounted(loadData)
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; }
.page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
.page-header p { color:#64748b; margin-bottom:4px; }
.header-actions { display:flex; gap:10px; flex-wrap:wrap; }
.primary,.ghost,.expand { border:0; border-radius:6px; cursor:pointer; }
.primary,.ghost { padding:10px 14px; }
.primary { background:#0f766e; color:#fff; }
.ghost { background:#fff; border:1px solid #d9e2ec; color:#334155; }
.primary:disabled,.ghost:disabled { cursor:not-allowed; opacity:.64; }
.notice { color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px; }
.notice.error { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
.notice.compact { margin:10px 0 0; }
.summary-grid { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:12px; margin-bottom:12px; }
.metric { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.metric span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.metric strong { font-size:22px; color:#111827; }
.layout { display:grid; grid-template-columns:minmax(0,1fr) 420px; gap:12px; }
.panel { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.filter-row { display:grid; grid-template-columns:1.5fr .8fr .8fr; gap:10px; margin-bottom:12px; }
input,select,textarea { width:100%; border:1px solid #d7dde8; border-radius:6px; padding:10px; font-size:13px; }
.table-wrap { max-height:72vh; overflow:auto; border:1px solid #e5e7eb; border-radius:8px; }
.mapping-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1120px; table-layout:fixed; }
.col-product { width:30%; }
.col-channel { width:27%; }
.col-status { width:16%; }
th,td { border-bottom:1px solid #e5e7eb; padding:10px; vertical-align:top; text-align:left; }
thead th { position:sticky; top:0; background:#1f2937; color:#fff; z-index:1; }
tbody:last-child tr:last-child td { border-bottom:0; }
td small { color:#64748b; display:block; margin-top:3px; }
.product-row { background:#f8fafc; cursor:pointer; }
.product-row td:first-child { display:flex; align-items:center; gap:10px; }
.product-row strong { color:#111827; overflow-wrap:anywhere; }
.variant-row { cursor:pointer; }
.variant-row:hover,.variant-row.active { background:#ecfeff; }
.variant-row.missing-sku-row { background:#fffbeb; }
.variant-row.missing-sku-row:hover,.variant-row.missing-sku-row.active { background:#fef3c7; }
.variant-title strong { display:block; margin-bottom:4px; }
.inline-copy { display:flex; align-items:center; gap:6px; flex-wrap:wrap; }
.inline-copy code { color:#0f172a; font-family:inherit; font-weight:700; overflow-wrap:anywhere; }
.inline-copy button { border:1px solid #d9e2ec; border-radius:5px; background:#fff; color:#334155; cursor:pointer; font-size:11px; font-weight:700; padding:3px 7px; }
.inline-copy button:disabled { cursor:not-allowed; opacity:.55; }
.channel-cell { display:grid; grid-template-columns:58px minmax(0,1fr); gap:10px; align-items:start; min-width:0; }
.channel-cell strong { display:block; line-height:1.25; margin-bottom:4px; overflow-wrap:anywhere; }
.channel-cell small,.variant-title small { overflow-wrap:anywhere; }
.thumb { width:58px; height:58px; border-radius:6px; object-fit:cover; background:#eef2f7; border:1px solid #e2e8f0; }
.thumb.small { width:42px; height:42px; flex:0 0 auto; }
.thumb.large { width:82px; height:82px; }
.fallback { display:grid; place-items:center; color:#64748b; font-weight:800; font-size:12px; }
.expand { width:28px; height:28px; background:#e2e8f0; color:#0f172a; font-weight:800; flex:0 0 auto; }
.badge { display:inline-block; border-radius:999px; padding:4px 8px; font-size:12px; font-weight:700; }
.badge.ready_to_sync { background:#d1fae5; color:#047857; }
.badge.partially_ready { background:#e0f2fe; color:#0369a1; }
.badge.needs_sku,.badge.mismatch { background:#fef3c7; color:#b45309; }
.badge.shopee_missing,.badge.tiktok_missing,.badge.belum_ada_variant { background:#f1f5f9; color:#475569; }
.badge.shopee_empty,.badge.tiktok_empty,.badge.both_empty { background:#ffedd5; color:#c2410c; }
.badge.incomplete,.badge.internal_empty { background:#fee2e2; color:#b91c1c; }
.status-note { margin-top:6px; }
.row-update-sku { display:block; margin-top:8px; border:1px solid #f59e0b; border-radius:5px; background:#fef3c7; color:#92400e; cursor:pointer; font-size:11px; font-weight:800; padding:6px 8px; }
.row-update-sku:disabled { cursor:not-allowed; opacity:.55; }
.detail-panel label { display:block; margin-bottom:10px; }
.detail-panel span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.detail-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
.detail-head strong { display:block; color:#111827; line-height:1.25; overflow-wrap:anywhere; }
.sku-card { border:1px solid #d9e2ec; border-radius:8px; padding:12px; margin-bottom:10px; background:#f8fafc; }
.sku-card strong { display:block; font-size:18px; color:#111827; overflow-wrap:anywhere; }
.sku-card small { color:#64748b; display:block; margin-top:4px; }
.sku-card .copy-btn { margin:8px 0 2px; }
.current-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px; }
.current-grid > div { border:1px solid #e5e7eb; border-radius:8px; padding:10px; min-width:0; }
.current-grid strong { display:block; color:#111827; overflow-wrap:anywhere; }
.toggle-grid { display:grid; grid-template-columns:1fr 1fr 1fr; gap:8px; }
.check-row { display:flex; align-items:center; gap:8px; border:1px solid #e5e7eb; border-radius:6px; padding:9px; margin:0; }
.check-row input { width:auto; }
.check-row span { margin:0; color:#334155; font-size:13px; }
.actions { display:flex; gap:10px; margin:12px 0; }
.response-grid { display:grid; grid-template-columns:1fr; gap:10px; }
.response-box { border:1px solid #d9e2ec; border-radius:8px; overflow:hidden; }
.response-head { display:flex; justify-content:space-between; align-items:center; gap:8px; padding:9px 10px; background:#f8fafc; border-bottom:1px solid #e5e7eb; }
.response-head strong { color:#111827; }
.response-actions { display:flex; align-items:center; gap:8px; }
.copy-btn { border:1px solid #d9e2ec; border-radius:6px; background:#fff; color:#334155; cursor:pointer; font-size:12px; font-weight:700; padding:5px 8px; }
.copy-btn:disabled { cursor:not-allowed; opacity:.55; }
.response-status { border-radius:999px; padding:3px 7px; font-size:11px; font-weight:800; background:#eef2f7; color:#475569; }
.response-status.ok,.response-status.dry_run { background:#d1fae5; color:#047857; }
.response-status.error { background:#fee2e2; color:#b91c1c; }
.response-status.skipped { background:#fef3c7; color:#b45309; }
pre { margin:0; padding:10px; max-height:260px; overflow:auto; background:#0f172a; color:#dbeafe; font-size:12px; line-height:1.5; white-space:pre-wrap; overflow-wrap:anywhere; }
.copy-message { color:#0f766e; font-size:12px; margin:8px 0 0; }
.empty { text-align:center; color:#64748b; padding:20px; }
@media (max-width:1180px) { .layout { grid-template-columns:1fr; } .detail-panel { order:-1; } }
@media (max-width:820px) {
  .page-shell { margin-left:0; padding:16px; }
  .summary-grid,.filter-row,.current-grid,.toggle-grid { grid-template-columns:1fr; }
  .page-header { align-items:flex-start; flex-direction:column; }
}
</style>
