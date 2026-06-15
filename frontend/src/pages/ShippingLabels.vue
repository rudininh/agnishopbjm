<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Cetak Resi</h1>
      </div>
      <button class="primary" type="button" @click="loadOrders(1)" :disabled="loading">
        {{ loading ? 'Memuat...' : 'Refresh Order' }}
      </button>
    </header>

    <p v-if="notice.message" :class="['notice', notice.type]">{{ notice.message }}</p>

    <section class="document-tabs" aria-label="Mode dokumen">
      <button class="active" type="button">Buat Dokumen</button>
      <button type="button">Input No. Resi Massal</button>
    </section>

    <section class="document-panel">
      <div class="filter-stack">
        <div class="filter-line">
          <span class="filter-label">Tipe Pesanan</span>
          <div class="chip-row">
            <button
              v-for="tab in orderTabs"
              :key="tab.key"
              :class="{ active: activeTab === tab.key }"
              type="button"
              @click="setOrderTab(tab)"
            >
              {{ tabLabel(tab) }}
            </button>
          </div>
        </div>

        <div class="filter-line">
          <span class="filter-label">Jasa Kirim</span>
          <div class="chip-row carrier-chips">
            <button :class="{ active: carrierFilter === 'all' }" type="button" @click="carrierFilter = 'all'">
              Semua Jasa Kirim ({{ orders.length }})
            </button>
            <button
              v-for="carrier in carrierOptions"
              :key="carrier.key"
              :class="{ active: carrierFilter === carrier.key }"
              type="button"
              @click="carrierFilter = carrier.key"
            >
              {{ carrier.label }} ({{ carrier.count }})
            </button>
          </div>
        </div>

        <div class="filter-line">
          <span class="filter-label">Status Pesanan</span>
          <div class="chip-row">
            <button
              v-for="status in statusFilters"
              :key="status.key"
              :class="{ active: filters.status === status.key }"
              type="button"
              @click="setStatusFilter(status.key)"
            >
              {{ status.label }} ({{ statusCount(status.key) }})
            </button>
          </div>
        </div>

        <div class="filter-grid">
          <label>
            <span>Status Cetak Dokumen</span>
            <select v-model="appPrintFilter">
              <option value="all">Semua</option>
              <option value="not_printed">Belum Dicetak App</option>
              <option value="printed">Sudah Dicetak App</option>
            </select>
          </label>
          <label>
            <span>Isi Pesanan</span>
            <select disabled>
              <option>Semua</option>
            </select>
          </label>
          <label class="search-filter">
            <span>Produk / No Pesanan</span>
            <input v-model.trim="filters.search" type="search" placeholder="Masukkan no pesanan / pesan log" @keyup.enter="loadOrders(1)" />
          </label>
          <label>
            <span>Metode Pengiriman</span>
            <select v-model="carrierFilter">
              <option value="all">Semua</option>
              <option v-for="carrier in carrierOptions" :key="`select-${carrier.key}`" :value="carrier.key">{{ carrier.label }}</option>
            </select>
          </label>
        </div>

        <div class="filter-actions">
          <button class="outline-danger" type="button" @click="loadOrders(1)">Terapkan</button>
          <button class="ghost" type="button" @click="resetFilters">Atur Ulang</button>
        </div>
      </div>

      <section class="document-actions" aria-label="Opsi dokumen">
        <div class="document-choice">
          <label class="check-row">
            <input v-model="documents.shipping_label" type="checkbox" />
            <span>Label Pengiriman (A6)</span>
          </label>
          <label class="check-row">
            <input v-model="documents.picking_list" type="checkbox" />
            <span>Daftar Pengemasan (A6)</span>
          </label>
        </div>

        <div class="print-mode">
          <span>Mode Cetak</span>
          <label><input v-model="printMode" type="radio" value="thermal" /> Thermal</label>
          <label><input v-model="printMode" type="radio" value="normal" /> Normal</label>
        </div>

        <div class="official-options">
          <label>
            <span>Ukuran Resmi</span>
            <select v-model="officialDocumentSize">
              <option value="A6">A6</option>
              <option value="A5">A5</option>
              <option value="A4">A4</option>
            </select>
          </label>
          <label class="watermark-option">
            <span><input v-model="officialWatermarkEnabled" type="checkbox" /> Watermark</span>
            <input v-model.trim="officialWatermarkText" :disabled="!officialWatermarkEnabled" maxlength="80" />
          </label>
        </div>

        <div class="print-buttons">
          <strong>{{ selectedCount }} order dipilih</strong>
          <button class="primary" type="button" @click="printSelected" :disabled="!canPrint || printing">
            {{ printing ? 'Menyiapkan...' : 'Cetak Label Terpilih' }}
          </button>
          <button class="ghost" type="button" @click="printOfficialSelected" :disabled="!canPrint || officialPrinting">
            {{ officialPrinting ? 'Mengambil dokumen...' : 'Cetak Dokumen Resmi' }}
          </button>
        </div>
      </section>

      <div v-if="previewLabel" class="preview-card">
        <span>Preview terakhir</span>
        <strong>{{ marketplaceLabel(previewLabel.marketplace) }} {{ previewLabel.order_ref }}</strong>
        <small>{{ previewLabel.buyer_name || '-' }}</small>
        <small>{{ previewLabel.shipping_carrier || '-' }} {{ previewLabel.tracking_number || '' }}</small>
      </div>

      <div class="table-head">
        <div>
          <strong>{{ displayedOrders.length }} Paket</strong>
          <span>({{ pagination.total || 0 }} Pesanan)</span>
        </div>
        <button class="sort-button" type="button" disabled>Urutkan: Batas Pengiriman (Terlama ke Terbaru)</button>
      </div>

      <div class="table-shell">
        <table class="order-table">
          <thead>
            <tr>
              <th class="check-col">
                <input type="checkbox" :checked="allPageSelected" :disabled="!displayedOrders.length" @click.stop @change="toggleAllPage" />
              </th>
              <th>Produk</th>
              <th>No. Pesanan/No. Reservasi</th>
              <th>Pembeli</th>
              <th>Jasa Kirim</th>
              <th>No. Resi</th>
              <th>Waktu Pesanan Siap Dikirim</th>
              <th>Telah dicetak</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="order in displayedOrders"
              :key="orderKey(order)"
              :class="{ selected: isSelected(order) }"
              tabindex="0"
              @click="toggleOrder(order)"
              @keydown.enter.prevent="toggleOrder(order)"
              @keydown.space.prevent="toggleOrder(order)"
            >
              <td class="check-col">
                <input
                  type="checkbox"
                  :checked="isSelected(order)"
                  :aria-label="`Pilih order ${order.order_ref}`"
                  @click.stop
                  @change="toggleOrder(order, $event.target.checked)"
                />
              </td>
              <td class="product-cell">
                <div class="product-preview">
                  <img v-if="primaryItem(order)?.image_url" :src="primaryItem(order).image_url" alt="" loading="lazy" />
                  <div v-else class="item-image-empty">IMG</div>
                  <div>
                    <strong>{{ primaryItem(order)?.name || '-' }}</strong>
                    <small>{{ primaryItem(order)?.variant || '-' }} | {{ primaryItem(order)?.sku || '-' }}</small>
                    <small v-if="moreItemCount(order)">+{{ moreItemCount(order) }} item lain</small>
                  </div>
                </div>
              </td>
              <td class="order-ref-cell">
                <strong>{{ order.order_ref }}</strong>
                <small>{{ marketplaceLabel(order.marketplace) }}</small>
              </td>
              <td>{{ buyerName(order) }}</td>
              <td>
                <strong>{{ order.shipping_carrier || '-' }}</strong>
                <small>{{ shippingServiceName(order) }}</small>
              </td>
              <td class="tracking-cell">{{ order.tracking_number || '-' }}</td>
              <td>{{ formatCompactDate(order.created_at) }}</td>
              <td class="printed-cell">
                <div class="printed-list">
                  <span><i :class="{ on: (order.app_print_count || 0) > 0 }"></i> Label Pengiriman</span>
                  <span><i :class="{ on: (order.app_print_count || 0) > 0 }"></i> Daftar Pesanan</span>
                  <span><i :class="{ on: (order.app_print_count || 0) > 0 }"></i> Daftar Pesanan per Produk</span>
                </div>
                <button class="mini" type="button" @click.stop="previewOrder(order)" :disabled="previewingKey === orderKey(order)">
                  {{ previewingKey === orderKey(order) ? 'Preview...' : 'Preview' }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <div v-if="!displayedOrders.length" class="empty">
          {{ loading ? 'Sedang memuat order belum dikirim...' : 'Belum ada resi belum dikirim untuk filter ini.' }}
        </div>
      </div>

      <div class="pagination">
        <button class="ghost" type="button" :disabled="pagination.page <= 1" @click="loadOrders(pagination.page - 1)">Prev</button>
        <span>Halaman {{ pagination.page || 1 }} / {{ pagination.last_page || 1 }} | {{ pagination.total || 0 }} data</span>
        <button class="ghost" type="button" :disabled="pagination.page >= pagination.last_page" @click="loadOrders(pagination.page + 1)">Next</button>
      </div>

    </section>

    <section class="summary-grid">
      <article><span>Belum Dikirim</span><strong>{{ summary.total || 0 }}</strong></article>
      <article><span>Shopee</span><strong>{{ summary.shopee || 0 }}</strong></article>
      <article><span>TikTok</span><strong>{{ summary.tiktok || 0 }}</strong></article>
      <article><span>Siap Cetak</span><strong>{{ summary.total || 0 }}</strong></article>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const loading = ref(false)
const printing = ref(false)
const officialPrinting = ref(false)
const previewingKey = ref('')
const orders = ref([])
const summary = ref({})
const pagination = ref({ page: 1, last_page: 1, total: 0 })
const selected = ref({})
const selectedOrderSnapshots = ref({})
const detailCache = ref({})
const previewLabel = ref(null)
const printMode = ref('thermal')
const activeTab = ref('all')
const appPrintFilter = ref('all')
const carrierFilter = ref('all')
const officialDocumentSize = ref('A6')
const officialWatermarkEnabled = ref(true)
const officialWatermarkText = ref('WAJIB VIDEO UNBOXING')
const notice = ref({ type: '', message: '' })
const documents = reactive({ shipping_label: true, picking_list: true })
const filters = reactive({ marketplace: 'all', status: 'all', search: '', mode: 'regular' })
let pdfJsLoader = null

const orderTabs = [
  { key: 'all', label: 'Semua', marketplace: 'all', mode: 'regular' },
  { key: 'shopee', label: 'Shopee', marketplace: 'shopee', mode: 'regular' },
  { key: 'tiktok', label: 'TikTok', marketplace: 'tiktok', mode: 'regular' },
  { key: 'shopee_instant', label: 'Instant Shopee', marketplace: 'shopee', mode: 'shopee_instant' }
]

const statusFilters = [
  { key: 'all', label: 'Semua' },
  { key: 'success', label: 'Telah Diproses' },
  { key: 'skipped', label: 'Perlu Diproses' },
  { key: 'error', label: 'Error' }
]

const orderKey = (order) => `${order.marketplace}:${order.order_ref}`
const selectedKeys = computed(() => Object.keys(selected.value).filter((key) => selected.value[key]))
const selectedCount = computed(() => selectedKeys.value.length)
const canPrint = computed(() => selectedCount.value > 0 && (documents.shipping_label || documents.picking_list))
const allPageSelected = computed(() => displayedOrders.value.length > 0 && displayedOrders.value.every((order) => selected.value[orderKey(order)]))
const officialTiktokDocumentType = computed(() => {
  if (documents.shipping_label && documents.picking_list) return 'SHIPPING_LABEL_AND_PACKING_SLIP'
  if (documents.picking_list) return 'PACKING_SLIP'
  return 'SHIPPING_LABEL'
})

const setNotice = (type, message) => {
  notice.value = { type, message }
}

const marketplaceLabel = (value) => value === 'tiktok' ? 'TikTok' : 'Shopee'
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '-'
const formatCompactDate = (value) => value
  ? new Intl.DateTimeFormat('id-ID', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false
  }).format(new Date(value)).replace(/\./g, ':')
  : '-'
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))
const carrierKey = (value) => (String(value || '').trim() || 'Tanpa Jasa Kirim').toLowerCase()
const primaryItem = (order) => (order.items || [])[0] || null
const moreItemCount = (order) => Math.max((order.items || []).length - 1, 0)
const buyerName = (order) => order.buyer_name || detailCache.value[orderKey(order)]?.buyer_name || '-'
const shippingServiceName = (order) => order.marketplace_logistics_status || order.print_status || order.order_status || '-'
const marketplacePrintClass = (order) => {
  const text = `${order.print_status || ''} ${order.order_status || ''} ${order.marketplace_logistics_status || ''}`.toLowerCase()
  if (text.includes('sudah dikirim') || text.includes('shipped') || text.includes('pickup_done') || text.includes('deliver')) return 'sent'
  if (text.includes('ready') || text.includes('processed') || text.includes('logistics_ready')) return 'ready'
  return 'not-printed'
}

