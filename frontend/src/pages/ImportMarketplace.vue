<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Import Marketplace</h1>
      </div>
    </header>

    <p v-if="notice.message" :class="['notice', notice.type]">{{ notice.message }}</p>

    <section class="stock-sync-panel">
      <div class="panel-head">
        <div>
          <h2>Sinkron Stok Manual</h2>
          <p>Pakai stok dari marketplace lain, lalu push ke Shopee dan TikTok utama lewat auto-sync.</p>
        </div>
        <button class="secondary" type="button" @click="loadProducts" :disabled="loadingProducts">
          {{ loadingProducts ? 'Memuat...' : 'Refresh Produk' }}
        </button>
      </div>

      <div class="sync-layout">
        <section class="catalog-panel">
          <div class="sync-toolbar">
            <label>
              Sumber stok
              <select v-model="sourceMarketplace">
                <option value="Lazada Agni Shop Banjarmasin">Lazada Agni Shop Banjarmasin</option>
                <option value="Blibli Agni Shop Banjarmasin">Blibli Agni Shop Banjarmasin</option>
                <option value="Shopee Gitashopcollection">Shopee Gitashopcollection</option>
                <option value="Marketplace Lain">Marketplace Lain</option>
              </select>
            </label>
            <label>
              Cari produk / varian / SKU
              <input v-model.trim="stockSearch" type="search" placeholder="Cari seperti POS" />
            </label>
          </div>

          <div class="product-list">
            <article v-for="group in groupedProducts" :key="group.key" class="product-row">
              <div class="product-main">
                <img v-if="group.imageUrl" :src="group.imageUrl" alt="" loading="lazy" />
                <div v-else class="image-empty">{{ productInitial(group.productName) }}</div>
                <div>
                  <strong>{{ group.productName }}</strong>
                  <small>{{ group.variants.length }} varian | stok master {{ group.totalStock }}</small>
                </div>
              </div>

              <div class="variant-list">
                <div v-for="variant in group.variants" :key="variant.stock_master_id" class="variant-row">
                  <div>
                    <strong>{{ variant.variant_name || 'Default' }}</strong>
                    <small>{{ variant.sku || '-' }} | Master {{ variant.stock }} | Shopee {{ variant.shopee_stock ?? '-' }} | TikTok {{ variant.tiktok_stock ?? '-' }}</small>
                  </div>
                  <button class="mini" type="button" @click="addSyncItem(variant)">Pilih</button>
                </div>
              </div>
            </article>
            <p v-if="!groupedProducts.length" class="empty">{{ loadingProducts ? 'Memuat produk...' : 'Produk tidak ditemukan.' }}</p>
          </div>
        </section>

        <aside class="sync-cart">
          <h2>{{ selectedItems.length }} varian dipilih</h2>
          <p>Isi stok acuan dari {{ sourceMarketplace }}.</p>

          <div class="selected-list">
            <article v-for="item in selectedItems" :key="item.stock_master_id" class="selected-item">
              <div>
                <strong>{{ item.product_name }}</strong>
                <small>{{ item.variant_name || 'Default' }} | {{ item.sku || '-' }}</small>
              </div>
              <label>
                Stok
                <input v-model.number="item.stock" type="number" min="0" step="1" />
              </label>
              <button class="danger" type="button" @click="removeSyncItem(item.stock_master_id)">Hapus</button>
            </article>
            <p v-if="!selectedItems.length" class="empty">Pilih varian produk dari daftar kiri.</p>
          </div>

          <button class="primary full" type="button" :disabled="!canSubmitStockSync || syncingStock" @click="submitStockSync">
            {{ syncingStock ? 'Menyinkronkan...' : 'Submit Sinkron ke Auto Sync' }}
          </button>
        </aside>
      </div>

      <div v-if="syncResults.length" class="result-panel">
        <h3>Hasil sinkron terakhir</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Produk</th>
                <th>Stok</th>
                <th>Shopee</th>
                <th>TikTok</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in syncResults" :key="`${row.stock_master_id}:${row.new_stock}`">
                <td>
                  <strong>{{ row.product_name || row.sku }}</strong>
                  <span>{{ row.variant_name || '-' }}</span>
                </td>
                <td>{{ row.old_stock }} -> {{ row.new_stock }}</td>
                <td>{{ row.shopee?.status || '-' }}<span>{{ row.shopee?.message || '' }}</span></td>
                <td>{{ row.tiktok?.status || '-' }}<span>{{ row.tiktok?.message || '' }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>

    <section class="market-grid">
      <article
        v-for="marketplace in marketplaces"
        :key="marketplace.key"
        :class="['market-card', marketplace.key]"
      >
        <div class="card-top">
          <div class="market-logo" v-html="marketplace.logo"></div>
          <span>Manual</span>
        </div>

        <div class="market-body">
          <h2>{{ marketplace.name }}</h2>
          <strong>{{ marketplace.account }}</strong>
        </div>

        <dl>
          <div>
            <dt>Akun</dt>
            <dd>{{ marketplace.account }}</dd>
          </div>
          <div>
            <dt>Format</dt>
            <dd>Mass Update</dd>
          </div>
        </dl>

        <button
          class="primary"
          type="button"
          :disabled="downloadingKey === marketplace.key"
          @click="downloadMassUpdate(marketplace)"
        >
          {{ downloadingKey === marketplace.key ? 'Menyiapkan...' : 'Download Mass Update' }}
        </button>
      </article>
    </section>

    <section class="download-panel">
      <div class="panel-head">
        <div>
          <h2>Download Mass Update Shopee</h2>
          <p>Gitashopcollection</p>
        </div>
        <button class="secondary" type="button" @click="downloadUrl(shopeeGitaMassUpdateUrl)">
          Download Semua ZIP
        </button>
      </div>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Jenis</th>
              <th>File</th>
              <th>Isi</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="file in shopeeMassUpdateFiles" :key="file.key">
              <td>
                <strong>{{ file.name }}</strong>
                <span>{{ file.status }}</span>
              </td>
              <td>{{ file.filename }}</td>
              <td>{{ file.note }}</td>
              <td>
                <button class="mini" type="button" @click="downloadMassUpdateFile(file)">
                  Download Excel
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { omnichannelService, posService } from '@/services'

const notice = ref({ type: '', message: '' })
const downloadingKey = ref('')
const products = ref([])
const selectedItems = ref([])
const stockSearch = ref('')
const sourceMarketplace = ref('Lazada Agni Shop Banjarmasin')
const loadingProducts = ref(false)
const syncingStock = ref(false)
const syncResults = ref([])
const shopeeGitaMassUpdateUrl = '/api/marketplace/import/shopee-gita/mass-update'
const shopeeGitaMassUpdateFileUrl = (type) => `${shopeeGitaMassUpdateUrl}/${type}`

const marketplaces = [
  {
    key: 'shopee',
    logo: '<svg viewBox="0 0 64 64" aria-label="Shopee" role="img"><rect width="64" height="64" rx="14" fill="#ee4d2d"/><path d="M20 24h24l-2 26H22L20 24Z" fill="#fff"/><path d="M25 24c.5-8 4-12 7-12s6.5 4 7 12" fill="none" stroke="#fff" stroke-width="4" stroke-linecap="round"/><path d="M36.8 31.5c-1.4-1-2.9-1.5-4.7-1.5-2.1 0-3.4.8-3.4 2.1 0 1.4 1.3 1.9 4.2 2.9 3.8 1.2 5.8 3 5.8 6.1 0 3.7-3.2 6.1-7.8 6.1-3 0-5.6-.9-7.5-2.4l1.9-3.2c1.7 1.3 3.6 2 5.7 2 2.2 0 3.6-.8 3.6-2.2 0-1.3-1-1.9-4-2.9-3.6-1.1-5.9-2.8-5.9-6.1 0-3.5 3-5.9 7.3-5.9 2.6 0 4.8.7 6.5 2l-1.7 3Z" fill="#ee4d2d"/></svg>',
    name: 'Shopee',
    account: 'Gitashopcollection'
  },
  {
    key: 'lazada',
    logo: '<svg viewBox="0 0 180 64" aria-label="Lazada" role="img"><rect width="180" height="64" rx="14" fill="#fff"/><path d="M41 14 22 25v22l19 11 19-11V25L41 14Z" fill="#1a2a6c"/><path d="M22 25 41 36v22L22 47V25Z" fill="#f36f21"/><path d="M60 25 41 36v22l19-11V25Z" fill="#f7b500"/><path d="M22 25 41 14l19 11-19 11L22 25Z" fill="#ff0084"/><text x="74" y="42" fill="#1a2a6c" font-family="Arial, Helvetica, sans-serif" font-size="25" font-weight="800">Lazada</text></svg>',
    name: 'Lazada',
    account: 'Agni Shop Banjarmasin'
  },
  {
    key: 'blibli',
    logo: '<svg viewBox="0 0 180 64" aria-label="Blibli" role="img"><rect width="180" height="64" rx="14" fill="#fff"/><rect x="14" y="14" width="36" height="36" rx="9" fill="#0095da"/><text x="23" y="42" fill="#fff" font-family="Arial, Helvetica, sans-serif" font-size="28" font-weight="900">B</text><text x="62" y="42" fill="#0095da" font-family="Arial, Helvetica, sans-serif" font-size="28" font-weight="900">blibli</text></svg>',
    name: 'Blibli',
    account: 'Agni Shop Banjarmasin'
  }
]

const productInitial = (name) => (String(name || 'P').trim().charAt(0) || 'P').toUpperCase()
const productGroupKey = (product) => [
  product.shopee_product_id || '',
  product.tiktok_product_id || '',
  product.product_name || 'Produk Tanpa Nama'
].join('|')
const productSearchText = (product) => [
  product.product_name,
  product.variant_name,
  product.sku,
  product.stock_master_id
].join(' ').toLowerCase()

const filteredProducts = computed(() => {
  const keyword = stockSearch.value.trim().toLowerCase()
  return products.value.filter((product) => !keyword || productSearchText(product).includes(keyword))
})

const groupedProducts = computed(() => {
  const groups = new Map()

  filteredProducts.value.forEach((product) => {
    const key = productGroupKey(product)
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        productName: product.product_name || 'Produk Tanpa Nama',
        imageUrl: product.image_url || '',
        totalStock: 0,
        variants: []
      })
    }

    const group = groups.get(key)
    group.variants.push(product)
    group.totalStock += Number(product.stock || 0)
    if (!group.imageUrl && product.image_url) {
      group.imageUrl = product.image_url
    }
  })

  return Array.from(groups.values()).slice(0, 80)
})

