<template>
  <section class="mobile-page-shell">
    <header class="mobile-header">
      <div>
        <p>Marketplace</p>
        <h1>Sinkron Stok Mobile</h1>
      </div>
    </header>

    <p v-if="notice.message" :class="['notice', notice.type]">{{ notice.message }}</p>

    <section class="stock-sync-panel">
      <div class="panel-head">
        <h2>Sinkron Stok Manual</h2>
        <p>Pakai stok marketplace lain, push ke Shopee &amp; TikTok.</p>
        <button class="secondary full-width" type="button" @click="loadProducts" :disabled="loadingProducts">
          {{ loadingProducts ? 'Memuat...' : 'Refresh Produk' }}
        </button>
      </div>

      <div class="sync-layout">
        <!-- Cart / Selected Items Toggle -->
        <div class="cart-summary" @click="showCart = !showCart" v-if="selectedItems.length > 0">
          <div class="cart-summary-text">
            <strong>{{ selectedItems.length }} varian dipilih</strong>
            <span>Ketuk untuk {{ showCart ? 'tutup' : 'lihat &amp; submit' }}</span>
          </div>
          <div class="cart-toggle-icon">{{ showCart ? '▼' : '▲' }}</div>
        </div>

        <!-- Sync Cart (expanded section) -->
        <aside class="sync-cart" v-show="showCart">
          <h2>Varian Dipilih ({{ selectedItems.length }})</h2>

          <div class="selected-list">
            <article v-for="item in selectedItems" :key="item.stock_master_id" class="selected-item">
              <div class="item-info">
                <strong>{{ item.product_name }}</strong>
                <small>{{ item.variant_name || 'Default' }}</small>
              </div>
              <div class="item-actions">
                <div class="stock-input-wrapper">
                  <label>Stok:</label>
                  <input v-model.number="item.stock" type="number" min="0" step="1" inputmode="numeric" />
                </div>
                <button class="danger mini" type="button" @click="removeSyncItem(item.stock_master_id)">Hapus</button>
              </div>
            </article>
            <p v-if="!selectedItems.length" class="empty">Belum ada varian dipilih.</p>
          </div>

          <button class="primary full" type="button" :disabled="!canSubmitStockSync || syncingStock" @click="submitStockSync">
            {{ syncingStock ? 'Menyinkronkan...' : 'Submit Sinkron' }}
          </button>
        </aside>

        <!-- Product Catalog -->
        <section class="catalog-panel" v-show="!showCart || selectedItems.length === 0">
          <div class="sync-toolbar">
            <label>
              Sumber stok
              <select v-model="sourceMarketplace">
                <option value="Lazada Agni Shop Banjarmasin">Lazada Agni</option>
                <option value="Blibli Agni Shop Banjarmasin">Blibli Agni</option>
                <option value="Shopee Gitashopcollection">Shopee Gita</option>
                <option value="Marketplace Lain">Marketplace Lain</option>
              </select>
            </label>
            <label>
              Cari produk / varian
              <input v-model.trim="stockSearch" type="search" placeholder="Cari..." />
            </label>
          </div>

          <div class="product-list">
            <article v-for="group in groupedProducts" :key="group.key" class="product-row">
              <div class="product-main">
                <img v-if="group.imageUrl" :src="group.imageUrl" alt="" loading="lazy" />
                <div v-else class="image-empty">{{ productInitial(group.productName) }}</div>
                <div class="product-info-mobile">
                  <strong>{{ group.productName }}</strong>
                  <small>{{ group.variants.length }} var | stok master {{ group.totalStock }}</small>
                </div>
              </div>

              <div class="variant-list">
                <div v-for="variant in group.variants" :key="variant.stock_master_id" class="variant-row" :class="{'is-selected': isSelected(variant.stock_master_id)}">
                  <div class="variant-info">
                    <strong>{{ variant.variant_name || 'Default' }}</strong>
                    <small>M:{{ variant.stock }} | S:{{ variant.shopee_stock ?? '-' }} | T:{{ variant.tiktok_stock ?? '-' }}</small>
                  </div>
                  <button v-if="isSelected(variant.stock_master_id)" class="danger mini" type="button" @click="removeSyncItem(variant.stock_master_id)">Hapus</button>
                  <button v-else class="mini primary-outline" type="button" @click="addSyncItem(variant)">Pilih</button>
                </div>
              </div>
            </article>
            <p v-if="!groupedProducts.length" class="empty">{{ loadingProducts ? 'Memuat produk...' : 'Produk tidak ditemukan.' }}</p>
          </div>
        </section>
      </div>

      <div v-if="syncResults.length" class="result-panel">
        <h3>Hasil sinkron terakhir</h3>
        <div class="result-cards">
          <div v-for="row in syncResults" :key="`${row.stock_master_id}:${row.new_stock}`" class="result-card">
            <div class="result-card-header">
              <strong>{{ row.product_name || row.sku }}</strong>
              <span>{{ row.variant_name || '-' }}</span>
            </div>
            <div class="result-card-body">
              <div class="stock-change">Stok: {{ row.old_stock }} &rarr; {{ row.new_stock }}</div>
              <div class="status-row">
                <span class="platform">Shopee:</span>
                <span :class="{'status-ok': row.shopee?.status === 'success', 'status-err': row.shopee?.status !== 'success'}">
                  {{ row.shopee?.status || '-' }} <small v-if="row.shopee?.message">{{ row.shopee?.message }}</small>
                </span>
              </div>
              <div class="status-row">
                <span class="platform">TikTok:</span>
                <span :class="{'status-ok': row.tiktok?.status === 'success', 'status-err': row.tiktok?.status !== 'success'}">
                  {{ row.tiktok?.status || '-' }} <small v-if="row.tiktok?.message">{{ row.tiktok?.message }}</small>
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { omnichannelService, posService } from '@/services'