const carrierOptions = computed(() => {
  const counts = new Map()
  orders.value.forEach((order) => {
    const label = String(order.shipping_carrier || 'Tanpa Jasa Kirim').trim() || 'Tanpa Jasa Kirim'
    const key = carrierKey(label)
    const current = counts.get(key) || { key, label, count: 0 }
    current.count += 1
    counts.set(key, current)
  })

  return Array.from(counts.values()).sort((left, right) => right.count - left.count || left.label.localeCompare(right.label))
})

const displayedOrders = computed(() => orders.value.filter((order) => {
  if (carrierFilter.value !== 'all' && carrierKey(order.shipping_carrier) !== carrierFilter.value) return false
  if (appPrintFilter.value === 'printed' && (order.app_print_count || 0) <= 0) return false
  if (appPrintFilter.value === 'not_printed' && (order.app_print_count || 0) > 0) return false
  return true
}))

const tabLabel = (tab) => {
  const counts = {
    all: summary.value.total || 0,
    shopee: summary.value.shopee || 0,
    tiktok: summary.value.tiktok || 0,
    shopee_instant: activeTab.value === 'shopee_instant' ? (summary.value.total || 0) : 0
  }
  return `${tab.label} (${counts[tab.key] ?? 0})`
}

const statusCount = (status) => {
  if (status === 'all') return summary.value.total || orders.value.length
  if (status === 'success') return summary.value.success || orders.value.filter((order) => order.status === 'success').length
  return orders.value.filter((order) => order.status === status).length
}