const canSubmitStockSync = computed(() => selectedItems.value.length > 0 && selectedItems.value.every((item) => Number.isFinite(Number(item.stock)) && Number(item.stock) >= 0))

const loadProducts = async () => {
  loadingProducts.value = true
  try {
    const response = await posService.stockMasterProducts()
    products.value = response.data.data || []
  } catch (error) {
    notice.value = {
      type: 'warning',
      message: error.response?.data?.message || 'Produk stock master gagal dimuat.'
    }
  } finally {
    loadingProducts.value = false
  }
}

const addSyncItem = (variant) => {
  if (selectedItems.value.some((item) => item.stock_master_id === variant.stock_master_id)) {
    return
  }

  selectedItems.value.push({
    stock_master_id: variant.stock_master_id,
    product_name: variant.product_name,
    variant_name: variant.variant_name || 'Default',
    sku: variant.sku,
    stock: Number(variant.stock || 0)
  })
}

const removeSyncItem = (stockMasterId) => {
  selectedItems.value = selectedItems.value.filter((item) => item.stock_master_id !== stockMasterId)
}

const submitStockSync = async () => {
  if (!canSubmitStockSync.value) return

  syncingStock.value = true
  notice.value = { type: '', message: '' }
  try {
    const response = await omnichannelService.manualImportMarketplaceStockSync({
      source_marketplace: sourceMarketplace.value,
      items: selectedItems.value.map((item) => ({
        stock_master_id: item.stock_master_id,
        stock: Math.max(0, Math.trunc(Number(item.stock || 0)))
      }))
    })
    syncResults.value = response.data.items || []
    notice.value = {
      type: response.data.status === 'success' ? 'success' : 'warning',
      message: response.data.message || 'Sinkron stok manual selesai.'
    }
    await loadProducts()
  } catch (error) {
    syncResults.value = error.response?.data?.items || []
    notice.value = {
      type: 'warning',
      message: error.response?.data?.message || error.message || 'Sinkron stok manual gagal.'
    }
  } finally {
    syncingStock.value = false
  }
}