const notice = ref({ type: '', message: '' })
const products = ref([])
const selectedItems = ref([])
const stockSearch = ref('')
const sourceMarketplace = ref('Lazada Agni Shop Banjarmasin')
const loadingProducts = ref(false)
const syncingStock = ref(false)
const showCart = ref(false)
const syncResults = ref([])

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

  return Array.from(groups.values()).slice(0, 50)
})

const isSelected = (stockMasterId) => {
  return selectedItems.value.some(item => item.stock_master_id === stockMasterId)
}

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
  if (isSelected(variant.stock_master_id)) return

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
  if (selectedItems.value.length === 0) {
    showCart.value = false
  }
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
    selectedItems.value = []
    showCart.value = false
    await loadProducts()
    setTimeout(() => {
      window.scrollTo({ top: document.body.scrollHeight, behavior: 'smooth' })
    }, 100)
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

onMounted(loadProducts)
</script>

<style scoped>
* { box-sizing: border-box; }

.mobile-page-shell {
  margin-left: 0 !important;
  padding: 12px;
  color: #0f172a;
  padding-bottom: 80px;
  max-width: 100vw;
  overflow-x: hidden;
  -webkit-text-size-adjust: 100%;
}

.mobile-header { margin-bottom: 16px; }
.mobile-header p { color: #64748b; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; }
.mobile-header h1 { font-size: 22px; line-height: 1.15; margin-top: 4px; }

.notice { border-radius: 6px; padding: 10px 12px; margin-bottom: 14px; font-size: 13px; }
.notice.warning { border: 1px solid #fde68a; background: #fffbeb; color: #92400e; }
.notice.success { border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; }

.stock-sync-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; box-shadow: 0 1px 2px rgba(15,23,42,.05); overflow: hidden; }
.panel-head { padding: 12px; border-bottom: 1px solid #e2e8f0; }
.panel-head h2 { font-size: 16px; margin-bottom: 4px; }
.panel-head p { color: #64748b; font-size: 12px; margin-bottom: 10px; }

.full-width { width: 100%; display: block; text-align: center; }

.sync-layout { padding: 12px; display: flex; flex-direction: column; gap: 12px; }

.cart-summary {
  background: #0f5fc7;
  color: white;
  padding: 12px 16px;
  border-radius: 8px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0 4px 6px -1px rgba(15, 95, 199, 0.2);
  cursor: pointer;
  position: sticky;
  top: 10px;
  z-index: 10;
  -webkit-tap-highlight-color: transparent;
}
.cart-summary-text { display: flex; flex-direction: column; }
.cart-summary-text strong { font-size: 14px; }
.cart-summary-text span { font-size: 11px; opacity: 0.9; }
.cart-toggle-icon { font-size: 12px; }

.sync-cart { border: 1px solid #cbd5e1; border-radius: 8px; padding: 12px; background: #f8fafc; }
.sync-cart h2 { font-size: 15px; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #e2e8f0; }

.selected-list { display: grid; gap: 8px; max-height: 50vh; overflow-y: auto; margin-bottom: 12px; -webkit-overflow-scrolling: touch; }
.selected-item { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; display: flex; flex-direction: column; gap: 8px; }
.item-info { display: flex; flex-direction: column; }
.item-info strong { font-size: 13px; color: #0f172a; line-height: 1.3; }
.item-info small { font-size: 11px; color: #64748b; margin-top: 2px; }
.item-actions { display: flex; justify-content: space-between; align-items: center; }
.stock-input-wrapper { display: flex; align-items: center; gap: 6px; }
.stock-input-wrapper label { font-size: 12px; font-weight: 700; color: #475569; white-space: nowrap; }
.stock-input-wrapper input { width: 70px; padding: 6px; text-align: center; font-size: 16px; }

.sync-toolbar { display: flex; flex-direction: column; gap: 10px; margin-bottom: 12px; }
.sync-toolbar label { display: flex; flex-direction: column; gap: 4px; color: #475569; font-size: 12px; font-weight: 700; }
select, input { border: 1px solid #cbd5e1; border-radius: 6px; padding: 10px; width: 100%; font-size: 16px; -webkit-appearance: none; appearance: none; background: #fff; }
select { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23475569' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 12px center; padding-right: 30px; }
input[type="search"] { -webkit-appearance: none; }

.product-list { display: grid; gap: 12px; }
.product-row { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
.product-main { align-items: center; background: #f8fafc; display: grid; gap: 10px; grid-template-columns: 50px minmax(0,1fr); padding: 8px; }
.product-main img, .image-empty { border: 1px solid #cbd5e1; border-radius: 6px; height: 50px; width: 50px; }
.product-main img { object-fit: cover; }
.image-empty { align-items: center; background: #e2e8f0; color: #475569; display: grid; font-size: 18px; font-weight: 900; justify-items: center; }
.product-info-mobile { display: flex; flex-direction: column; min-width: 0; }
.product-info-mobile strong { font-size: 13px; color: #0f172a; line-height: 1.25; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.product-info-mobile small { color: #64748b; font-size: 11px; margin-top: 2px; }

.variant-list { display: grid; gap: 1px; background: #edf2f7; }
.variant-row { background: #fff; display: flex; justify-content: space-between; align-items: center; padding: 10px; gap: 8px; min-height: 48px; }
.variant-row.is-selected { background: #f0fdf4; border-left: 3px solid #22c55e; }
.variant-info { display: flex; flex-direction: column; flex-grow: 1; min-width: 0; }
.variant-info strong { font-size: 12px; color: #0f172a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.variant-info small { font-size: 10px; color: #64748b; margin-top: 2px; }

button { border: 0; border-radius: 6px; cursor: pointer; font-weight: 700; padding: 10px 14px; font-size: 14px; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
.primary { background: #0f5fc7; color: #fff; }
.secondary { background: #eef4ff; color: #0f5fc7; border: 1px solid #dbe3ef; }
.danger { background: #fee2e2; color: #991b1b; }
.primary-outline { background: #fff; color: #0f5fc7; border: 2px solid #0f5fc7; }
.mini { padding: 8px 12px; font-size: 12px; min-width: 60px; }
.full { width: 100%; display: block; text-align: center; padding: 14px; font-size: 15px; }
button:disabled { opacity: .6; pointer-events: none; }
button:active:not(:disabled) { transform: scale(0.97); }

.empty { border: 1px dashed #cbd5e1; border-radius: 7px; color: #64748b; padding: 16px; text-align: center; font-size: 13px; }

.result-panel { border-top: 1px solid #e2e8f0; padding: 12px; background: #f8fafc; }
.result-panel h3 { font-size: 15px; margin-bottom: 12px; }
.result-cards { display: grid; gap: 10px; }
.result-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; }
.result-card-header { border-bottom: 1px solid #f1f5f9; padding-bottom: 6px; margin-bottom: 6px; }
.result-card-header strong { display: block; font-size: 13px; color: #0f172a; }
.result-card-header span { font-size: 11px; color: #64748b; }
.result-card-body { display: flex; flex-direction: column; gap: 4px; }
.stock-change { font-size: 12px; font-weight: 700; color: #334155; margin-bottom: 4px; }
.status-row { display: flex; font-size: 11px; justify-content: space-between; }
.platform { color: #64748b; }
.status-ok { color: #166534; font-weight: 600; }
.status-err { color: #991b1b; font-weight: 600; }

::-webkit-scrollbar { width: 4px; height: 4px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
</style>