const setStatusFilter = (status) => {
  filters.status = status
  loadOrders(1)
}

const resetFilters = () => {
  activeTab.value = 'all'
  filters.marketplace = 'all'
  filters.mode = 'regular'
  filters.status = 'all'
  filters.search = ''
  appPrintFilter.value = 'all'
  carrierFilter.value = 'all'
  selected.value = {}
  selectedOrderSnapshots.value = {}
  loadOrders(1)
}

const setOrderTab = (tab) => {
  activeTab.value = tab.key
  filters.marketplace = tab.marketplace
  filters.mode = tab.mode
  selected.value = {}
  selectedOrderSnapshots.value = {}
  carrierFilter.value = 'all'
  loadOrders(1)
}

const mapWithConcurrency = async (items, limit, mapper) => {
  const results = new Array(items.length)
  let index = 0
  const workers = Array.from({ length: Math.min(limit, items.length) }, async () => {
    while (index < items.length) {
      const currentIndex = index
      index += 1
      results[currentIndex] = await mapper(items[currentIndex], currentIndex)
    }
  })
  await Promise.all(workers)
  return results
}

const syncSelectedSnapshots = () => {
  if (!selectedKeys.value.length) return

  const next = { ...selectedOrderSnapshots.value }
  orders.value.forEach((order) => {
    const key = orderKey(order)
    if (selected.value[key]) next[key] = order
  })
  selectedOrderSnapshots.value = next
}

const loadOrders = async (page = pagination.value.page || 1) => {
  loading.value = true
  setNotice('', '')
  try {
    const { data } = await omnichannelService.shippingLabelOrders({ ...filters, page, per_page: 20 })
    orders.value = data.items || []
    summary.value = data.summary || {}
    pagination.value = data.pagination || pagination.value
    syncSelectedSnapshots()
    if (carrierFilter.value !== 'all' && !carrierOptions.value.some((carrier) => carrier.key === carrierFilter.value)) {
      carrierFilter.value = 'all'
    }
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Data cetak resi gagal dimuat.')
  } finally {
    loading.value = false
  }
}