const shopeeMassUpdateFiles = [
  {
    key: 'basic-info',
    name: 'Basic Info',
    filename: 'mass_update_basic_info.xlsx',
    note: 'Nama produk dan deskripsi; simbol invalid Shopee otomatis dibersihkan.',
    status: 'Siap'
  },
  {
    key: 'sales-info',
    name: 'Sales Info',
    filename: 'mass_update_sales_info.xlsx',
    note: 'SKU dan stok; harga tidak diubah agar aman dari produk promo.',
    status: 'Siap'
  },
  {
    key: 'media-info',
    name: 'Media Info',
    filename: 'mass_update_media_info.xlsx',
    note: 'Foto produk dan varian hanya memakai URL cf.shopee.co.id.',
    status: 'Siap'
  },
  {
    key: 'shipping-info',
    name: 'Shipping Info',
    filename: 'mass_update_shipping_info.xlsx',
    note: 'Template pengiriman dari Shopee Gita.',
    status: 'Siap'
  },
  {
    key: 'dts-info',
    name: 'DTS Info',
    filename: 'mass_update_dts_info.xlsx',
    note: 'Template masa proses dari Shopee Gita.',
    status: 'Siap'
  },
  {
    key: 'republish-items',
    name: 'Republish Items',
    filename: 'mass_republish_items.xlsx',
    note: 'Disediakan kosong agar tidak mengirim aksi invalid.',
    status: 'Kosong'
  }
]

