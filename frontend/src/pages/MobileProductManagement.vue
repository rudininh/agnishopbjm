<template>
  <div class="mobile-product-page">
    <!-- Header -->
    <header class="mobile-header">
      <h1>📱 Kelola Produk</h1>
      <p class="subtitle">Shopee & TikTok</p>
    </header>

    <section class="mobile-access-guide" aria-labelledby="mobile-access-guide-title">
      <div class="guide-header">
        <p class="guide-eyebrow">Akses dari handphone</p>
        <h2 id="mobile-access-guide-title">Panduan membuka halaman ini di HP</h2>
      </div>
      <ol class="guide-steps">
        <li>Pastikan handphone dan komputer server berada pada jaringan Wi-Fi yang sama.</li>
        <li>
          Buka browser di handphone, lalu masukkan alamat:
          <code class="guide-url">{{ mobileAccessUrl }}</code>
        </li>
        <li>
          Jika alamat tersebut tidak terbuka, gunakan alamat IP komputer sebagai cadangan:
          <code>{{ lanAccessUrl }}</code>.
        </li>
        <li>Jika suatu saat alamat tersebut berubah, cari IP komputer melalui Command Prompt dengan menjalankan <code>ipconfig</code>, lalu gunakan nilai IPv4 Address pada jaringan yang sedang dipakai.</li>
        <li>Login dengan akun admin untuk mulai mencari dan memperbarui produk.</li>
      </ol>
    </section>

    <section class="stock-master-panel" aria-labelledby="stock-master-title">
      <div class="stock-master-heading">
        <p class="stock-master-eyebrow">Sumber stok harian</p>
        <h2 id="stock-master-title">Stock Master</h2>
        <p>Catat barang masuk, penjualan, retur, barang rusak, dan koreksi di sini. Marketplace belum diperbarui otomatis.</p>
      </div>

      <input
        v-model="stockMasterSearch"
        type="search"
        class="form-input"
        placeholder="Cari SKU, nama produk, atau varian Stock Master"
        @input="debouncedStockMasterSearch"
      />

      <p v-if="stockMasterLoading" class="stock-master-status">Mencari Stock Master...</p>
      <p v-else-if="stockMasterError" class="stock-master-error">{{ stockMasterError }}</p>

      <div v-if="stockMasterResults.length" class="stock-master-results">
        <button
          v-for="item in stockMasterResults"
          :key="item.id"
          type="button"
          :class="['stock-master-result', { selected: selectedStockMaster?.id === item.id }]"
          @click="selectStockMaster(item)"
        >
          <strong>{{ item.product_name || item.internal_sku }}</strong>
          <span>{{ item.variant_name || 'Varian standar' }} · {{ item.internal_sku }}</span>
          <b>{{ item.stock_qty }} unit</b>
        </button>
      </div>

      <form v-if="selectedStockMaster" class="stock-adjustment-form" @submit.prevent="submitStockAdjustment">
        <div class="selected-stock-master">
          <strong>{{ selectedStockMaster.product_name || selectedStockMaster.internal_sku }}</strong>
          <span>{{ selectedStockMaster.variant_name || 'Varian standar' }} · Stok Master {{ selectedStockMaster.stock_qty }} unit</span>
        </div>

        <div class="stock-adjustment-grid">
          <label class="form-group">
            <span>Arah stok</span>
            <select v-model="stockAdjustment.direction" class="form-input">
              <option value="increase">Tambah stok</option>
              <option value="decrease">Kurangi stok</option>
            </select>
          </label>
          <label class="form-group">
            <span>Jumlah</span>
            <input v-model.number="stockAdjustment.quantity" type="number" min="1" class="form-input" required />
          </label>
        </div>

        <label class="form-group">
          <span>Alasan</span>
          <select v-model="stockAdjustment.reason" class="form-input" required>
            <option value="receiving">Barang masuk</option>
            <option value="sale">Penjualan</option>
            <option value="return">Retur</option>
            <option value="damaged">Barang rusak</option>
            <option value="correction">Koreksi stok</option>
          </select>
        </label>
        <label class="form-group">
          <span>Catatan (opsional)</span>
          <textarea v-model="stockAdjustment.note" class="form-input" rows="2" placeholder="Contoh: nomor pesanan atau penerimaan barang"></textarea>
        </label>
        <button type="submit" class="btn-primary" :disabled="stockAdjustmentSubmitting">
          {{ stockAdjustmentSubmitting ? 'Mencatat...' : 'Catat Penyesuaian Stock Master' }}
        </button>
      </form>

      <div v-if="selectedStockMaster" class="delivery-state-list">
        <h3>Status tujuan marketplace</h3>
        <p v-for="state in Object.values(selectedStockMaster.delivery_states || {})" :key="state.account_key">
          <strong>{{ state.name }}:</strong> {{ formatDeliveryState(state.status) }}
        </p>
      </div>

      <div v-if="stockAdjustmentHistory.length" class="stock-history">
        <h3>Riwayat terakhir</h3>
        <p v-for="entry in stockAdjustmentHistory" :key="entry.id">
          {{ entry.delta > 0 ? '+' : '' }}{{ entry.delta }} unit · {{ entry.reason }} · stok akhir {{ entry.after_quantity }}
        </p>
      </div>
    </section>

    <!-- Search & Filter -->
    <div class="search-section">
      <div class="search-box">
        <input 
          v-model="searchQuery" 
          type="search" 
          placeholder="🔍 Cari produk..."
          @input="debouncedSearch"
          class="search-input"
        />
      </div>
      <div class="filter-tabs">
        <button 
          v-for="tab in marketplaceTabs" 
          :key="tab.value"
          :class="['tab-btn', { active: selectedMarketplace === tab.value }]"
          @click="selectMarketplace(tab.value)"
        >
          {{ tab.icon }} {{ tab.label }}
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="loading-state">
      <div class="spinner"></div>
      <p>Memuat produk...</p>
    </div>

    <!-- Error State -->
    <div v-else-if="error" class="error-state">
      <p>❌ {{ error }}</p>
      <button @click="loadProducts" class="retry-btn">Coba Lagi</button>
    </div>

    <!-- Products List -->
    <div v-else class="products-list">
      <div 
        v-for="product in products" 
        :key="product.shopee_product_id || product.tiktok_product_id"
        class="product-card"
        @click="selectProduct(product)"
      >
        <div class="product-image">
          <img 
            :src="product.shopee_image || product.tiktok_image || '/placeholder.png'" 
            :alt="product.product_name"
            @error="handleImageError"
          />
        </div>
        <div class="product-info">
          <h3>{{ product.product_name }}</h3>
          <div class="marketplace-badges">
            <span v-if="product.shopee_product_id" class="badge shopee">🛒 Shopee</span>
            <span v-if="product.tiktok_product_id" class="badge tiktok">🎵 TikTok</span>
          </div>
          <div class="product-stats">
            <div v-if="product.shopee_product_id" class="stat">
              <span class="label">Shopee:</span>
              <span class="value">Rp {{ formatNumber(product.shopee_price) }} • Stok {{ product.shopee_stock }}</span>
            </div>
            <div v-if="product.tiktok_product_id" class="stat">
              <span class="label">TikTok:</span>
              <span class="value">Rp {{ formatNumber(product.tiktok_price) }} • Stok {{ product.tiktok_stock }}</span>
            </div>
          </div>
        </div>
        <div class="product-arrow">›</div>
      </div>

      <!-- Empty State -->
      <div v-if="products.length === 0" class="empty-state">
        <p>📦 Tidak ada produk ditemukan</p>
      </div>

      <!-- Load More -->
      <div v-if="hasMore" class="load-more">
        <button @click="loadMore" :disabled="loadingMore" class="load-more-btn">
          {{ loadingMore ? 'Memuat...' : 'Muat Lebih Banyak' }}
        </button>
      </div>
    </div>

    <!-- Product Detail Modal -->
    <div v-if="selectedProductData" class="modal-backdrop" @click.self="closeModal">
      <div class="modal-content">
        <div class="modal-header">
          <h2>{{ selectedProductData.product_name }}</h2>
          <button @click="closeModal" class="close-btn">✕</button>
        </div>

        <div class="modal-body">
          <!-- Product Image -->
          <div class="detail-image">
            <img 
              :src="selectedProductData.main_image || '/placeholder.png'" 
              :alt="selectedProductData.product_name"
              @error="handleImageError"
            />
          </div>

          <!-- Marketplace Badge -->
          <div class="detail-badge">
            <span :class="['badge', selectedProductData.marketplace]">
              {{ selectedProductData.marketplace === 'shopee' ? '🛒 Shopee' : '🎵 TikTok' }}
            </span>
          </div>

          <!-- Variants List -->
          <div class="variants-section">
            <h3>Varian Produk ({{ selectedProductData.variants.length }})</h3>
            
            <div 
              v-for="variant in selectedProductData.variants" 
              :key="variant.variant_id"
              class="variant-card"
            >
              <div class="variant-header" @click="toggleVariant(variant.variant_id)">
                <div class="variant-image">
                  <img 
                    v-if="variant.image_url" 
                    :src="variant.image_url" 
                    :alt="variant.variant_name"
                    @error="handleImageError"
                  />
                  <div v-else class="image-placeholder">📦</div>
                </div>
                <div class="variant-info">
                  <strong>{{ variant.variant_name || 'Varian Standar' }}</strong>
                  <small>SKU: {{ variant.seller_sku || '-' }}</small>
                </div>
                <button class="expand-btn">
                  {{ expandedVariants.includes(variant.variant_id) ? '▼' : '▶' }}
                </button>
              </div>

              <!-- Variant Details (Expanded) -->
              <div v-if="expandedVariants.includes(variant.variant_id)" class="variant-details">
                <div class="detail-row">
                  <span class="detail-label">Harga Jual:</span>
                  <span class="detail-value">Rp {{ formatNumber(variant.price || 0) }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Harga Modal:</span>
                  <span class="detail-value">Rp {{ formatNumber(variant.cost_price || 0) }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Laba Kotor:</span>
                  <span class="detail-value profit">Rp {{ formatNumber((variant.price || 0) - (variant.cost_price || 0)) }}</span>
                </div>
                <div class="detail-row">
                  <span class="detail-label">Stok marketplace:</span>
                  <span class="detail-value">{{ variant.stock || 0 }} unit</span>
                </div>
                <p class="stock-master-notice">Untuk mengubah stok, gunakan panel Stock Master di atas. Form ini hanya untuk harga.</p>

                <!-- Edit Form -->
                <div class="edit-form">
                  <div class="form-group">
                    <label>Harga Jual:</label>
                    <input 
                      v-model.number="editForm[variant.variant_id].price" 
                      type="number" 
                      min="0"
                      step="1000"
                      class="form-input"
                    />
                  </div>
                  <div class="form-group">
                    <label>Harga Modal:</label>
                    <input 
                      v-model.number="editForm[variant.variant_id].cost_price" 
                      type="number" 
                      min="0"
                      step="1000"
                      class="form-input"
                    />
                  </div>

                  <div class="form-actions">
                    <button 
                      @click="updateVariant(variant)"
                      :disabled="updating"
                      class="btn-primary"
                    >
                      {{ updating ? 'Menyimpan...' : '💾 Simpan' }}
                    </button>
                    <button 
                      @click="resetForm(variant)"
                      class="btn-secondary"
                    >
                      🔄 Reset
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Success Toast -->
    <div v-if="successMessage" class="toast success">
      ✅ {{ successMessage }}
    </div>

  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import api from '@/services/api'
import { createAdjustmentPayload, formatDeliveryState } from './mobileStockMasterState'

// State
const loading = ref(false)
const loadingMore = ref(false)
const updating = ref(false)
const error = ref('')
const successMessage = ref('')
const searchQuery = ref('')
const selectedMarketplace = ref('all')
const products = ref([])
const selectedProductData = ref(null)
const expandedVariants = ref([])
const editForm = reactive({})
const stockMasterSearch = ref('')
const stockMasterResults = ref([])
const selectedStockMaster = ref(null)
const stockMasterLoading = ref(false)
const stockMasterError = ref('')
const stockAdjustmentSubmitting = ref(false)
const stockAdjustmentHistory = ref([])
const stockAdjustment = reactive({
  direction: 'decrease',
  quantity: 1,
  reason: 'sale',
  note: '',
})
const fallbackImage = '/agni-logo.png'
const mobileAccessUrl = 'http://agnishopbjm-laravel.test/mobile/kelola-produk'
const lanAccessUrl = 'http://192.168.18.6/mobile/kelola-produk'

// Pagination
const currentPage = ref(1)
const totalPages = ref(1)

// Marketplace Tabs
const marketplaceTabs = [
  { value: 'all', label: 'Semua', icon: '🏪' },
  { value: 'shopee', label: 'Shopee', icon: '🛒' },
  { value: 'tiktok', label: 'TikTok', icon: '🎵' },
]

// Computed
const hasMore = computed(() => currentPage.value < totalPages.value)

// Methods
const loadProducts = async (reset = true) => {
  try {
    if (reset) {
      loading.value = true
      currentPage.value = 1
      products.value = []
    } else {
      loadingMore.value = true
    }
    
    error.value = ''

    const response = await api.get('/mobile/products', {
      params: {
        search: searchQuery.value,
        marketplace: selectedMarketplace.value,
        page: currentPage.value,
        per_page: 20,
      }
    })

    if (response.data.success) {
      if (reset) {
        products.value = response.data.data
      } else {
        products.value.push(...response.data.data)
      }
      
      currentPage.value = response.data.pagination.current_page
      totalPages.value = response.data.pagination.last_page
    } else {
      error.value = response.data.message || 'Gagal memuat produk'
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Terjadi kesalahan saat memuat produk'
    console.error('Error loading products:', err)
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

const loadMore = () => {
  currentPage.value++
  loadProducts(false)
}

const selectMarketplace = (marketplace) => {
  selectedMarketplace.value = marketplace
  loadProducts(true)
}

let searchTimeout
const debouncedSearch = () => {
  clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    loadProducts(true)
  }, 500)
}

let stockMasterSearchTimeout
const debouncedStockMasterSearch = () => {
  clearTimeout(stockMasterSearchTimeout)
  stockMasterSearchTimeout = setTimeout(() => {
    loadStockMaster()
  }, 350)
}

const loadStockMaster = async () => {
  if (!stockMasterSearch.value.trim()) {
    stockMasterResults.value = []
    return
  }

  try {
    stockMasterLoading.value = true
    stockMasterError.value = ''
    const response = await api.get('/mobile/stock-master/search', {
      params: { search: stockMasterSearch.value, per_page: 10 },
    })
    stockMasterResults.value = response.data.data || []
  } catch (err) {
    stockMasterError.value = err.response?.data?.message || 'Gagal mencari Stock Master.'
  } finally {
    stockMasterLoading.value = false
  }
}

const selectStockMaster = async (item) => {
  selectedStockMaster.value = item
  stockAdjustmentHistory.value = []

  try {
    const response = await api.get('/mobile/stock-master/adjustments', {
      params: { stock_master_id: item.id, limit: 5 },
    })
    stockAdjustmentHistory.value = response.data.data || []
  } catch (err) {
    stockMasterError.value = err.response?.data?.message || 'Gagal memuat riwayat stok.'
  }
}

const submitStockAdjustment = async () => {
  try {
    stockAdjustmentSubmitting.value = true
    stockMasterError.value = ''
    const payload = createAdjustmentPayload({
      stockMasterId: selectedStockMaster.value?.id,
      ...stockAdjustment,
    })
    const response = await api.post('/mobile/stock-master/adjustments', payload)
    const adjustment = response.data.data
    selectedStockMaster.value = {
      ...selectedStockMaster.value,
      stock_qty: adjustment.after_quantity,
      delivery_states: adjustment.delivery_states,
    }
    stockAdjustmentHistory.value = [adjustment, ...stockAdjustmentHistory.value].slice(0, 5)
    stockAdjustment.quantity = 1
    stockAdjustment.note = ''
    showSuccess('Penyesuaian Stock Master berhasil dicatat. Marketplace belum diperbarui otomatis.')
  } catch (err) {
    stockMasterError.value = err.response?.data?.message || err.message || 'Gagal mencatat penyesuaian stok.'
  } finally {
    stockAdjustmentSubmitting.value = false
  }
}

const selectProduct = async (product) => {
  try {
    loading.value = true
    const marketplace = product.marketplace || (product.shopee_product_id ? 'shopee' : 'tiktok')
    const productId = product.product_id || product.shopee_product_id || product.tiktok_product_id

    const response = await api.get('/mobile/products/detail', {
      params: {
        product_id: productId,
        marketplace: marketplace,
      }
    })

    if (response.data.success) {
      selectedProductData.value = response.data.data
      
      // Initialize edit form for all variants
      selectedProductData.value.variants.forEach(variant => {
        editForm[variant.variant_id] = {
          price: variant.price || 0,
          cost_price: variant.cost_price || 0,
        }
      })
      
      expandedVariants.value = []
    } else {
      error.value = response.data.message || 'Gagal memuat detail produk'
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Terjadi kesalahan'
    console.error('Error loading product detail:', err)
  } finally {
    loading.value = false
  }
}

const closeModal = () => {
  selectedProductData.value = null
  expandedVariants.value = []
}

const toggleVariant = (variantId) => {
  const index = expandedVariants.value.indexOf(variantId)
  if (index > -1) {
    expandedVariants.value.splice(index, 1)
  } else {
    expandedVariants.value.push(variantId)
  }
}

const updateVariant = async (variant) => {
  try {
    updating.value = true
    const formData = editForm[variant.variant_id]

    const response = await api.post('/mobile/products/update-stock-price', {
      marketplace: selectedProductData.value.marketplace,
      product_id: selectedProductData.value.product_id,
      variant_id: variant.variant_id,
      stock: variant.stock || 0,
      price: formData.price,
      cost_price: formData.cost_price,
    })

    if (response.data.success) {
      // Update local data
      variant.price = formData.price
      variant.cost_price = formData.cost_price

      showSuccess('Berhasil diupdate!')
      
      // Refresh products list
      loadProducts(true)
    } else {
      error.value = response.data.message || 'Gagal update'
    }
  } catch (err) {
    error.value = err.response?.data?.message || 'Terjadi kesalahan saat update'
    console.error('Error updating variant:', err)
  } finally {
    updating.value = false
  }
}

const resetForm = (variant) => {
  editForm[variant.variant_id] = {
    price: variant.price || 0,
    cost_price: variant.cost_price || 0,
  }
}

const showSuccess = (message) => {
  successMessage.value = message
  setTimeout(() => {
    successMessage.value = ''
  }, 3000)
}

const formatNumber = (value) => {
  if (!value) return '0'
  return new Intl.NumberFormat('id-ID').format(value)
}

const handleImageError = (e) => {
  e.target.src = fallbackImage
}

// Lifecycle
onMounted(() => {
  loadProducts()
})
</script>

<style scoped>
.mobile-product-page {
  min-height: 100vh;
  background: #f5f5f5;
  padding-bottom: 20px;
}

.mobile-header {
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  color: white;
  padding: 20px;
  text-align: center;
  box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.mobile-header h1 {
  margin: 0;
  font-size: 24px;
  font-weight: 600;
}

.subtitle {
  margin: 5px 0 0;
  opacity: 0.9;
  font-size: 14px;
}

.search-section {
  background: white;
  padding: 15px;
  box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}

.search-box {
  margin-bottom: 12px;
}

.search-input {
  width: 100%;
  padding: 12px 15px;
  border: 2px solid #e0e0e0;
  border-radius: 25px;
  font-size: 15px;
  outline: none;
  transition: all 0.3s;
}

.search-input:focus {
  border-color: #667eea;
  box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
}

.filter-tabs {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  -webkit-overflow-scrolling: touch;
}

.tab-btn {
  flex: 1;
  min-width: 100px;
  padding: 10px 16px;
  border: 2px solid #e0e0e0;
  background: white;
  border-radius: 20px;
  font-size: 14px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.3s;
  white-space: nowrap;
}

.tab-btn.active {
  background: #667eea;
  color: white;
  border-color: #667eea;
}

.loading-state, .error-state {
  text-align: center;
  padding: 40px 20px;
}

.spinner {
  width: 40px;
  height: 40px;
  margin: 0 auto 15px;
  border: 4px solid #f3f3f3;
  border-top: 4px solid #667eea;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}

@keyframes spin {
  0% { transform: rotate(0deg); }
  100% { transform: rotate(360deg); }
}

.retry-btn {
  margin-top: 15px;
  padding: 10px 24px;
  background: #667eea;
  color: white;
  border: none;
  border-radius: 20px;
  font-size: 14px;
  cursor: pointer;
}

.products-list {
  padding: 15px;
}

.product-card {
  background: white;
  border-radius: 12px;
  padding: 15px;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: 0 2px 8px rgba(0,0,0,0.08);
  cursor: pointer;
  transition: all 0.3s;
}

.product-card:active {
  transform: scale(0.98);
  box-shadow: 0 1px 4px rgba(0,0,0,0.12);
}

.product-image {
  flex-shrink: 0;
  width: 70px;
  height: 70px;
  border-radius: 8px;
  overflow: hidden;
  background: #f5f5f5;
}

.product-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.product-info {
  flex: 1;
  min-width: 0;
}

.product-info h3 {
  margin: 0 0 8px;
  font-size: 15px;
  font-weight: 600;
  color: #333;
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
}

.marketplace-badges {
  display: flex;
  gap: 6px;
  margin-bottom: 8px;
}

.badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 12px;
  font-size: 11px;
  font-weight: 600;
}

.badge.shopee {
  background: #ee4d2d;
  color: white;
}

.badge.tiktok {
  background: #000;
  color: white;
}

.product-stats {
  font-size: 12px;
  color: #666;
}

.stat {
  margin-bottom: 2px;
}

.stat .label {
  font-weight: 600;
  margin-right: 4px;
}

.product-arrow {
  font-size: 24px;
  color: #ccc;
  flex-shrink: 0;
}

.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: #999;
  font-size: 15px;
}

.load-more {
  text-align: center;
  margin-top: 20px;
}

.load-more-btn {
  padding: 12px 32px;
  background: white;
  border: 2px solid #667eea;
  color: #667eea;
  border-radius: 25px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
}

.load-more-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

/* Modal Styles */
.modal-backdrop {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: flex-end;
  z-index: 1000;
  animation: fadeIn 0.3s;
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

.modal-content {
  background: white;
  width: 100%;
  max-height: 90vh;
  border-radius: 20px 20px 0 0;
  overflow-y: auto;
  animation: slideUp 0.3s;
}

@keyframes slideUp {
  from { transform: translateY(100%); }
  to { transform: translateY(0); }
}

.modal-header {
  position: sticky;
  top: 0;
  background: white;
  padding: 20px;
  border-bottom: 1px solid #e0e0e0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  z-index: 10;
}

.modal-header h2 {
  margin: 0;
  font-size: 18px;
  font-weight: 600;
  flex: 1;
  padding-right: 10px;
}

.close-btn {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: none;
  background: #f5f5f5;
  font-size: 20px;
  cursor: pointer;
  flex-shrink: 0;
}

.modal-body {
  padding: 20px;
}

.detail-image {
  width: 100%;
  height: 200px;
  border-radius: 12px;
  overflow: hidden;
  margin-bottom: 15px;
  background: #f5f5f5;
}

.detail-image img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.detail-badge {
  margin-bottom: 20px;
}

.variants-section h3 {
  margin: 0 0 15px;
  font-size: 16px;
  font-weight: 600;
}

.variant-card {
  background: #f9f9f9;
  border-radius: 12px;
  margin-bottom: 12px;
  overflow: hidden;
}

.variant-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px;
  cursor: pointer;
}

.variant-image {
  width: 50px;
  height: 50px;
  border-radius: 8px;
  overflow: hidden;
  background: white;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.variant-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.image-placeholder {
  font-size: 24px;
}

.variant-info {
  flex: 1;
  min-width: 0;
}

.variant-info strong {
  display: block;
  font-size: 14px;
  margin-bottom: 4px;
  color: #333;
}

.variant-info small {
  font-size: 12px;
  color: #666;
}

.expand-btn {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  border: none;
  background: white;
  cursor: pointer;
  flex-shrink: 0;
}

.variant-details {
  padding: 15px;
  background: white;
  border-top: 1px solid #e0e0e0;
}

.detail-row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid #f0f0f0;
}

.detail-row:last-of-type {
  border-bottom: none;
  margin-bottom: 15px;
}

.detail-label {
  font-size: 13px;
  color: #666;
}

.detail-value {
  font-size: 14px;
  font-weight: 600;
  color: #333;
}

.detail-value.profit {
  color: #27ae60;
}

.edit-form {
  margin-top: 15px;
  padding-top: 15px;
  border-top: 2px solid #f0f0f0;
}

.form-group {
  margin-bottom: 12px;
}

.form-group label {
  display: block;
  font-size: 13px;
  font-weight: 600;
  margin-bottom: 6px;
  color: #333;
}

.form-input {
  width: 100%;
  padding: 10px 12px;
  border: 2px solid #e0e0e0;
  border-radius: 8px;
  font-size: 14px;
  outline: none;
  transition: border-color 0.3s;
}

.form-input:focus {
  border-color: #667eea;
}

.form-actions {
  display: flex;
  gap: 10px;
  margin-top: 15px;
}

.btn-primary, .btn-secondary {
  flex: 1;
  padding: 12px;
  border: none;
  border-radius: 8px;
  font-size: 14px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.3s;
}

.btn-primary {
  background: #667eea;
  color: white;
}

.btn-primary:active {
  background: #5568d3;
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-secondary {
  background: #f5f5f5;
  color: #666;
}

.btn-secondary:active {
  background: #e0e0e0;
}

/* Toast */
.toast {
  position: fixed;
  bottom: 20px;
  left: 50%;
  transform: translateX(-50%);
  padding: 12px 24px;
  border-radius: 25px;
  font-size: 14px;
  font-weight: 600;
  box-shadow: 0 4px 12px rgba(0,0,0,0.15);
  z-index: 2000;
  animation: slideUpToast 0.3s;
}

@keyframes slideUpToast {
  from {
    opacity: 0;
    transform: translateX(-50%) translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
  }
}

.toast.success {
  background: #27ae60;
  color: white;
}

.mobile-access-guide {
  background: #f0f9ff;
  border-left: 4px solid #0284c7;
  color: #0f172a;
  margin: 16px 16px 0;
  padding: 16px;
  font-size: 14px;
}

.guide-header {
  margin-bottom: 12px;
}

.guide-eyebrow {
  color: #0369a1;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0;
  margin: 0 0 4px;
  text-transform: uppercase;
}

.guide-header h2 {
  color: #0c4a6e;
  font-size: 16px;
  line-height: 1.3;
  margin: 0;
}

.guide-steps {
  line-height: 1.5;
  margin: 0;
  padding-left: 20px;
}

.guide-steps li + li {
  margin-top: 8px;
}

.guide-steps code {
  background: #dbeafe;
  border: 1px solid #bfdbfe;
  color: #0c4a6e;
  display: inline-block;
  font-family: monospace;
  font-size: 12px;
  margin-top: 4px;
  max-width: 100%;
  overflow-wrap: anywhere;
  padding: 2px 4px;
}

.guide-steps .guide-url {
  display: block;
}

.stock-master-panel {
  background: #ecfdf5;
  border: 1px solid #a7f3d0;
  border-radius: 12px;
  margin: 16px;
  padding: 16px;
}

.stock-master-heading h2,
.delivery-state-list h3,
.stock-history h3 {
  color: #065f46;
  font-size: 18px;
  margin: 0 0 6px;
}

.stock-master-eyebrow {
  color: #047857;
  font-size: 11px;
  font-weight: 700;
  margin: 0 0 4px;
  text-transform: uppercase;
}

.stock-master-heading > p:last-child,
.stock-master-status,
.stock-master-error,
.stock-master-notice {
  color: #475569;
  font-size: 13px;
  line-height: 1.45;
}

.stock-master-error {
  color: #b91c1c;
}

.stock-master-results,
.delivery-state-list,
.stock-history {
  display: grid;
  gap: 8px;
  margin-top: 12px;
}

.stock-master-result {
  background: white;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  cursor: pointer;
  display: grid;
  gap: 3px;
  padding: 10px;
  text-align: left;
}

.stock-master-result.selected {
  border: 2px solid #059669;
}

.stock-master-result span,
.selected-stock-master span {
  color: #64748b;
  font-size: 12px;
}

.stock-adjustment-form {
  background: white;
  border-radius: 10px;
  margin-top: 12px;
  padding: 12px;
}

.selected-stock-master {
  display: grid;
  gap: 3px;
  margin-bottom: 12px;
}

.stock-adjustment-grid {
  display: grid;
  gap: 12px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

/* Responsive adjustments */
@media (min-width: 768px) {
  .mobile-product-page {
    max-width: 600px;
    margin: 0 auto;
  }
  
  .modal-content {
    max-width: 600px;
    margin: 0 auto;
  }
}
</style>