const isSelected = (order) => Boolean(selected.value[orderKey(order)])
const toggleOrder = (order, checked = null) => {
  const key = orderKey(order)
  const nextChecked = checked === null ? !selected.value[key] : Boolean(checked)
  const nextSelected = { ...selected.value, [key]: nextChecked }
  const nextSnapshots = { ...selectedOrderSnapshots.value }

  if (nextChecked) {
    nextSnapshots[key] = order
  } else {
    delete nextSelected[key]
    delete nextSnapshots[key]
  }

  selected.value = nextSelected
  selectedOrderSnapshots.value = nextSnapshots
}

const toggleAllPage = () => {
  const next = { ...selected.value }
  const nextSnapshots = { ...selectedOrderSnapshots.value }
  const shouldSelect = !allPageSelected.value

  displayedOrders.value.forEach((order) => {
    const key = orderKey(order)
    if (shouldSelect) {
      next[key] = true
      nextSnapshots[key] = order
    } else {
      delete next[key]
      delete nextSnapshots[key]
    }
  })
  selected.value = next
  selectedOrderSnapshots.value = nextSnapshots
}

const fetchDetail = async (order) => {
  const key = orderKey(order)
  if (detailCache.value[key]) return detailCache.value[key]

  const { data } = await omnichannelService.shippingLabelOrderDetail({
    marketplace: order.marketplace,
    order_ref: order.order_ref
  })
  detailCache.value = { ...detailCache.value, [key]: data.label }
  return data.label
}

const previewOrder = async (order) => {
  previewingKey.value = orderKey(order)
  setNotice('', '')
  try {
    previewLabel.value = await fetchDetail(order)
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Detail order belum bisa diambil.')
  } finally {
    previewingKey.value = ''
  }
}

const selectedOrders = () => {
  const currentOrders = new Map(orders.value.map((order) => [orderKey(order), order]))
  return selectedKeys.value.map((key) => selectedOrderSnapshots.value[key] || currentOrders.get(key)).filter(Boolean)
}

const mergePrintStatuses = (items = []) => {
  if (!items.length) return

  const statusMap = new Map(items.map((item) => [orderKey(item), item]))
  orders.value = orders.value.map((order) => {
    const printed = statusMap.get(orderKey(order))
    return printed ? { ...order, ...printed } : order
  })
  selectedOrderSnapshots.value = Object.fromEntries(Object.entries(selectedOrderSnapshots.value).map(([key, order]) => {
    const printed = statusMap.get(key)
    return [key, printed ? { ...order, ...printed } : order]
  }))
}

const markPrinted = async (rows, documentType, source) => {
  if (!rows.length) return

  try {
    const { data } = await omnichannelService.markShippingLabelsPrinted({
      items: rows.map((order) => ({ marketplace: order.marketplace, order_ref: order.order_ref })),
      document_type: documentType,
      source
    })
    mergePrintStatuses(data.items || [])
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Status print gagal dicatat.')
  }
}

const labelHtml = (label, index) => {
  const items = (label.items || []).map((item, index) => `
    <tr>
      <td>${index + 1}</td>
      <td>${escapeHtml(item.name)}</td>
      <td>${item.image_url ? `<img class="item-thumb" src="${escapeHtml(item.image_url)}" />` : ''}</td>
      <td>${escapeHtml(item.sku)}</td>
      <td>${escapeHtml(item.variant)}</td>
      <td>${escapeHtml(item.qty)}</td>
    </tr>
  `).join('')
  const totalQty = (label.items || []).reduce((total, item) => total + Number(item.qty || 0), 0)
  const shippingBlock = documents.shipping_label ? `
    <div class="tracking-block">
      <span>No. Resi</span>
      <strong>${escapeHtml(label.tracking_number || label.order_ref)}</strong>
    </div>
    <div class="address-grid">
      <section>
        <span class="section-title">Penerima</span>
        <strong>${escapeHtml(label.buyer_name || '-')}</strong>
        <p>${escapeHtml(label.buyer_address || '-')}</p>
        <small>${escapeHtml(label.buyer_phone || '')}</small>
      </section>
      <section>
        <span class="section-title">Pengirim</span>
        <strong>${escapeHtml(label.sender_name || 'Agni Shop Banjarmasin')}</strong>
        <p>KOTA BANJARMASIN</p>
      </section>
    </div>
  ` : ''
  const pickingBlock = documents.picking_list ? `
    <div class="order-meta">
      <span>No Pesanan: ${escapeHtml(label.order_ref)}</span>
      <span>Total Qty: ${escapeHtml(totalQty || '-')}</span>
      <span>Status: ${escapeHtml(label.order_status || '-')}</span>
    </div>
    <table>
      <thead><tr><th>#</th><th>Produk</th><th>Gambar</th><th>SKU</th><th>Varian</th><th>Qty</th></tr></thead>
      <tbody>${items || '<tr><td colspan="6">Tidak ada item.</td></tr>'}</tbody>
    </table>
  ` : ''

  return `
    <section class="print-page">
      <article class="label">
        <header class="label-header">
          <div>
            <strong>${escapeHtml(marketplaceLabel(label.marketplace))}</strong>
            <span>${escapeHtml(label.shipping_carrier || '-')}</span>
          </div>
          <div class="page-mark">Resi ${index + 1}</div>
        </header>
        ${shippingBlock}
        ${pickingBlock}
      </article>
    </section>
  `
}