const downloadUrl = (url) => {
  const link = document.createElement('a')
  link.href = url
  link.download = ''
  document.body.appendChild(link)
  link.click()
  link.remove()
}

const downloadMassUpdate = (marketplace) => {
  if (marketplace.key !== 'shopee') {
    notice.value = {
      type: 'warning',
      message: `Template Mass Update ${marketplace.name} untuk ${marketplace.account} belum dipasang. Kirim contoh file mass update dulu.`
    }
    return
  }

  downloadingKey.value = marketplace.key
  notice.value = {
    type: 'success',
    message: 'Download Mass Update Shopee Gitashopcollection sedang disiapkan.'
  }
  downloadUrl(shopeeGitaMassUpdateUrl)
  window.setTimeout(() => {
    downloadingKey.value = ''
  }, 1200)
}

const downloadMassUpdateFile = (file) => {
  notice.value = {
    type: 'success',
    message: `Download ${file.name} Shopee Gitashopcollection sedang disiapkan.`
  }
  downloadUrl(shopeeGitaMassUpdateFileUrl(file.key))
}

onMounted(loadProducts)
</script>

<style scoped>
.page-shell { margin-left:240px; padding:24px; color:#0f172a; }
.page-header { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; margin-bottom:18px; }
.page-header p { color:#64748b; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:.08em; }
.page-header h1 { font-size:28px; line-height:1.15; margin-top:4px; }
.notice { border-radius:6px; padding:10px 12px; margin-bottom:14px; }
.notice.warning { border:1px solid #fde68a; background:#fffbeb; color:#92400e; }
.notice.success { border:1px solid #bbf7d0; background:#f0fdf4; color:#166534; }
.stock-sync-panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); margin-bottom:16px; overflow:hidden; }
.sync-layout { display:grid; grid-template-columns:minmax(0,1fr) 360px; gap:14px; padding:14px; }
.catalog-panel { min-width:0; }
.sync-toolbar { display:grid; grid-template-columns:240px minmax(0,1fr); gap:10px; margin-bottom:12px; }
.sync-toolbar label,.selected-item label { display:grid; gap:6px; color:#475569; font-size:12px; font-weight:900; }
select,input { border:1px solid #cbd5e1; border-radius:6px; min-height:40px; padding:8px 10px; width:100%; }
.product-list { display:grid; gap:10px; max-height:680px; overflow:auto; padding-right:4px; }
.product-row { border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
.product-main { align-items:center; background:#f8fafc; display:grid; gap:10px; grid-template-columns:58px minmax(0,1fr); padding:10px; }
.product-main img,.image-empty { border:1px solid #cbd5e1; border-radius:6px; height:58px; width:58px; }
.product-main img { object-fit:cover; }
.image-empty { align-items:center; background:#e2e8f0; color:#475569; display:grid; font-size:22px; font-weight:900; justify-items:center; }
.product-main strong,.variant-row strong,.selected-item strong { color:#0f172a; display:block; line-height:1.25; overflow-wrap:anywhere; }
.product-main small,.variant-row small,.selected-item small { color:#64748b; display:block; font-size:12px; margin-top:3px; overflow-wrap:anywhere; }
.variant-list { display:grid; gap:1px; background:#edf2f7; }
.variant-row { align-items:center; background:#fff; display:grid; gap:10px; grid-template-columns:minmax(0,1fr) auto; padding:10px; }
.sync-cart { border:1px solid #e2e8f0; border-radius:8px; padding:12px; position:sticky; top:80px; }
.sync-cart h2,.result-panel h3 { font-size:17px; line-height:1.25; }
.sync-cart p { color:#64748b; font-size:13px; margin:4px 0 12px; }
.selected-list { display:grid; gap:9px; max-height:470px; overflow:auto; padding-right:2px; }
.selected-item { border:1px solid #e2e8f0; border-radius:7px; display:grid; gap:8px; padding:10px; }
.danger { background:#fee2e2; color:#991b1b; }
.full { margin-top:12px; width:100%; }
.result-panel { border-top:1px solid #e2e8f0; padding:14px; }
.empty { border:1px dashed #cbd5e1; border-radius:7px; color:#64748b; padding:18px; text-align:center; }
.market-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:14px; }
.market-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); display:grid; gap:16px; min-height:280px; padding:16px; }
.card-top { align-items:flex-start; display:flex; justify-content:space-between; gap:12px; }
.card-top span { border-radius:999px; border:1px solid #dbe3ef; color:#475569; font-size:12px; font-weight:800; padding:5px 9px; }
.market-logo { align-items:center; display:grid; height:58px; justify-items:start; width:180px; }
.market-logo :deep(svg) { display:block; height:58px; max-width:180px; width:auto; }
.market-card.shopee .market-logo { width:58px; }
.market-card.shopee .market-logo :deep(svg) { width:58px; }
.market-body { display:grid; gap:5px; }
.market-body h2 { font-size:22px; line-height:1.2; }
.market-body strong { color:#334155; font-size:15px; line-height:1.35; }
dl { display:grid; gap:10px; }
dl div { border-top:1px solid #edf2f7; display:grid; gap:4px; padding-top:10px; }
dt { color:#64748b; font-size:12px; font-weight:800; text-transform:uppercase; }
dd { color:#0f172a; font-size:14px; font-weight:700; }
button { border:0; border-radius:6px; cursor:pointer; font-weight:800; padding:10px 13px; }
.primary { align-self:end; background:#0f5fc7; color:#fff; }
.secondary { background:#eef4ff; color:#0f5fc7; }
.mini { background:#0f5fc7; color:#fff; min-width:126px; padding:8px 11px; }
button:disabled { cursor:wait; opacity:.72; }
.download-panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.05); margin-top:16px; overflow:hidden; }
.panel-head { align-items:center; border-bottom:1px solid #e2e8f0; display:flex; justify-content:space-between; gap:14px; padding:16px; }
.panel-head h2 { font-size:18px; line-height:1.25; }
.panel-head p { color:#64748b; font-size:13px; font-weight:700; margin-top:3px; }
.table-wrap { overflow-x:auto; }
table { border-collapse:collapse; width:100%; }
th, td { border-bottom:1px solid #edf2f7; padding:12px 16px; text-align:left; vertical-align:middle; }
th { background:#f8fafc; color:#475569; font-size:12px; font-weight:900; text-transform:uppercase; }
td { color:#334155; font-size:14px; }
td strong { color:#0f172a; display:block; font-size:14px; }
td span { color:#64748b; display:block; font-size:12px; margin-top:3px; }
td:last-child, th:last-child { text-align:right; width:150px; }
@media (max-width:1100px) {
  .market-grid { grid-template-columns:1fr; }
  .sync-layout,.sync-toolbar { grid-template-columns:1fr; }
  .sync-cart { position:static; }
  .panel-head { align-items:flex-start; flex-direction:column; }
  td:last-child, th:last-child { text-align:left; }
}
@media (max-width:820px) {
  .page-shell { margin-left:0; padding:16px; }
  .page-header { flex-direction:column; }
}
</style>
