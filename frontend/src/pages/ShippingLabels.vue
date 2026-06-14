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

    <section class="summary-grid">
      <article><span>Belum Dikirim</span><strong>{{ summary.total || 0 }}</strong></article>
      <article><span>Shopee</span><strong>{{ summary.shopee || 0 }}</strong></article>
      <article><span>TikTok</span><strong>{{ summary.tiktok || 0 }}</strong></article>
      <article><span>Siap Cetak</span><strong>{{ summary.total || 0 }}</strong></article>
    </section>

    <div class="workspace">
      <section class="panel list-panel">
        <div class="filter-stack">
          <div class="chip-row">
            <button
              v-for="tab in orderTabs"
              :key="tab.key"
              :class="{ active: activeTab === tab.key }"
              type="button"
              @click="setOrderTab(tab)"
            >
              {{ tab.label }}
            </button>
          </div>
          <div class="filter-row">
            <select v-model="filters.status" @change="loadOrders(1)">
              <option value="all">Semua status</option>
              <option value="success">Success</option>
              <option value="skipped">Skipped</option>
              <option value="error">Error</option>
            </select>
            <input v-model.trim="filters.search" type="search" placeholder="Cari no pesanan / pesan log" @keyup.enter="loadOrders(1)" />
            <button class="ghost" type="button" @click="loadOrders(1)">Terapkan</button>
          </div>
        </div>

        <div class="table-head">
          <strong>{{ selectedCount }} order dipilih</strong>
          <button class="ghost" type="button" @click="toggleAllPage" :disabled="!orders.length">
            {{ allPageSelected ? 'Batalkan Halaman Ini' : 'Pilih Halaman Ini' }}
          </button>
        </div>

        <div class="order-list">
          <article v-for="order in orders" :key="orderKey(order)" :class="['order-row', { selected: isSelected(order) }]">
            <label class="check-cell">
              <input type="checkbox" :checked="isSelected(order)" @change="toggleOrder(order)" />
            </label>
            <div class="order-main">
              <span :class="['market-badge', order.marketplace]">{{ marketplaceLabel(order.marketplace) }}</span>
              <strong>{{ order.order_ref }}</strong>
              <small>{{ formatDate(order.created_at) }} | {{ order.order_status || '-' }} | {{ order.shipping_carrier || '-' }} {{ order.tracking_number || '' }}</small>
              <small>Status marketplace: {{ order.print_status || order.order_status || '-' }}<template v-if="order.marketplace_logistics_status"> | {{ order.marketplace_logistics_status }}</template></small>
              <small>{{ order.app_print_status || 'Belum print dari aplikasi' }}<template v-if="order.app_last_printed_at"> | Terakhir {{ formatDate(order.app_last_printed_at) }}</template></small>
              <div v-if="(order.items || []).length" class="ordered-items">
                <div v-for="(item, index) in order.items.slice(0, 3)" :key="`${orderKey(order)}:${index}`" class="ordered-item">
                  <img v-if="item.image_url" :src="item.image_url" alt="" loading="lazy" />
                  <div v-else class="item-image-empty">IMG</div>
                  <div>
                    <strong>{{ item.name || '-' }}</strong>
                    <small>{{ item.variant || '-' }} | {{ item.sku || '-' }} | Qty {{ item.qty || 1 }}</small>
                  </div>
                </div>
                <small v-if="order.items.length > 3">+{{ order.items.length - 3 }} item lain</small>
              </div>
              <small>{{ order.message || '-' }}</small>
            </div>
            <div class="order-actions">
              <span :class="['status-badge', order.status]">{{ order.status }}</span>
              <span :class="['print-badge', marketplacePrintClass(order)]">{{ order.print_status || order.order_status || '-' }}</span>
              <span :class="['app-print-badge', (order.app_print_count || 0) > 0 ? 'printed' : 'not-printed']">{{ order.app_print_status || 'Belum print app' }}</span>
              <span class="order-status">{{ order.order_status || '-' }}</span>
              <button class="mini" type="button" @click="previewOrder(order)" :disabled="previewingKey === orderKey(order)">
                {{ previewingKey === orderKey(order) ? 'Preview...' : 'Preview' }}
              </button>
            </div>
          </article>
          <div v-if="!orders.length" class="empty">{{ loading ? 'Sedang memuat order belum dikirim...' : 'Belum ada resi belum dikirim untuk filter ini.' }}</div>
        </div>

        <div class="pagination">
          <button class="ghost" type="button" :disabled="pagination.page <= 1" @click="loadOrders(pagination.page - 1)">Prev</button>
          <span>Halaman {{ pagination.page || 1 }} / {{ pagination.last_page || 1 }} | {{ pagination.total || 0 }} data</span>
          <button class="ghost" type="button" :disabled="pagination.page >= pagination.last_page" @click="loadOrders(pagination.page + 1)">Next</button>
        </div>
      </section>

      <aside class="panel print-panel">
        <h2>Buat Dokumen Pengiriman</h2>
        <p>{{ selectedCount }} order dipilih, pilih dokumen yang akan dicetak.</p>

        <label class="check-row">
          <input v-model="documents.shipping_label" type="checkbox" />
          <span>Label Pengiriman (A6)</span>
        </label>
        <label class="check-row">
          <input v-model="documents.picking_list" type="checkbox" />
          <span>Daftar Pengemasan (A6)</span>
        </label>

        <div class="print-mode">
          <span>Mode Cetak</span>
          <label><input v-model="printMode" type="radio" value="thermal" /> Thermal</label>
          <label><input v-model="printMode" type="radio" value="normal" /> Normal</label>
        </div>

        <div class="official-options">
          <label>
            Ukuran Resmi
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

        <div v-if="previewLabel" class="preview-card">
          <span>Preview terakhir</span>
          <strong>{{ marketplaceLabel(previewLabel.marketplace) }} {{ previewLabel.order_ref }}</strong>
          <small>{{ previewLabel.buyer_name || '-' }}</small>
          <small>{{ previewLabel.shipping_carrier || '-' }} {{ previewLabel.tracking_number || '' }}</small>
          <div v-if="(previewLabel.items || []).length" class="preview-items">
            <div v-for="(item, index) in previewLabel.items" :key="`preview:${index}`" class="ordered-item">
              <img v-if="item.image_url" :src="item.image_url" alt="" loading="lazy" />
              <div v-else class="item-image-empty">IMG</div>
              <div>
                <strong>{{ item.name || '-' }}</strong>
                <small>{{ item.variant || '-' }} | {{ item.sku || '-' }} | Qty {{ item.qty || 1 }}</small>
              </div>
            </div>
          </div>
        </div>

        <button class="primary full" type="button" @click="printSelected" :disabled="!canPrint || printing">
          {{ printing ? 'Menyiapkan...' : 'Cetak Label Terpilih' }}
        </button>
        <button class="ghost full" type="button" @click="printOfficialSelected" :disabled="!canPrint || officialPrinting">
          {{ officialPrinting ? 'Mengambil dokumen...' : 'Cetak Dokumen Resmi' }}
        </button>
        <p class="hint">Tabel hanya menampilkan resi yang belum dikirim. Dokumen resmi mengikuti aturan marketplace.</p>
      </aside>
    </div>
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
const detailCache = ref({})
const previewLabel = ref(null)
const printMode = ref('thermal')
const activeTab = ref('all')
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