const waitForPopupAssets = (popup) => new Promise((resolve) => {
  const finish = () => {
    const images = Array.from(popup.document.images || [])
    let pending = images.filter((image) => !image.complete).length
    let settled = false
    const done = () => {
      if (settled) return
      settled = true
      const schedule = popup.requestAnimationFrame ? popup.requestAnimationFrame.bind(popup) : ((callback) => setTimeout(callback, 16))
      schedule(() => setTimeout(resolve, 150))
    }

    if (!pending) {
      done()
      return
    }

    const timer = setTimeout(done, 3000)
    images.forEach((image) => {
      if (image.complete) return
      const settle = () => {
        pending -= 1
        if (pending <= 0) {
          clearTimeout(timer)
          done()
        }
      }
      image.addEventListener('load', settle, { once: true })
      image.addEventListener('error', settle, { once: true })
    })
  }

  if (popup.document.readyState === 'complete') {
    finish()
  } else {
    popup.addEventListener('load', finish, { once: true })
  }
})

const printSelected = async () => {
  const rows = selectedOrders()
  if (!rows.length) {
    setNotice('error', 'Pilih order pada halaman ini dulu.')
    return
  }

  printing.value = true
  setNotice('', '')
  try {
    const labels = await mapWithConcurrency(rows, 5, fetchDetail)

    const popup = window.open('', '_blank', 'width=900,height=900')
    if (!popup) throw new Error('Popup diblokir browser. Izinkan pop-up untuk mencetak label.')

    popup.document.write(`<!doctype html><html><head><title>Cetak Resi</title><style>
      @page { size: ${printMode.value === 'thermal' ? '101.6mm 154mm' : 'A4'}; margin: 0; }
      * { box-sizing:border-box; }
      html, body { margin:0; padding:0; background:#e5e7eb; font-family:Arial,sans-serif; color:#111827; }
      .print-page { width:${printMode.value === 'thermal' ? '101.6mm' : '210mm'}; min-height:${printMode.value === 'thermal' ? '154mm' : '297mm'}; margin:${printMode.value === 'thermal' ? '0 auto' : '12px auto'}; padding:${printMode.value === 'thermal' ? '4mm' : '10mm'}; background:#fff; break-after:page; page-break-after:always; }
      .print-page:last-child { break-after:auto; page-break-after:auto; }
      .label { width:100%; min-height:${printMode.value === 'thermal' ? '146mm' : '277mm'}; border:1.5px solid #111827; background:#fff; display:flex; flex-direction:column; }
      .label-header { display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #111827; padding:7px 9px; }
      .label-header strong { font-size:18px; letter-spacing:0; }
      .label-header span,.page-mark { color:#374151; font-size:11px; font-weight:700; }
      .tracking-block { padding:10px 9px; border-bottom:1px solid #111827; text-align:center; }
      .tracking-block span,.section-title { display:block; color:#374151; font-size:10px; font-weight:800; text-transform:uppercase; }
      .tracking-block strong { display:block; margin-top:4px; font-family:'Courier New',monospace; font-size:19px; letter-spacing:1px; overflow-wrap:anywhere; }
      .address-grid { display:grid; grid-template-columns:1.4fr 1fr; border-bottom:1px solid #111827; }
      .address-grid section { padding:8px 9px; min-height:34mm; }
      .address-grid section + section { border-left:1px solid #111827; }
      strong { display:block; font-size:13px; line-height:1.25; overflow-wrap:anywhere; }
      p { margin:4px 0; font-size:12px; line-height:1.3; overflow-wrap:anywhere; }
      small { color:#374151; font-size:11px; }
      .order-meta { display:grid; grid-template-columns:1.4fr .7fr 1fr; gap:6px; padding:7px 9px; border-bottom:1px solid #111827; color:#374151; font-size:10.5px; font-weight:700; }
      table { width:100%; border-collapse:collapse; font-size:10px; }
      th,td { border-top:1px solid #d1d5db; padding:4px; text-align:left; vertical-align:top; overflow-wrap:anywhere; }
      th { background:#f3f4f6; font-size:9px; text-transform:uppercase; }
      .item-thumb { width:34px; height:34px; object-fit:cover; border:1px solid #d1d5db; display:block; }
      @media print { html, body { background:#fff; } .print-page { margin:0; } }
    </style></head><body>${labels.map(labelHtml).join('')}</body></html>`)
    popup.document.close()
    await waitForPopupAssets(popup)
    popup.focus()
    popup.print()
    await markPrinted(rows, documents.picking_list ? 'local_label_with_picking_list' : 'local_label', 'custom_label')
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Label gagal disiapkan.')
  } finally {
    printing.value = false
  }
}

const documentUrl = (document) => {
  if (!document) return ''
  if (document.content_base64) return `data:${document.mime_type || 'application/pdf'};base64,${document.content_base64}`
  if (document.url) return document.url
  return ''
}

const base64ToBytes = (base64) => {
  const clean = String(base64 || '').replace(/^data:[^;]+;base64,/, '')
  const binary = atob(clean)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index)
  }
  return bytes
}

const loadPdfJs = async () => {
  if (!pdfJsLoader) {
    pdfJsLoader = Promise.all([
      import('pdfjs-dist'),
      import('pdfjs-dist/build/pdf.worker.mjs?url')
    ]).then(([pdfjs, worker]) => {
      pdfjs.GlobalWorkerOptions.workerSrc = worker.default
      return pdfjs
    })
  }

  return pdfJsLoader
}