const orderKey = (order) => `${order.marketplace}:${order.order_ref}`
const selectedKeys = computed(() => Object.keys(selected.value).filter((key) => selected.value[key]))
const selectedCount = computed(() => selectedKeys.value.length)
const canPrint = computed(() => selectedCount.value > 0 && (documents.shipping_label || documents.picking_list))
const allPageSelected = computed(() => orders.value.length > 0 && orders.value.every((order) => selected.value[orderKey(order)]))
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
const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]))
const marketplacePrintClass = (order) => {
  const text = `${order.print_status || ''} ${order.order_status || ''} ${order.marketplace_logistics_status || ''}`.toLowerCase()
  if (text.includes('sudah dikirim') || text.includes('shipped') || text.includes('pickup_done') || text.includes('deliver')) return 'sent'
  if (text.includes('ready') || text.includes('processed') || text.includes('logistics_ready')) return 'ready'
  return 'not-printed'
}

const setFilter = (key, value) => {
  filters[key] = value
  loadOrders(1)
}

const setOrderTab = (tab) => {
  activeTab.value = tab.key
  filters.marketplace = tab.marketplace
  filters.mode = tab.mode
  selected.value = {}
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

const loadOrders = async (page = pagination.value.page || 1) => {
  loading.value = true
  setNotice('', '')
  try {
    const { data } = await omnichannelService.shippingLabelOrders({ ...filters, page, per_page: 20 })
    orders.value = data.items || []
    summary.value = data.summary || {}
    pagination.value = data.pagination || pagination.value
  } catch (error) {
    setNotice('error', error?.response?.data?.message || error?.message || 'Data cetak resi gagal dimuat.')
  } finally {
    loading.value = false
  }
}

const isSelected = (order) => Boolean(selected.value[orderKey(order)])
const toggleOrder = (order) => {
  const key = orderKey(order)
  selected.value = { ...selected.value, [key]: !selected.value[key] }
}

const toggleAllPage = () => {
  const next = { ...selected.value }
  orders.value.forEach((order) => {
    next[orderKey(order)] = !allPageSelected.value
  })
  selected.value = next
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
  return selectedKeys.value.map((key) => currentOrders.get(key)).filter(Boolean)
}

const mergePrintStatuses = (items = []) => {
  if (!items.length) return

  const statusMap = new Map(items.map((item) => [orderKey(item), item]))
  orders.value = orders.value.map((order) => {
    const printed = statusMap.get(orderKey(order))
    return printed ? { ...order, ...printed } : order
  })
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

const labelHtml = (label) => {
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

  return `
    <section class="label">
      <header>
        <strong>${escapeHtml(marketplaceLabel(label.marketplace))}</strong>
        <span>${escapeHtml(label.shipping_carrier || '-')}</span>
      </header>
      <div class="barcode">${escapeHtml(label.tracking_number || label.order_ref)}</div>
      <div class="split">
        <div><span>Penerima</span><strong>${escapeHtml(label.buyer_name || '-')}</strong><p>${escapeHtml(label.buyer_address || '-')}</p><small>${escapeHtml(label.buyer_phone || '')}</small></div>
        <div><span>Pengirim</span><strong>${escapeHtml(label.sender_name || 'Agni Shop Banjarmasin')}</strong><p>KOTA BANJARMASIN</p></div>
      </div>
      <div class="meta"><span>No Pesanan: ${escapeHtml(label.order_ref)}</span><span>Status: ${escapeHtml(label.order_status || '-')}</span></div>
      ${documents.picking_list ? `<table><thead><tr><th>#</th><th>Produk</th><th>Gambar</th><th>SKU</th><th>Varian</th><th>Qty</th></tr></thead><tbody>${items}</tbody></table>` : ''}
    </section>
  `
}

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
      @page { size: ${printMode.value === 'thermal' ? '100mm 150mm' : 'A4'}; margin: 6mm; }
      body { margin:0; background:#e5e7eb; font-family:Arial,sans-serif; color:#111827; }
      .label { width:${printMode.value === 'thermal' ? '96mm' : '180mm'}; min-height:${printMode.value === 'thermal' ? '135mm' : '120mm'}; background:#fff; border:1px solid #111827; margin:10px auto; padding:8px; page-break-after:always; box-sizing:border-box; }
      header { display:flex; justify-content:space-between; align-items:center; border-bottom:2px solid #111827; padding-bottom:5px; font-size:16px; }
      .barcode { border:1px solid #111827; margin:8px 0; padding:10px; text-align:center; font-family:'Courier New',monospace; font-size:18px; font-weight:800; letter-spacing:1px; }
      .split { display:grid; grid-template-columns:1fr 1fr; gap:8px; border-bottom:1px dashed #111827; padding-bottom:8px; }
      span, small { color:#374151; font-size:11px; } strong { display:block; font-size:13px; } p { margin:4px 0; font-size:12px; line-height:1.25; }
      .meta { display:flex; justify-content:space-between; gap:8px; margin:7px 0; font-size:11px; }
      table { width:100%; border-collapse:collapse; font-size:10px; } th,td { border-top:1px solid #d1d5db; padding:3px; text-align:left; vertical-align:top; } .item-thumb { width:34px; height:34px; object-fit:cover; border:1px solid #d1d5db; }
      @media print { body { background:#fff; } .label { margin:0; border:1px solid #111827; } }
    </style></head><body>${labels.map(labelHtml).join('')}</body></html>`)
    popup.document.close()
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

const renderWatermarkedPdfImages = async (marketplaceDocument, watermarkText) => {
  if (!marketplaceDocument?.content_base64) return []

  const pdfjs = await loadPdfJs()
  const pdf = await pdfjs.getDocument({ data: base64ToBytes(marketplaceDocument.content_base64) }).promise
  const images = []
  for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
    const page = await pdf.getPage(pageNumber)
    const viewport = page.getViewport({ scale: 2.25 })
    const canvas = window.document.createElement('canvas')
    const context = canvas.getContext('2d')
    const renderedWidth = Math.floor(viewport.width)
    const renderedHeight = Math.floor(viewport.height)
    canvas.width = renderedWidth
    canvas.height = renderedHeight
    context.fillStyle = '#fff'
    context.fillRect(0, 0, canvas.width, canvas.height)
    await page.render({ canvasContext: context, viewport }).promise
    drawCanvasStamp(context, canvas.width, renderedHeight * 0.92, watermarkText)
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

  if (shouldRenderFallbackWatermark) {
    try {
      const pages = await renderWatermarkedPdfImages(data.document, officialWatermarkText.value)
      if (pages.length) {
        return `<section class="doc rendered-doc"><h2>${title}</h2><p class="watermark-ok">Watermark aktif di hasil render cetak. PDF asli memakai kompresi TikTok, jadi watermark digambar di halaman yang sama.</p><button class="print-now" type="button" onclick="window.print()">Print Watermark</button><div class="rendered-pages">${pages.map((src) => `<img class="rendered-page" src="${src}" />`).join('')}</div></section>`
      }
    } catch (error) {
      return `<section class="doc error"><h2>${title}</h2><p>Watermark gagal dirender: ${escapeHtml(error?.message || 'PDF tidak bisa dirender.')}</p></section>`
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
  popup.document.write(`<!doctype html><html><head><title>Dokumen Resmi Marketplace</title><style>@page{size:${officialDocumentSize.value};margin:0}body{font-family:Arial,sans-serif;margin:18px;background:#f3f4f6;color:#111827}.doc{background:#fff;border:1px solid #d1d5db;border-radius:8px;padding:12px;margin-bottom:14px}iframe{width:100%;height:760px;border:1px solid #e5e7eb;background:#fff}a{color:#0f5fc7;font-weight:700}.error{color:#991b1b;background:#fef2f2;border-color:#fecaca}.watermark-ok{color:#166534;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:6px;padding:8px 10px}.watermark-warning{color:#9a3412;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px 10px}.print-now{border:0;border-radius:6px;background:#0f5fc7;color:#fff;font-weight:800;padding:9px 13px;margin:0 0 10px}.rendered-pages{display:grid;gap:12px}.rendered-page{display:block;width:100%;max-width:760px;margin:0 auto;background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.18)}h1{font-size:22px}h2{font-size:16px;margin:0 0 8px}@media print{body{background:#fff;margin:0}.doc{page-break-after:always;border:0;padding:0;margin:0}.doc:not(.rendered-doc) iframe{height:100vh;border:0}.rendered-pages{display:block}.rendered-page{width:100%;max-width:none;margin:0;box-shadow:none;page-break-after:always}.watermark-ok,.watermark-warning,h1,h2,p a,.print-now{display:none}}</style></head><body><h1>Dokumen Resmi Marketplace</h1>${html}</body></html>`)
  popup.document.close()
  await markPrinted(results.filter((result) => result.data.status === 'success').map((result) => result.order), officialTiktokDocumentType.value, 'official_document')
  setNotice(results.some((result) => result.data.status !== 'success') ? 'error' : 'success', `${results.length} dokumen resmi selesai diproses. Bila PDF tidak langsung tampil, klik Buka / Download Dokumen.`)
  officialPrinting.value = false
}

onMounted(() => loadOrders(1))
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; color:#0f172a; }
.page-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
.page-header p { color:#64748b; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
.page-header h1 { font-size:28px; line-height:1.15; margin-top:4px; }
button { border:0; border-radius:6px; padding:9px 13px; font-weight:700; cursor:pointer; }
button:disabled { opacity:.6; cursor:not-allowed; }
.primary { background:#ef4b36; color:#fff; }
.ghost { background:#fff; color:#0f172a; border:1px solid #dbe3ef; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:14px; }
.notice.error { border:1px solid #fecaca; background:#fef2f2; color:#991b1b; }
.notice.success { border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.summary-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:10px; margin-bottom:14px; }
.summary-grid article,.panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); }
.summary-grid article { padding:14px; }
.summary-grid span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.summary-grid strong { font-size:20px; }
.workspace { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:14px; align-items:start; }
.panel { padding:14px; }
.filter-stack { display:grid; gap:10px; margin-bottom:12px; }
.chip-row { display:flex; flex-wrap:wrap; gap:8px; }
.chip-row button { background:#fff; border:1px solid #dbe3ef; color:#334155; padding:8px 14px; }
.chip-row button.active { border-color:#ef4b36; color:#ef4b36; background:#fff7f5; }
.filter-row { display:grid; grid-template-columns:180px 1fr 120px; gap:10px; }
select,input { width:100%; border:1px solid #cbd5e1; border-radius:6px; padding:9px 10px; background:#fff; }
.table-head { display:flex; justify-content:space-between; align-items:center; gap:10px; margin-bottom:10px; }
.order-list { display:grid; gap:8px; }
.order-row { display:grid; grid-template-columns:30px minmax(0,1fr) auto; gap:12px; align-items:center; border:1px solid #e2e8f0; border-radius:6px; padding:10px; }
.order-row.selected { border-color:#ef4b36; background:#fff7f5; }
.check-cell { display:grid; place-items:center; }
.order-main { min-width:0; }
.order-main strong,.order-main small { display:block; }
.order-main strong { margin:4px 0; overflow-wrap:anywhere; }
.order-main small { color:#64748b; line-height:1.35; }
.ordered-items,.preview-items { display:grid; gap:6px; margin:8px 0; }
.ordered-item { display:grid; grid-template-columns:42px minmax(0,1fr); gap:8px; align-items:center; border:1px solid #e2e8f0; border-radius:6px; padding:6px; background:#f8fafc; }
.ordered-item img,.item-image-empty { width:42px; height:42px; border-radius:5px; object-fit:cover; background:#e2e8f0; border:1px solid #cbd5e1; }
.item-image-empty { display:grid; place-items:center; color:#64748b; font-size:10px; font-weight:800; }
.ordered-item strong { font-size:12px; margin:0 0 2px; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; overflow:hidden; }
.ordered-item small { font-size:11px; overflow-wrap:anywhere; }
.order-actions { display:grid; gap:7px; justify-items:end; }
.market-badge,.status-badge,.print-badge,.app-print-badge { display:inline-flex; border-radius:999px; padding:3px 8px; font-size:11px; font-weight:800; text-transform:uppercase; }
.market-badge.shopee { background:#fff1ed; color:#c2410c; }
.market-badge.tiktok { background:#111827; color:#fff; }
.status-badge.success { background:#dcfce7; color:#166534; }
.status-badge.error { background:#fee2e2; color:#991b1b; }
.status-badge.skipped { background:#e2e8f0; color:#475569; }
.print-badge.not-printed { background:#f8fafc; color:#64748b; border:1px solid #cbd5e1; }
.print-badge.ready { background:#dbeafe; color:#1d4ed8; }
.print-badge.sent { background:#dcfce7; color:#166534; }
.app-print-badge.not-printed { background:#f8fafc; color:#64748b; border:1px solid #cbd5e1; }
.app-print-badge.printed { background:#fef3c7; color:#92400e; }
.order-status { display:inline-flex; border-radius:999px; padding:3px 8px; font-size:11px; font-weight:800; background:#eef2ff; color:#3730a3; }
.mini { background:#0f5fc7; color:#fff; padding:7px 9px; font-size:12px; }
.pagination { display:flex; justify-content:flex-end; align-items:center; gap:10px; margin-top:12px; color:#475569; font-size:13px; }
.print-panel h2 { font-size:18px; margin-bottom:8px; }
.print-panel p { color:#64748b; line-height:1.45; }
.check-row { display:flex; gap:9px; align-items:center; margin:12px 0; font-weight:700; }
.print-mode { display:grid; grid-template-columns:1fr 1fr; gap:8px; background:#f8fafc; border-radius:6px; padding:10px; margin:14px 0; }
.print-mode span { grid-column:1 / -1; color:#64748b; font-size:12px; font-weight:800; }
.print-mode label { display:flex; gap:7px; align-items:center; }
.official-options { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin:14px 0; }
.official-options label { display:grid; gap:6px; color:#64748b; font-size:12px; font-weight:800; }
.official-options .watermark-option { grid-column:1 / -1; }
.watermark-option span { display:flex; align-items:center; gap:7px; color:#0f172a; }
.preview-card { border:1px solid #e2e8f0; border-radius:6px; padding:11px; margin:12px 0; display:grid; gap:4px; }
.preview-card span,.preview-card small { color:#64748b; font-size:12px; }
.preview-card strong { overflow-wrap:anywhere; }
.full { width:100%; margin-top:8px; }
.hint { font-size:12px; margin-top:12px; }
.empty { color:#64748b; text-align:center; padding:26px; border:1px dashed #cbd5e1; border-radius:6px; }
@media (max-width:960px) { .page-shell { margin-left:0; padding:16px; } .workspace,.summary-grid,.filter-row { grid-template-columns:1fr; } .order-row { grid-template-columns:30px minmax(0,1fr); } .order-actions { grid-column:2; justify-items:start; } }
</style>