const drawCanvasStamp = (ctx, width, centerY, text) => {
  const stampText = String(text || 'WAJIB VIDEO UNBOXING').toUpperCase()
  const drawStamp = (centerY, fontSize, lineWidth) => {
    ctx.save()
    ctx.globalAlpha = 1
    ctx.font = `700 ${fontSize}px Arial, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.strokeStyle = '#000'
    ctx.fillStyle = '#000'
    ctx.lineWidth = lineWidth
    const metrics = ctx.measureText(stampText)
    const stampWidth = Math.min(width * 0.88, Math.max(width * 0.48, metrics.width + fontSize * 1.2))
    const stampHeight = fontSize * 1.65
    const x = (width - stampWidth) / 2
    const y = centerY - (stampHeight / 2)
    ctx.strokeRect(x, y, stampWidth, stampHeight)
    ctx.fillText(stampText, width / 2, centerY)
    ctx.restore()
  }

  drawStamp(centerY, Math.max(26, Math.min(42, width * 0.052)), Math.max(2, width * 0.004))
}

const renderWatermarkedPdfImages = async (marketplaceDocument, watermarkText, stampWatermark = true) => {
  if (!marketplaceDocument?.content_base64) return []

  const pdfjs = await loadPdfJs()
  const pdf = await pdfjs.getDocument({ data: base64ToBytes(marketplaceDocument.content_base64) }).promise
  const images = []
  const renderScale = 2.25
  const halfCentimeterInCanvasPixels = Math.round((72 / 2.54) * 0.5 * renderScale)
  for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
    const page = await pdf.getPage(pageNumber)
    const viewport = page.getViewport({ scale: renderScale })
    const canvas = window.document.createElement('canvas')
    const context = canvas.getContext('2d')
    const renderedWidth = Math.floor(viewport.width)
    const renderedHeight = Math.floor(viewport.height)
    canvas.width = renderedWidth
    canvas.height = renderedHeight
    context.fillStyle = '#fff'
    context.fillRect(0, 0, canvas.width, canvas.height)
    await page.render({ canvasContext: context, viewport }).promise
    if (stampWatermark) {
      const watermarkCenterY = renderedHeight - Math.max(20, Math.floor(renderedWidth * 0.028)) - halfCentimeterInCanvasPixels
      drawCanvasStamp(context, canvas.width, watermarkCenterY, watermarkText)
    }
    images.push(canvas.toDataURL('image/png'))
  }
  return images
}

const officialDocumentHtml = async ({ order, data }) => {
  const title = `${marketplaceLabel(order.marketplace)} ${escapeHtml(order.order_ref)}`
  if (data.status === 'pending') {
    return `<section class="doc"><h2>${title}</h2><p>${escapeHtml(data.message || 'Dokumen masih diproses marketplace.')}</p></section>`
  }
  if (data.status !== 'success') {
    return `<section class="doc error"><h2>${title}</h2><p>${escapeHtml(data.message || 'Dokumen resmi gagal diambil.')}</p></section>`
  }

  const url = documentUrl(data.document)
  if (!url) {
    return `<section class="doc error"><h2>${title}</h2><p>Dokumen kosong.</p></section>`
  }

  const shouldRenderFallbackWatermark = data.document?.watermark_error
    && data.document?.content_base64

  const canRenderPdfPages = data.document?.content_base64
    && String(data.document?.mime_type || 'application/pdf').includes('pdf')

  if (canRenderPdfPages) {
    try {
      const pages = await renderWatermarkedPdfImages(data.document, officialWatermarkText.value, shouldRenderFallbackWatermark)
      if (pages.length) {
        const watermarkNote = shouldRenderFallbackWatermark
          ? '<p class="watermark-ok">Watermark aktif di hasil render cetak.</p>'
          : ''
        return `<section class="doc rendered-doc"><h2>${title}</h2>${watermarkNote}<p><a href="${escapeHtml(url)}" target="_blank" rel="noopener">Buka / Download PDF</a></p><div class="rendered-pages">${pages.map((src) => `<div class="official-page"><img class="rendered-page" src="${src}" /></div>`).join('')}</div></section>`
      }
    } catch (error) {
      if (shouldRenderFallbackWatermark) {
        return `<section class="doc error"><h2>${title}</h2><p>Watermark gagal dirender: ${escapeHtml(error?.message || 'PDF tidak bisa dirender.')}</p></section>`
      }
    }
  }

  const watermarkInfo = data.document?.watermark
    ? `<p class="watermark-ok">Watermark aktif: ${escapeHtml(data.document.watermark.text || '')}</p>`
    : (data.document?.watermark_error ? `<p class="watermark-warning">Watermark belum terpasang: ${escapeHtml(data.document.watermark_error)}</p>` : '')

  return `<section class="doc"><h2>${title}</h2>${watermarkInfo}<p><a href="${escapeHtml(url)}" target="_blank" rel="noopener">Buka / Download Dokumen</a></p><iframe src="${escapeHtml(url)}"></iframe></section>`
}

const printOfficialSelected = async () => {
  const rows = selectedOrders()
  if (!rows.length) {
    setNotice('error', 'Pilih order pada halaman ini dulu.')
    return
  }
  if (!documents.shipping_label && !documents.picking_list) {
    setNotice('error', 'Pilih Label Pengiriman atau Daftar Pengemasan dulu.')
    return
  }

  const popup = window.open('', '_blank', 'width=1000,height=900')
  if (!popup) {
    setNotice('error', 'Popup diblokir browser. Izinkan pop-up untuk membuka dokumen resmi.')
    return
  }

  officialPrinting.value = true
  setNotice('', '')
  popup.document.write('<!doctype html><html><head><title>Dokumen Resmi Marketplace</title><style>body{font-family:Arial,sans-serif;margin:18px;background:#f3f4f6;color:#111827}.doc{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:12px;margin-bottom:14px}iframe,object{width:100%;height:760px;border:1px solid #e5e7eb}a{color:#0f5fc7;font-weight:700}.error{color:#991b1b;background:#fef2f2;border-color:#fecaca}</style></head><body><h1>Menyiapkan dokumen resmi...</h1></body></html>')
  popup.document.close()

  const results = await mapWithConcurrency(rows, 3, async (order) => {
    try {
      const { data } = await omnichannelService.shippingLabelOfficialDocument({
        marketplace: order.marketplace,
        order_ref: order.order_ref,
        document_size: officialDocumentSize.value,
        document_type: order.marketplace === 'tiktok' ? officialTiktokDocumentType.value : undefined,
        document_format: 'PDF',
        watermark_enabled: officialWatermarkEnabled.value,
        watermark_text: officialWatermarkText.value
      })
      return { order, data }
    } catch (error) {
      const message = error?.response?.data?.message || error?.message || 'Dokumen resmi gagal diambil.'
      return {
        order,
        data: {
          status: 'error',
          message
        }
      }
    }
  })

  const html = (await Promise.all(results.map(officialDocumentHtml))).join('')

  popup.document.open()
  popup.document.write(`<!doctype html><html><head><title>Dokumen Resmi Marketplace</title><style>
    @page{size:101.6mm 154mm;margin:0}
    *{box-sizing:border-box}
    body{font-family:Arial,sans-serif;margin:18px;background:#f3f4f6;color:#111827}
    .doc{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:12px;margin-bottom:14px}
    iframe{width:100%;height:760px;border:1px solid #e5e7eb;background:#fff}
    a{color:#0f5fc7;font-weight:700}
    .error{color:#991b1b;background:#fef2f2;border-color:#fecaca}
    .watermark-ok{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 10px}
    .watermark-warning{color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px 10px}
    .rendered-pages{display:grid;gap:12px}
    .official-page{background:#fff;margin:0 auto;max-width:760px;box-shadow:0 1px 4px rgba(15,23,42,.18)}
    .rendered-page{display:block;width:100%;height:auto}
    h1{font-size:22px}h2{font-size:16px;margin:0 0 8px}
    @media print{
      html,body{width:101.6mm;background:#fff;margin:0}
      body>h1,.doc>h2,.doc>p,.watermark-ok,.watermark-warning{display:none}
      .doc{border:0;border-radius:0;padding:0;margin:0;background:#fff}
      .doc:not(.rendered-doc){width:101.6mm;height:154mm;break-after:page;page-break-after:always;overflow:hidden}
      .doc:not(.rendered-doc) iframe{width:101.6mm;height:154mm;border:0}
      .rendered-pages{display:block;gap:0}
      .official-page{width:101.6mm;height:154mm;max-width:none;margin:0;box-shadow:none;break-after:page;page-break-after:always;overflow:hidden}
      .official-page:last-child{break-after:auto;page-break-after:auto}
      .rendered-page{width:101.6mm;height:154mm;object-fit:fill}
    }
  </style></head><body><h1>Dokumen Resmi Marketplace</h1>${html}</body></html>`)
  popup.document.close()
  await waitForPopupAssets(popup)
  popup.focus()
  popup.print()
  await markPrinted(results.filter((result) => result.data.status === 'success').map((result) => result.order), officialTiktokDocumentType.value, 'official_document')
  setNotice(results.some((result) => result.data.status !== 'success') ? 'error' : 'success', `${results.length} dokumen resmi selesai diproses. Bila PDF tidak langsung tampil, klik Buka / Download Dokumen.`)
  officialPrinting.value = false
}

onMounted(() => loadOrders(1))
</script>

<style scoped>
.page-shell { margin-left:240px; padding:0 24px 24px; color:#111827; background:#f5f5f5; min-height:100vh; }
.page-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; padding:22px 0 14px; }
.page-header p { color:#6b7280; font-size:12px; font-weight:700; text-transform:uppercase; }
.page-header h1 { font-size:26px; line-height:1.15; margin-top:4px; }
button { border:0; border-radius:6px; padding:9px 13px; font-weight:700; cursor:pointer; white-space:nowrap; }
button:disabled { opacity:.6; cursor:not-allowed; }
.primary { background:#ee4d2d; color:#fff; }
.ghost { background:#fff; color:#111827; border:1px solid #d9dde5; }
.outline-danger { background:#fff; color:#ee4d2d; border:1px solid #ee4d2d; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:12px; }
.notice.error { border:1px solid #fecaca; background:#fef2f2; color:#991b1b; }
.notice.success { border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.document-tabs { display:flex; gap:22px; background:#fff; border:1px solid #e5e7eb; border-bottom:0; padding:0 24px; }
.document-tabs button { border-radius:0; background:transparent; color:#111827; padding:16px 0 13px; border-bottom:3px solid transparent; }
.document-tabs button.active { color:#ee4d2d; border-bottom-color:#ee4d2d; }
.document-panel { background:#fff; border:1px solid #e5e7eb; padding:20px 24px 22px; }
.filter-stack { display:grid; gap:13px; }
.filter-line { display:grid; grid-template-columns:120px minmax(0,1fr); gap:12px; align-items:start; }
.filter-label { font-size:12px; color:#111827; padding-top:8px; }
.chip-row { display:flex; flex-wrap:wrap; gap:8px; }
.chip-row button { background:#fff; border:1px solid #d9dde5; color:#111827; border-radius:999px; padding:7px 14px; font-size:12px; font-weight:500; }
.chip-row button.active { border-color:#ee4d2d; color:#ee4d2d; background:#fff; }
.carrier-chips button { max-width:240px; overflow:hidden; text-overflow:ellipsis; }
.filter-grid { display:grid; grid-template-columns:1fr 1fr; gap:12px 14px; }
.filter-grid label,.official-options label { min-width:0; }
.filter-grid span,.official-options label > span { display:block; color:#6b7280; font-size:12px; margin-bottom:6px; }
.search-filter { display:grid; grid-template-columns:110px minmax(0,1fr); align-items:end; }
.search-filter span { margin:0; align-self:center; color:#111827; }
select,input { width:100%; min-width:0; border:1px solid #d9dde5; border-radius:4px; padding:9px 10px; background:#fff; color:#111827; }
.filter-actions { display:flex; gap:10px; }
.document-actions { display:grid; grid-template-columns:minmax(220px,1fr) 220px minmax(300px,1.1fr) minmax(260px,.9fr); gap:14px; align-items:stretch; border:1px solid #e5e7eb; background:#fafafa; padding:14px; margin:18px 0; }
.document-choice { display:grid; align-content:center; gap:8px; }
.check-row { display:flex; gap:8px; align-items:center; margin:0; font-weight:700; font-size:13px; }
.check-row input,.print-mode input,.watermark-option input[type='checkbox'] { width:auto; }
.print-mode { display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#fff; border:1px solid #e5e7eb; border-radius:4px; padding:10px; margin:0; }
.print-mode span { grid-column:1 / -1; color:#6b7280; font-size:12px; font-weight:800; }
.print-mode label { display:flex; gap:7px; align-items:center; font-size:13px; }
.official-options { display:grid; grid-template-columns:120px minmax(0,1fr); gap:10px; }
.official-options .watermark-option { min-width:0; }
.watermark-option span { display:flex; align-items:center; gap:7px; color:#111827; font-weight:700; }
.print-buttons { display:grid; grid-template-columns:1fr; gap:8px; align-content:center; }
.print-buttons strong { color:#111827; font-size:13px; }
.preview-card { border:1px solid #e5e7eb; border-radius:4px; padding:10px 12px; margin-bottom:14px; display:flex; flex-wrap:wrap; gap:8px 14px; align-items:center; background:#f9fafb; }
.preview-card span,.preview-card small { color:#6b7280; font-size:12px; }
.preview-card strong { overflow-wrap:anywhere; }
.table-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin:18px 0 12px; }
.table-head strong { font-size:16px; }
.table-head span { color:#6b7280; font-size:13px; margin-left:4px; }
.sort-button { background:#fff; color:#9ca3af; border:1px solid #e5e7eb; font-weight:500; font-size:12px; }
.table-shell { border:1px solid #e5e7eb; overflow:auto; background:#fff; }
.order-table { width:100%; min-width:1080px; border-collapse:collapse; font-size:12px; }
.order-table th { background:#f8f8f8; color:#4b5563; font-weight:500; text-align:left; padding:13px 10px; border-bottom:1px solid #e5e7eb; }
.order-table td { padding:13px 10px; border-bottom:1px solid #e5e7eb; vertical-align:top; }
.order-table tbody tr { cursor:pointer; transition:background .15s ease; }
.order-table tbody tr:hover { background:#fff7f5; }
.order-table tbody tr.selected { background:#fff4f0; box-shadow:inset 3px 0 0 #ee4d2d; }
.order-table tbody tr:focus { outline:2px solid #f59e8b; outline-offset:-2px; }
.check-col { width:38px; text-align:center; }
.check-col input { width:14px; height:14px; }
.product-cell { min-width:230px; max-width:320px; }
.product-preview { display:grid; grid-template-columns:64px minmax(0,1fr); gap:10px; align-items:start; }
.product-preview img,.item-image-empty { width:64px; height:64px; border-radius:2px; object-fit:cover; background:#e5e7eb; border:1px solid #d1d5db; }
.item-image-empty { display:grid; place-items:center; color:#6b7280; font-size:10px; font-weight:800; }
.product-preview strong { display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; font-size:12px; line-height:1.35; }
.product-preview small,.order-ref-cell small,.order-table td small { display:block; color:#6b7280; font-size:11px; line-height:1.35; margin-top:3px; overflow-wrap:anywhere; }
.order-ref-cell strong,.tracking-cell { color:#00a98f; font-weight:800; overflow-wrap:anywhere; }
.printed-cell { min-width:190px; }
.printed-list { display:grid; gap:6px; margin-bottom:8px; }
.printed-list span { display:flex; align-items:center; gap:7px; line-height:1.2; }
.printed-list i { width:9px; height:9px; border-radius:50%; background:#d1d5db; display:inline-block; }
.printed-list i.on { background:#10b981; }
.mini { background:#0f5fc7; color:#fff; padding:6px 9px; font-size:12px; }
.pagination { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:12px; color:#4b5563; font-size:13px; }
.summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-top:14px; }
.summary-grid article { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:14px; }
.summary-grid span { display:block; color:#6b7280; font-size:12px; margin-bottom:6px; }
.summary-grid strong { font-size:20px; }
.empty { color:#6b7280; text-align:center; padding:26px; border-top:1px dashed #d1d5db; }
@media (max-width:1200px) {
  .document-actions { grid-template-columns:1fr 1fr; }
}
@media (max-width:960px) {
  .page-shell { margin-left:0; padding:0 14px 18px; }
  .document-tabs,.document-panel { padding-left:14px; padding-right:14px; }
  .filter-line,.filter-grid,.summary-grid,.document-actions,.official-options,.search-filter { grid-template-columns:1fr; }
  .filter-label { padding-top:0; }
  .table-head { align-items:flex-start; flex-direction:column; }
}
</style>
