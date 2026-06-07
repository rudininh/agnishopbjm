<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Kasir Toko</p>
        <h1>POS Offline</h1>
      </div>
      <div class="header-actions">
        <button class="secondary" @click="settingsOpen = true">
          Pengaturan
        </button>
        <button class="secondary" @click="loadProducts" :disabled="loading">
          {{ loading ? 'Memuat...' : 'Refresh Stock Master' }}
        </button>
        <button class="danger" @click="clearSale" :disabled="!cart.length || submitting">Reset</button>
      </div>
    </header>

    <div v-if="errorMessage" class="alert">{{ errorMessage }}</div>
    <div v-if="successMessage" class="success">{{ successMessage }}</div>

    <div class="pos-grid">
      <section class="product-panel">
        <div class="catalog-layout">
          <aside class="category-sidebar">
            <button
              v-for="category in posCategories"
              :key="category.key"
              class="category-button"
              :class="{ active: selectedCategory === category.key }"
              @click="selectCategory(category.key)"
            >
              <strong>{{ category.label }}</strong>
              <small>{{ category.hint }}</small>
            </button>
          </aside>

          <div class="catalog-content">
            <div class="toolbar">
              <label class="search-box">
                <span>Cari</span>
                <input v-model="search" type="search" placeholder="Produk, varian, SKU, atau ID stock master" autocomplete="off" />
              </label>
              <label class="stock-toggle">
                <input v-model="onlyAvailable" type="checkbox" />
                Stok tersedia
              </label>
            </div>

            <div class="product-browser" :class="{ 'has-variant': selectedProduct }">
              <div class="product-grid">
                <button
                  v-for="group in groupedProducts"
                  :key="group.key"
                  class="product-card"
                  :class="{ active: selectedProductKey === group.key }"
                  @click="selectedProductKey = group.key"
                >
                  <span class="product-image">
                    <img v-if="group.imageUrl" :src="group.imageUrl" :alt="group.productName" loading="lazy" />
                    <span v-else>{{ productInitial(group.productName) }}</span>
                  </span>
                  <span class="product-card-body">
                    <strong>{{ group.productName }}</strong>
                    <small>{{ group.variants.length }} varian</small>
                    <b>{{ formatRupiah(group.lowestPrice) }}</b>
                  </span>
                  <span class="stock-badge">Stok {{ group.totalStock }}</span>
                </button>
              </div>

              <aside v-if="selectedProduct" class="variant-panel">
                <div class="variant-header">
                  <button class="back-button" @click="selectedProductKey = ''">Kembali</button>
                  <div>
                    <p>Pilih Varian</p>
                    <h2>{{ selectedProduct.productName }}</h2>
                  </div>
                </div>

                <div class="variant-list">
                  <button
                    v-for="product in selectedProduct.variants"
                    :key="product.stock_master_id"
                    class="variant-row"
                    :disabled="Number(product.stock || 0) <= 0"
                    @click="addToCart(product)"
                  >
                    <span class="variant-image">
                      <img v-if="product.image_url" :src="product.image_url" :alt="product.variant_name || 'Default'" loading="lazy" />
                      <span v-else>{{ productInitial(product.variant_name || product.product_name) }}</span>
                    </span>
                    <span>
                      <strong>{{ product.variant_name || 'Default' }}</strong>
                      <small>{{ product.sku || `SM-${product.stock_master_id}` }}</small>
                      <small>Stok Master {{ product.stock }} | TikTok {{ product.tiktok_stock }}</small>
                    </span>
                    <b>{{ formatRupiah(product.price) }}</b>
                  </button>
                </div>
              </aside>

              <p v-if="!groupedProducts.length" class="empty">Produk tidak ditemukan.</p>
            </div>
          </div>
        </div>
      </section>

      <aside class="checkout-panel">
        <button type="button" class="cart-summary-button" @click="cartPopupOpen = true">
          <span>{{ cartItemCount }}</span>
          <small>Total</small>
          <strong>{{ formatRupiah(total) }}</strong>
          <b>›</b>
        </button>

        <div class="payment-box">
          <div class="payment-methods">
            <span>Metode bayar</span>
            <div class="payment-choice-grid">
              <button
                type="button"
                class="payment-choice"
                :class="{ active: paymentMethod === 'cash' }"
                @click="paymentMethod = 'cash'; openCashKeypad()"
              >
                <img src="/pos/cash-payment.svg" alt="" />
                <span>Cash</span>
              </button>
              <button
                type="button"
                class="payment-choice"
                :class="{ active: paymentMethod === 'qris' }"
                @click="paymentMethod = 'qris'; cashKeypadOpen = false"
              >
                <img src="/pos/qris-logo.svg" alt="" />
                <span>QRIS</span>
              </button>
            </div>
          </div>

          <label>
            Uang diterima
            <input
              :value="formatPlainRupiah(cashReceived)"
              type="text"
              inputmode="none"
              readonly
              :disabled="paymentMethod !== 'cash'"
              @click="openCashKeypad"
              @focus="openCashKeypad"
            />
          </label>

          <div class="totals">
            <span>Total</span>
            <strong>{{ formatRupiah(total) }}</strong>
            <span>Kembali</span>
            <strong>{{ formatRupiah(change) }}</strong>
          </div>

          <button class="pay-button" @click="submitSale" :disabled="!canPay || submitting">
            {{ submitting ? 'Memproses...' : checkoutButtonLabel }}
          </button>
        </div>
      </aside>
    </div>
  </section>

  <div v-if="cashKeypadOpen" class="keypad-backdrop" @click.self="closeCashKeypad">
    <section class="cash-keypad" role="dialog" aria-modal="true" aria-label="Input uang diterima">
      <div class="keypad-header">
        <div>
          <p>Uang diterima</p>
          <strong>{{ formatRupiah(keypadAmount) }}</strong>
        </div>
        <button type="button" class="keypad-close" @click="closeCashKeypad">Tutup</button>
      </div>

      <button type="button" class="exact-pay" @click="setExactPayment">Bayar Pas</button>

      <div class="quick-cash">
        <button v-for="amount in quickCashAmounts" :key="amount" type="button" @click="setKeypadAmount(amount)">
          {{ formatPlainRupiah(amount) }}
        </button>
      </div>

      <div class="number-pad">
        <button v-for="key in keypadKeys" :key="key" type="button" @click="pressKeypad(key)">
          {{ key }}
        </button>
        <button type="button" class="muted" @click="clearKeypad">C</button>
        <button type="button" @click="pressKeypad('0')">0</button>
        <button type="button" class="muted" @click="deleteKeypad">Hapus</button>
      </div>

      <button type="button" class="apply-cash" @click="applyCashKeypad">Terapkan</button>
    </section>
  </div>

  <div v-if="cartPopupOpen" class="cart-backdrop" @click.self="cartPopupOpen = false">
    <section class="cart-popup" role="dialog" aria-modal="true" aria-label="Keranjang transaksi">
      <div class="cart-popup-header">
        <div>
          <p>Keranjang</p>
          <h2>{{ cartItemCount }} item</h2>
        </div>
        <strong>{{ formatRupiah(total) }}</strong>
        <button type="button" class="cart-popup-close" @click="cartPopupOpen = false">Tutup</button>
      </div>

      <div class="cart-list popup-cart-list">
        <article v-for="item in cart" :key="item.stockMasterId" class="cart-item">
          <div>
            <strong>{{ item.productName }}</strong>
            <small>{{ item.variantName }}</small>
            <small>{{ item.sku }}</small>
            <label class="inline-price">
              Harga
              <input v-model.number="item.price" type="number" min="0" />
            </label>
          </div>
          <div class="qty-control">
            <button @click="decreaseQty(item)">-</button>
            <input
              :value="item.quantity"
              type="number"
              min="1"
              :max="item.stock"
              @input="setQty(item, $event.target.value)"
            />
            <button @click="increaseQty(item)" :disabled="item.quantity >= item.stock">+</button>
          </div>
          <button class="remove" @click="removeItem(item.stockMasterId)">Hapus</button>
        </article>

        <p v-if="!cart.length" class="empty">Keranjang masih kosong.</p>
      </div>

      <button type="button" class="apply-cash" @click="cartPopupOpen = false">Selesai</button>
    </section>
  </div>

  <div v-if="settingsOpen" class="settings-backdrop" @click.self="settingsOpen = false">
    <section class="settings-popup" role="dialog" aria-modal="true" aria-label="Pengaturan POS">
      <div class="settings-header">
        <div>
          <p>Mode POS</p>
          <h2>Pengaturan</h2>
        </div>
        <button type="button" class="cart-popup-close" @click="settingsOpen = false">Tutup</button>
      </div>

      <label class="settings-option">
        <input v-model="posSettings.updateStockRealtime" type="checkbox" />
        <span>
          <strong>Update Stock Master dan stok Shopee/TikTok realtime</strong>
          <small>Disiapkan untuk mode live. Saat ini backend masih aman untuk uji coba.</small>
        </span>
      </label>

      <label class="settings-option">
        <input v-model="posSettings.submitMarketplaceOrder" type="checkbox" />
        <span>
          <strong>Update ke marketplace sebagai order asli dan update stok online realtime</strong>
          <small>Disiapkan untuk integrasi marketplace live, belum dieksekusi dari POS uji coba.</small>
        </span>
      </label>

      <label class="settings-option">
        <input v-model="posSettings.printWithoutSubmit" type="checkbox" />
        <span>
          <strong>Cetak nota tanpa submit</strong>
          <small>Untuk uji coba printer. Tidak membuat order dan tidak mengubah stok.</small>
        </span>
      </label>

      <div class="settings-note">
        Mode aman aktif: transaksi POS tidak mengurangi Stock Master selama `POS_OFFLINE_DEDUCT_STOCK=false`.
      </div>
    </section>
  </div>

  <div v-if="successScreenOpen && lastReceipt" class="success-backdrop">
    <section class="success-screen" role="dialog" aria-modal="true" aria-label="Transaksi sukses">
      <div class="success-icon">✓</div>
      <h2>Sukses!</h2>

      <dl class="success-summary">
        <dt>Total Tagihan</dt>
        <dd>{{ formatRupiah(lastReceipt.total) }}</dd>
        <dt>{{ formatPayment(lastReceipt.payment_method) }}</dt>
        <dd>{{ formatRupiah(lastReceipt.cash_received) }}</dd>
        <dt>Kembalian</dt>
        <dd>{{ formatRupiah(lastReceipt.change) }}</dd>
      </dl>

      <div v-if="printingReceipt" class="printing-status">
        <span></span>
        <strong>Memproses struk...</strong>
      </div>

      <div class="success-actions">
        <button type="button" class="secondary" @click="printReceipt">Cetak Ulang</button>
        <button type="button" class="pay-button" @click="closeSuccessScreen">Selesai</button>
      </div>
    </section>
  </div>

  <div v-if="printToastMessage" class="print-toast">
    {{ printToastMessage }}
  </div>

  <section v-if="lastReceipt" class="receipt-print" aria-hidden="true">
    <div class="receipt">
      <img class="receipt-logo" src="/agni-logo.png?v=20260505" alt="Agni Shop Banjarmasin" />
      <h2>AGNI SHOP BANJARMASIN</h2>
      <p>JL. DAHLIA KEBUN SAYUR GG. DAHLIA KEBUN SAYUR RT.11 NO.30, BANJARMASIN</p>
      <dl>
        <dt>No</dt>
        <dd>{{ lastReceipt.order_number }}</dd>
        <dt>Tanggal</dt>
        <dd>{{ formatDate(lastReceipt.created_at) }}</dd>
        <dt>Kasir</dt>
        <dd>{{ lastReceipt.cashier_name }}</dd>
      </dl>
      <table>
        <tbody>
          <tr v-for="item in lastReceipt.items" :key="item.uuid">
            <td>
              <strong>{{ receiptItemParts(item).productName }}</strong>
              <small v-if="receiptItemParts(item).variantName">Varian: {{ receiptItemParts(item).variantName }}</small>
              <small v-if="receiptItemParts(item).sku">SKU: {{ receiptItemParts(item).sku }}</small>
              <small>{{ item.quantity }} x {{ formatRupiah(item.unit_price) }}</small>
            </td>
            <td>{{ formatRupiah(item.subtotal) }}</td>
          </tr>
        </tbody>
      </table>
      <dl class="receipt-total">
        <dt>Total</dt>
        <dd>{{ formatRupiah(lastReceipt.total) }}</dd>
        <dt>Bayar</dt>
        <dd>{{ formatPayment(lastReceipt.payment_method) }}</dd>
        <dt>Diterima</dt>
        <dd>{{ formatRupiah(lastReceipt.cash_received) }}</dd>
        <dt>Kembali</dt>
        <dd>{{ formatRupiah(lastReceipt.change) }}</dd>
      </dl>
      <p>Terima kasih</p>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { posService } from '@/services'

const products = ref([])
const cart = ref([])
const search = ref('')
const onlyAvailable = ref(true)
const loading = ref(false)
const submitting = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const paymentMethod = ref('cash')
const cashReceived = ref(0)
const cashKeypadOpen = ref(false)
const keypadAmount = ref(0)
const cartPopupOpen = ref(false)
const settingsOpen = ref(false)
const successScreenOpen = ref(false)
const printingReceipt = ref(false)
const printToastMessage = ref('')
const lastReceipt = ref(null)
const selectedProductKey = ref('')
const selectedCategory = ref('all')
const posSettings = ref({
  updateStockRealtime: false,
  submitMarketplaceOrder: false,
  printWithoutSubmit: false
})

const posCategories = [
  { key: 'all', label: 'Semua Produk', hint: 'Semua kategori', terms: [] },
  {
    key: 'hijab-segi-empat',
    label: 'Hijab Segi Empat',
    hint: 'Paris, voal, motif, lasercut',
    terms: [
      'azara hijab segi empat sisi polos azara paris packing pouch metal logo',
      'hijab segi empat polos paris premium jahit tepi metal logo',
      'hijab segiempat buckle scarves polos buckle polos neci 4 sisi',
      'segi empat jumbo 130x130 syari lasercut oskara azara',
      'segi empat voal lasercut azara oskara premium',
      'hijab buckle segiempat motif bahan voal sublim',
      'hijab paris japan segi empat paris jadul',
      'hijab buckle box segi empat motif',
      'segi empat buckle motif packing bag tas metal logo',
      'laser cut segiempat paris jadul bordir pita besar tasel',
      'azara hijab segi empat polos paris bella square',
      'hijab segi empat polycotton herscarves box',
      'hijab segiempat motif',
      'hijab segiempat by buckle scarves lasercut',
      'hijab segiempat motif kerudung voal motif',
      'hijab segiempat paris coquette bordir pita kecil',
      'hijab zaryta pouch segi empat voal motif',
      'paris 2 sisi logo b gold',
      'paris jadul premium logo ayu dyah andari',
      'paris jadul premium ori varisha read rose',
      'paris legend hijaberies segiempat paris premium',
      'voal cotton hers hijab segiempat polos',
      'hijab seragam oskara motif plat logo pouch',
      'azara motif kemasan box metal logo'
    ]
  },
  {
    key: 'hijab-pashmina',
    label: 'Hijab Pashmina',
    hint: 'Jersey, viscose, crinkle, ceruty',
    terms: [
      'hijab pashmina ceruty premium motif turki kenan',
      'pashmina lebaran hammer',
      'pashmina crinkle pashmina kusut azara lavanya',
      'pashmina hijab kaos jersey premium',
      'pashmina kaos jersey premium',
      'pashmina viscose bamboo modal tencel',
      'pashmina viscose buckle premium',
      'zanoo pasmina melisae pasmina oval pasmina instan daily'
    ]
  },
  {
    key: 'hijab-instan',
    label: 'Hijab Instan / Bergo',
    hint: 'Hamidah, Jisoo, Moza, Marisa',
    terms: [
      'bergo hamidah akrilik jersey size l',
      'bergo hamidah sporty size m',
      'hasti hijab instan jisoo plus inner 2in1',
      'hijab bergo hamidah size m',
      'hijab bergo instan amel hyget',
      'hijab instan bergo moza',
      'hijab instan segitiga jersey premium',
      'hijab instan jersey oval inner pashmina instan jersey oval',
      'hijab instan oval jersey pashmina instan kaos jersey',
      'hijab instan size m bergo huma',
      'kalisha x mima kerudung bergo hijab instan non pet',
      'mahkota hijab lolly bergo khimar instan',
      'marisa m hijab instan daily',
      'zanoo emma hijab instant bergo rayon'
    ]
  },
  { key: 'ciput-inner', label: 'Ciput / Inner', hint: 'Ciput dagu, anti budeg, marsha', terms: ['ciput dagu lubang telinga spandex', 'ciput anti budeg lubang telinga', 'ciput marsha inner marsha ped'] },
  { key: 'gamis', label: 'Gamis / Busana Muslim', hint: 'Lebaran, kondangan', terms: ['gamis lebaran atau kondangan muslimah bahan ceruty baby doll premium'] },
  { key: 'rok', label: 'Rok', hint: 'Plisket, span plisket', terms: ['rok plisket premium jumbo', 'rok premium span plisket lidi yure skirt pleats'] },
  { key: 'legging', label: 'Legging', hint: 'Polos, jumbo', terms: ['agape legging polos wanita legging jumbo'] },
  { key: 'peci', label: 'Peci / Kopiah', hint: 'Peci rajut, kopiah', terms: ['peci rajut lokal motif mercan kopiah songkok'] },
  { key: 'packaging', label: 'Packaging / Aksesoris', hint: 'Box, paper bag, tali', terms: ['khusus tambahan box buckle tali', 'paper bag motif daun premium'] }
]

const formatRupiah = (value) => new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0
}).format(Number(value || 0))

const formatPlainRupiah = (value) => new Intl.NumberFormat('id-ID', {
  maximumFractionDigits: 0
}).format(Number(value || 0))

const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short'
}).format(new Date(value)) : '-'

const formatPayment = (method) => ({
  cash: 'Tunai',
  qris: 'QRIS'
}[method] || method)

const total = computed(() => cart.value.reduce((sum, item) => sum + (item.price * item.quantity), 0))
const cartItemCount = computed(() => cart.value.reduce((sum, item) => sum + Number(item.quantity || 0), 0))
const change = computed(() => paymentMethod.value === 'cash' ? Math.max(0, Number(cashReceived.value || 0) - total.value) : 0)
const canPay = computed(() => cart.value.length > 0 && total.value > 0 && (paymentMethod.value !== 'cash' || Number(cashReceived.value || 0) >= total.value))
const checkoutButtonLabel = computed(() => posSettings.value.printWithoutSubmit ? 'Cetak Nota Tanpa Submit' : 'Bayar & Cetak Nota')
const keypadKeys = ['1', '2', '3', '4', '5', '6', '7', '8', '9']
const quickCashAmounts = [10000, 20000, 50000, 100000]

const openCashKeypad = () => {
  if (paymentMethod.value !== 'cash') return
  keypadAmount.value = Number(cashReceived.value || 0)
  cashKeypadOpen.value = true
}

const closeCashKeypad = () => {
  cashKeypadOpen.value = false
}

const setKeypadAmount = (amount) => {
  keypadAmount.value = Number(amount || 0)
}

const pressKeypad = (key) => {
  const nextValue = `${Math.trunc(Number(keypadAmount.value || 0))}${key}`.replace(/^0+(?=\d)/, '')
  keypadAmount.value = Math.min(Number(nextValue || 0), 999999999)
}

const deleteKeypad = () => {
  const nextValue = String(Math.trunc(Number(keypadAmount.value || 0))).slice(0, -1)
  keypadAmount.value = Number(nextValue || 0)
}

const clearKeypad = () => {
  keypadAmount.value = 0
}

const setExactPayment = () => {
  keypadAmount.value = Number(total.value || 0)
}

const applyCashKeypad = () => {
  cashReceived.value = Number(keypadAmount.value || 0)
  closeCashKeypad()
}

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

const normalizeCategoryText = (value) => String(value || '')
  .toLowerCase()
  .replace(/&/g, ' dan ')
  .replace(/[^a-z0-9]+/g, ' ')
  .replace(/\s+/g, ' ')
  .trim()

const matchesCategory = (product, category) => {
  if (!category || category.key === 'all') return true
  const text = normalizeCategoryText(product.product_name)
  return category.terms.some((term) => {
    const normalizedTerm = normalizeCategoryText(term)
    return text.includes(normalizedTerm) || normalizedTerm.includes(text)
  })
}

const selectCategory = (categoryKey) => {
  selectedCategory.value = categoryKey
  selectedProductKey.value = ''
}

const filteredProducts = computed(() => {
  const keyword = search.value.trim().toLowerCase()
  const category = posCategories.find((item) => item.key === selectedCategory.value)
  return products.value.filter((product) => {
    const searchText = productSearchText(product)
    const matchesKeyword = !keyword
      || searchText.includes(keyword)
    const matchesStock = !onlyAvailable.value || Number(product.stock || 0) > 0
    return matchesKeyword && matchesCategory(product, category) && matchesStock
  })
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
        lowestPrice: Number(product.price || 0),
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
    if (Number(product.price || 0) > 0 && (group.lowestPrice <= 0 || Number(product.price || 0) < group.lowestPrice)) {
      group.lowestPrice = Number(product.price || 0)
    }
  })

  return Array.from(groups.values())
})

const selectedProduct = computed(() => groupedProducts.value.find((group) => group.key === selectedProductKey.value) || null)

const loadProducts = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await posService.stockMasterProducts()
    products.value = response.data.data || []
    if (selectedProductKey.value && !products.value.some((product) => productGroupKey(product) === selectedProductKey.value)) {
      selectedProductKey.value = ''
    }
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Gagal memuat stock master.'
  } finally {
    loading.value = false
  }
}

const addToCart = (product) => {
  const existing = cart.value.find((item) => item.stockMasterId === product.stock_master_id)
  if (existing) {
    increaseQty(existing)
    return
  }

  cart.value.push({
    stockMasterId: product.stock_master_id,
    productName: product.product_name,
    variantName: product.variant_name || 'Default',
    sku: product.sku,
    price: Number(product.price || 0),
    stock: Number(product.stock || 0),
    quantity: 1
  })
}

const setQty = (item, value) => {
  const quantity = Math.max(1, Math.min(Number(item.stock || 1), Number(value || 1)))
  item.quantity = quantity
}

const increaseQty = (item) => {
  if (item.quantity < item.stock) {
    item.quantity += 1
  }
}

const decreaseQty = (item) => {
  if (item.quantity <= 1) {
    removeItem(item.stockMasterId)
    return
  }
  item.quantity -= 1
}

const removeItem = (stockMasterId) => {
  cart.value = cart.value.filter((item) => item.stockMasterId !== stockMasterId)
}

const clearSale = () => {
  cart.value = []
  cartPopupOpen.value = false
  paymentMethod.value = 'cash'
  cashReceived.value = 0
  errorMessage.value = ''
  successMessage.value = ''
}

const escapeHtml = (value) => String(value ?? '')
  .replace(/&/g, '&amp;')
  .replace(/</g, '&lt;')
  .replace(/>/g, '&gt;')
  .replace(/"/g, '&quot;')
  .replace(/'/g, '&#039;')

const limitReceiptText = (value, limit = 42) => {
  const text = String(value || '').trim()
  if (text.length <= limit) return text
  return `${text.slice(0, limit).trim()}...`
}

const receiptItemParts = (item) => {
  const productName = item.receipt_product_name || item.product_name || item.product?.name || 'Produk'
  const variantName = item.receipt_variant_name || item.variant_name || ''
  const sku = item.receipt_sku || item.sku || ''

  return {
    productName: limitReceiptText(productName, 42),
    variantName: variantName && variantName.toLowerCase() !== 'default' ? limitReceiptText(variantName, 34) : '',
    sku: limitReceiptText(sku, 32)
  }
}

const receiptPrintHtml = (receipt) => {
  const rows = (receipt.items || []).map((item) => {
    const parts = receiptItemParts(item)

    return `
      <tr>
        <td>
          <strong>${escapeHtml(parts.productName)}</strong>
          ${parts.variantName ? `<small>Varian: ${escapeHtml(parts.variantName)}</small>` : ''}
          ${parts.sku ? `<small>SKU: ${escapeHtml(parts.sku)}</small>` : ''}
          <small>${Number(item.quantity || 0)} x ${escapeHtml(formatRupiah(item.unit_price))}</small>
        </td>
        <td>${escapeHtml(formatRupiah(item.subtotal))}</td>
      </tr>
    `
  }).join('')

  return `<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Nota ${escapeHtml(receipt.order_number || '')}</title>
  <style>
    @page { size: 58mm auto; margin: 0; }
    html, body { margin: 0; padding: 0; width: 58mm; background: #fff; color: #000; }
    body { font-family: Arial, sans-serif; font-size: 10px; line-height: 1.25; }
    .receipt { box-sizing: border-box; width: 58mm; min-height: 100mm; padding: 4mm 3mm; }
    .receipt-logo { display: block; height: 15mm; margin: 0 auto 2mm; object-fit: contain; width: 15mm; }
    h2 { font-size: 13px; text-align: center; margin: 0 0 2px; }
    p { font-size: 8.5px; line-height: 1.25; text-align: center; margin: 3px 0 6px; }
    dl { display: grid; grid-template-columns: 18mm 1fr; gap: 2px 4px; margin: 6px 0; }
    dt { margin: 0; }
    dd { margin: 0; text-align: right; }
    table { border-collapse: collapse; width: 100%; }
    td { border-top: 1px dashed #777; padding: 4px 0; vertical-align: top; }
    td:first-child { padding-right: 4px; }
    td:last-child { text-align: right; white-space: nowrap; width: 18mm; }
    small { display: block; margin-top: 2px; }
    .receipt-total { border-top: 1px dashed #777; padding-top: 5px; }
  </style>
</head>
<body>
  <div class="receipt">
    <img class="receipt-logo" src="${window.location.origin}/agni-logo.png?v=20260505" alt="Agni Shop Banjarmasin">
    <h2>AGNI SHOP BANJARMASIN</h2>
    <p>JL. DAHLIA KEBUN SAYUR GG. DAHLIA KEBUN SAYUR RT.11 NO.30, BANJARMASIN</p>
    <dl>
      <dt>No</dt><dd>${escapeHtml(receipt.order_number || '-')}</dd>
      <dt>Tanggal</dt><dd>${escapeHtml(formatDate(receipt.created_at))}</dd>
      <dt>Kasir</dt><dd>${escapeHtml(receipt.cashier_name || 'Kasir')}</dd>
    </dl>
    <table><tbody>${rows}</tbody></table>
    <dl class="receipt-total">
      <dt>Total</dt><dd>${escapeHtml(formatRupiah(receipt.total))}</dd>
      <dt>Bayar</dt><dd>${escapeHtml(formatPayment(receipt.payment_method))}</dd>
      <dt>Diterima</dt><dd>${escapeHtml(formatRupiah(receipt.cash_received))}</dd>
      <dt>Kembali</dt><dd>${escapeHtml(formatRupiah(receipt.change))}</dd>
    </dl>
    <p>Terima kasih</p>
  </div>
  <script>
    window.addEventListener('load', () => {
      window.focus();
      window.print();
    });
  <\/script>
</body>
</html>`
}

const printReceipt = () => {
  if (!lastReceipt.value) return
  printingReceipt.value = true
  printToastMessage.value = ''
  const printWindow = window.open('', 'agni-pos-receipt', 'width=320,height=640')
  if (!printWindow) {
    printingReceipt.value = false
    errorMessage.value = 'Popup print diblokir browser. Izinkan pop-up untuk mencetak struk.'
    return
  }
  printWindow.document.open()
  printWindow.document.write(receiptPrintHtml(lastReceipt.value))
  printWindow.document.close()

  window.setTimeout(() => {
    printingReceipt.value = false
    printToastMessage.value = 'Struk Telah Diproses'
    window.setTimeout(() => {
      printToastMessage.value = ''
    }, 2600)
  }, 1100)
}

const closeSuccessScreen = () => {
  successScreenOpen.value = false
}

const buildLocalReceipt = () => {
  const paid = paymentMethod.value === 'cash' ? Number(cashReceived.value || 0) : Number(total.value || 0)
  return {
    order_number: `TEST-${Date.now()}`,
    customer_name: 'Pelanggan Offline',
    cashier_name: 'Kasir',
    payment_method: paymentMethod.value,
    cash_received: paid,
    change: Math.max(0, paid - Number(total.value || 0)),
    created_at: new Date().toISOString(),
    total: Number(total.value || 0),
    items: cart.value.map((item) => ({
      uuid: `TEST-${item.stockMasterId}`,
      receipt_product_name: item.productName,
      receipt_variant_name: item.variantName,
      receipt_sku: item.sku,
      product: {
        name: item.variantName && item.variantName !== 'Default'
          ? `${item.productName} - ${item.variantName}`
          : item.productName
      },
      quantity: item.quantity,
      unit_price: Number(item.price || 0),
      subtotal: Number(item.price || 0) * Number(item.quantity || 0)
    }))
  }
}

const submitSale = async () => {
  submitting.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    if (posSettings.value.printWithoutSubmit) {
      lastReceipt.value = buildLocalReceipt()
      clearSale()
      successScreenOpen.value = true
      printReceipt()
      return
    }

    const response = await posService.checkout({
      customer_name: 'Pelanggan Offline',
      cashier_name: 'Kasir',
      payment_method: paymentMethod.value,
      cash_received: paymentMethod.value === 'cash' ? Number(cashReceived.value || 0) : total.value,
      items: cart.value.map((item) => ({
        stock_master_id: item.stockMasterId,
        quantity: item.quantity,
        unit_price: Number(item.price || 0)
      }))
    })

    const order = response.data.data
    const receipt = response.data.receipt
    lastReceipt.value = {
      ...receipt,
      total: order.total,
      items: order.items || []
    }
    clearSale()
    successScreenOpen.value = true
    await loadProducts()
    printReceipt()
  } catch (error) {
    const messages = error.response?.data?.errors
    errorMessage.value = messages ? Object.values(messages).flat().join(' ') : (error.response?.data?.message || 'Transaksi gagal diproses.')
  } finally {
    submitting.value = false
  }
}

onMounted(loadProducts)
</script>

<style scoped>
.page-shell { margin-left: 0; padding: 82px 18px 18px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 14px; }
.page-header p, .checkout-header p { color: #64748b; margin-bottom: 4px; }
.page-header h1 { font-size: 30px; }
.header-actions { display: flex; gap: 8px; }
button, input, select { font: inherit; }
button { border: 0; cursor: pointer; }
button:disabled { cursor: not-allowed; opacity: .55; }
.secondary, .danger, .pay-button { border-radius: 8px; min-height: 44px; padding: 11px 16px; font-weight: 800; }
.secondary { background: #e2e8f0; color: #0f172a; }
.danger { background: #dc2626; color: #fff; }
.full { width: 100%; }
.alert, .success { border-radius: 6px; margin-bottom: 12px; padding: 12px 14px; font-weight: 700; }
.alert { background: #fee2e2; color: #991b1b; }
.success { background: #dcfce7; color: #166534; }
.pos-grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 14px; align-items: start; }
.product-panel, .checkout-panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 16px; }
.checkout-panel { position: sticky; top: 82px; }
.catalog-layout { display: grid; gap: 14px; grid-template-columns: 210px minmax(0, 1fr); }
.category-sidebar {
  align-content: start;
  border-right: 1px solid #e2e8f0;
  display: grid;
  gap: 8px;
  max-height: calc(100vh - 188px);
  overflow: auto;
  padding-right: 12px;
}
.category-button {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  color: #0f172a;
  display: grid;
  gap: 4px;
  min-height: 68px;
  padding: 11px;
  text-align: left;
}
.category-button:hover,
.category-button.active { background: #eff6ff; border-color: #2563eb; }
.category-button strong { font-size: 14px; line-height: 1.2; }
.category-button small { color: #64748b; font-size: 11px; line-height: 1.25; }
.catalog-content { min-width: 0; }
.toolbar { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: end; margin-bottom: 12px; }
.search-box, .payment-box label, .payment-methods { display: grid; gap: 6px; color: #334155; font-size: 13px; font-weight: 800; }
.search-box input, .payment-box input, .payment-box select {
  border: 1px solid #cbd5e1;
  border-radius: 8px;
  min-height: 46px;
  padding: 10px 12px;
}
.stock-toggle { display: flex; align-items: center; gap: 8px; min-height: 40px; font-weight: 800; color: #334155; }
.product-browser { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; max-height: calc(100vh - 252px); overflow: hidden; }
.product-browser.has-variant { grid-template-columns: minmax(0, 1fr) 340px; }
.product-grid { align-content: start; display: grid; gap: 12px; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); overflow: auto; padding: 2px 4px 2px 2px; }
.product-card {
  background: #f8fafc;
  border: 1px solid #dbe4ef;
  border-radius: 8px;
  color: #0f172a;
  display: grid;
  gap: 10px;
  min-height: 230px;
  padding: 11px;
  position: relative;
  text-align: left;
  transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease;
}
.product-card:hover,
.product-card.active { border-color: #2563eb; box-shadow: 0 10px 22px rgba(37, 99, 235, .12); transform: translateY(-1px); }
.product-image, .variant-image {
  align-items: center;
  background: #e2e8f0;
  border-radius: 7px;
  color: #475569;
  display: flex;
  font-size: 28px;
  font-weight: 900;
  justify-content: center;
  overflow: hidden;
}
.product-image { aspect-ratio: 1 / 1; width: 100%; }
.product-image img, .variant-image img { height: 100%; object-fit: cover; width: 100%; }
.product-card-body { display: grid; gap: 4px; }
.product-card strong, .variant-row strong, .cart-item strong { display: block; line-height: 1.25; }
.product-card small, .variant-row small, .cart-item small, .cart-item span { color: #64748b; display: block; margin-top: 2px; }
.product-card b, .variant-row b { color: #15803d; font-size: 14px; white-space: nowrap; }
.stock-badge { background: #ecfdf5; border-radius: 999px; color: #047857; font-size: 12px; font-weight: 900; padding: 5px 8px; position: absolute; right: 10px; top: 10px; }
.variant-panel { border-left: 1px solid #e2e8f0; display: grid; grid-template-rows: auto minmax(0, 1fr); min-width: 0; padding-left: 14px; }
.variant-header { align-items: start; border-bottom: 1px solid #e2e8f0; display: flex; gap: 10px; margin-bottom: 10px; padding-bottom: 10px; }
.variant-header p { color: #64748b; font-weight: 800; margin-bottom: 3px; }
.variant-header h2 { font-size: 18px; line-height: 1.25; }
.back-button { background: #e2e8f0; border-radius: 6px; color: #0f172a; font-size: 12px; font-weight: 900; padding: 8px 10px; }
.variant-list { display: grid; gap: 8px; overflow: auto; padding-right: 4px; }
.variant-row {
  align-items: center;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  color: #0f172a;
  display: grid;
  gap: 10px;
  grid-template-columns: 58px minmax(0, 1fr) auto;
  min-height: 82px;
  padding: 10px;
  text-align: left;
}
.variant-row:hover:not(:disabled) { background: #eff6ff; border-color: #2563eb; }
.variant-image { height: 58px; width: 58px; }
.cart-summary-button {
  align-items: center;
  background: #ea580c;
  border-radius: 10px;
  color: #fff;
  display: grid;
  gap: 10px;
  grid-template-columns: 48px auto minmax(0, 1fr) 22px;
  min-height: 64px;
  padding: 10px 12px;
  text-align: left;
  width: 100%;
}
.cart-summary-button span {
  align-items: center;
  background: rgba(255, 255, 255, .16);
  border-radius: 8px;
  display: flex;
  font-size: 22px;
  font-weight: 900;
  height: 44px;
  justify-content: center;
}
.cart-summary-button small { font-size: 15px; font-weight: 800; opacity: .9; }
.cart-summary-button strong { font-size: 22px; justify-self: end; white-space: nowrap; }
.cart-summary-button b { font-size: 30px; line-height: 1; text-align: right; }
.cart-list { display: grid; gap: 10px; max-height: 34vh; overflow: auto; padding-right: 4px; }
.cart-item {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 118px 58px;
  gap: 10px;
  padding: 10px;
}
.qty-control { display: grid; grid-template-columns: 32px 1fr 32px; gap: 4px; align-items: center; }
.qty-control button, .remove { border-radius: 8px; min-height: 40px; }
.qty-control button { background: #1f2937; color: #fff; font-weight: 900; }
.qty-control input { border: 1px solid #cbd5e1; border-radius: 6px; min-width: 0; padding: 6px; text-align: center; }
.remove { background: #fee2e2; color: #991b1b; font-size: 12px; font-weight: 900; }
.inline-price { color: #334155; display: grid; font-size: 12px; font-weight: 800; gap: 4px; margin-top: 8px; max-width: 150px; }
.inline-price input { border: 1px solid #cbd5e1; border-radius: 6px; min-height: 34px; padding: 6px 8px; width: 100%; }
.payment-box { display: grid; gap: 12px; margin-top: 14px; }
.payment-choice-grid { display: grid; gap: 10px; grid-template-columns: 1fr 1fr; }
.payment-choice {
  align-items: center;
  background: #f8fafc;
  border: 2px solid #dbe4ef;
  border-radius: 10px;
  color: #0f172a;
  display: grid;
  gap: 6px;
  justify-items: center;
  min-height: 86px;
  padding: 12px 8px;
}
.payment-choice img {
  align-items: center;
  background: #fff;
  border-radius: 12px;
  display: flex;
  height: 48px;
  justify-content: center;
  max-width: 92px;
  object-fit: contain;
  padding: 6px;
  width: 92px;
}
.payment-choice span { font-size: 15px; font-weight: 900; }
.payment-choice.active { background: #eff6ff; border-color: #0f5fc7; box-shadow: 0 10px 20px rgba(15, 95, 199, .12); }
.payment-choice.active img { box-shadow: inset 0 0 0 2px rgba(15, 95, 199, .16); }
.totals { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; display: grid; grid-template-columns: 1fr auto; gap: 8px; padding: 12px; }
.totals span { color: #475569; font-weight: 700; }
.totals strong { font-size: 18px; }
.pay-button { background: #15803d; color: #fff; min-height: 50px; width: 100%; }
.empty { color: #64748b; padding: 18px; text-align: center; }
.keypad-backdrop,
.cart-backdrop,
.settings-backdrop,
.success-backdrop {
  align-items: center;
  background: rgba(15, 23, 42, .42);
  display: flex;
  inset: 0;
  justify-content: center;
  padding: 18px;
  position: fixed;
  z-index: 60;
}
.cash-keypad,
.cart-popup,
.settings-popup,
.success-screen {
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 24px 60px rgba(15, 23, 42, .28);
  display: grid;
  gap: 12px;
  max-width: 420px;
  padding: 16px;
  width: min(100%, 420px);
}
.cart-popup { max-width: 620px; width: min(100%, 620px); }
.settings-popup { max-width: 560px; width: min(100%, 560px); }
.settings-header {
  align-items: start;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 12px;
}
.settings-header p { color: #64748b; font-weight: 800; margin-bottom: 3px; }
.settings-header h2 { font-size: 24px; }
.settings-option {
  align-items: start;
  background: #f8fafc;
  border: 1px solid #dbe4ef;
  border-radius: 10px;
  color: #0f172a;
  display: grid;
  gap: 12px;
  grid-template-columns: 28px minmax(0, 1fr);
  min-height: 76px;
  padding: 13px;
}
.settings-option input {
  height: 24px;
  margin: 2px 0 0;
  width: 24px;
}
.settings-option strong { display: block; font-size: 15px; line-height: 1.25; }
.settings-option small { color: #64748b; display: block; font-size: 12px; line-height: 1.35; margin-top: 4px; }
.settings-note {
  background: #fff7ed;
  border: 1px solid #fed7aa;
  border-radius: 10px;
  color: #9a3412;
  font-size: 13px;
  font-weight: 800;
  line-height: 1.35;
  padding: 12px;
}
.success-screen {
  gap: 16px;
  justify-items: center;
  max-width: 360px;
  padding: 24px 20px 18px;
  text-align: center;
}
.success-icon {
  align-items: center;
  background: #43a047;
  border-radius: 12px;
  color: #fff;
  display: flex;
  font-size: 34px;
  font-weight: 900;
  height: 62px;
  justify-content: center;
  width: 62px;
}
.success-screen h2 {
  color: #9a6512;
  font-size: 32px;
  margin: 0;
}
.success-summary {
  border-bottom: 1px dashed #cbd5e1;
  display: grid;
  gap: 10px 18px;
  grid-template-columns: 1fr auto;
  padding: 4px 0 14px;
  text-align: left;
  width: 100%;
}
.success-summary dt { color: #334155; font-size: 17px; }
.success-summary dd { color: #0f172a; font-size: 18px; margin: 0; text-align: right; }
.success-summary dt:last-of-type,
.success-summary dd:last-of-type { font-size: 22px; padding-top: 8px; }
.printing-status {
  align-items: center;
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 10px;
  color: #0f5fc7;
  display: flex;
  gap: 10px;
  justify-content: center;
  min-height: 46px;
  padding: 8px 12px;
  width: 100%;
}
.printing-status span {
  animation: printSpin .8s linear infinite;
  border: 4px solid #bfdbfe;
  border-top-color: #0f5fc7;
  border-radius: 999px;
  height: 26px;
  width: 26px;
}
.printing-status strong { font-size: 14px; }
.success-actions { display: grid; gap: 10px; grid-template-columns: 1fr; width: 100%; }
.print-toast {
  background: #15803d;
  border-radius: 999px;
  bottom: 22px;
  box-shadow: 0 14px 32px rgba(15, 23, 42, .22);
  color: #fff;
  font-size: 15px;
  font-weight: 900;
  left: 50%;
  padding: 12px 18px;
  position: fixed;
  transform: translateX(-50%);
  z-index: 80;
}
@keyframes printSpin {
  to { transform: rotate(360deg); }
}
.cart-popup-header {
  align-items: start;
  border-bottom: 1px solid #e2e8f0;
  display: grid;
  gap: 12px;
  grid-template-columns: minmax(0, 1fr) auto auto;
  padding-bottom: 12px;
}
.cart-popup-header p { color: #64748b; font-weight: 800; margin-bottom: 3px; }
.cart-popup-header h2 { font-size: 24px; }
.cart-popup-header strong { color: #15803d; font-size: 24px; white-space: nowrap; }
.cart-popup-close { background: #e2e8f0; border-radius: 8px; color: #0f172a; font-weight: 900; min-height: 42px; padding: 8px 12px; }
.popup-cart-list { max-height: min(58vh, 520px); padding-right: 2px; }
.keypad-header { align-items: start; display: flex; justify-content: space-between; gap: 12px; }
.keypad-header p { color: #64748b; font-weight: 800; margin-bottom: 4px; }
.keypad-header strong { color: #15803d; font-size: 30px; line-height: 1.1; }
.keypad-close { background: #e2e8f0; border-radius: 8px; color: #0f172a; font-weight: 900; min-height: 42px; padding: 8px 12px; }
.exact-pay {
  background: #0f5fc7;
  border-radius: 10px;
  color: #fff;
  font-size: 18px;
  font-weight: 900;
  min-height: 58px;
}
.quick-cash { display: grid; gap: 8px; grid-template-columns: repeat(4, 1fr); }
.quick-cash button {
  background: #ecfdf5;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  color: #047857;
  font-size: 14px;
  font-weight: 900;
  min-height: 48px;
}
.number-pad { display: grid; gap: 8px; grid-template-columns: repeat(3, 1fr); }
.number-pad button {
  background: #f8fafc;
  border: 1px solid #dbe4ef;
  border-radius: 10px;
  color: #0f172a;
  font-size: 24px;
  font-weight: 900;
  min-height: 64px;
}
.number-pad button:active,
.quick-cash button:active,
.exact-pay:active,
.apply-cash:active { transform: scale(.98); }
.number-pad .muted { background: #fee2e2; border-color: #fecaca; color: #991b1b; font-size: 15px; }
.apply-cash {
  background: #15803d;
  border-radius: 10px;
  color: #fff;
  font-size: 18px;
  font-weight: 900;
  min-height: 56px;
}
.receipt-print { display: none; }

@media (max-width: 1100px) {
  .pos-grid { grid-template-columns: 1fr; }
  .checkout-panel { position: static; }
  .category-sidebar { max-height: none; }
  .product-browser { max-height: none; }
}

@media (max-width: 820px) {
  .page-shell { padding: 86px 16px 16px; }
  .page-header, .toolbar { grid-template-columns: 1fr; align-items: stretch; display: grid; }
  .catalog-layout { grid-template-columns: 1fr; }
  .category-sidebar { border-right: 0; display: flex; overflow-x: auto; padding-bottom: 4px; padding-right: 0; }
  .category-button { flex: 0 0 180px; }
  .product-browser { grid-template-columns: 1fr; overflow: visible; }
  .product-grid, .variant-list { max-height: none; overflow: visible; }
  .variant-panel { border-left: 0; border-top: 1px solid #e2e8f0; padding-left: 0; padding-top: 12px; }
  .variant-row { grid-template-columns: 52px minmax(0, 1fr); }
  .variant-row b { grid-column: 2; }
  .cart-item { grid-template-columns: 1fr; }
  .quick-cash { grid-template-columns: repeat(2, 1fr); }
}

@media print {
  @page {
    size: 58mm auto;
    margin: 0;
  }

  html,
  body {
    margin: 0;
    padding: 0;
    width: 58mm;
  }

  body > *:not(.receipt-print) {
    display: none !important;
  }

  body * { visibility: hidden !important; }
  .receipt-print,
  .receipt-print * {
    visibility: visible !important;
  }
  .receipt-print {
    display: block !important;
    left: 0;
    min-height: 100mm;
    position: absolute;
    top: 0;
    width: 58mm;
    background: #fff;
    color: #000;
    padding: 0;
  }
  .receipt {
    box-sizing: border-box;
    width: 58mm;
    min-height: 100mm;
    padding: 4mm 3mm;
    font-family: Arial, sans-serif;
    font-size: 10px;
    line-height: 1.25;
  }
  .receipt-logo { display: block; height: 15mm; margin: 0 auto 2mm; object-fit: contain; width: 15mm; }
  .receipt h2 { font-size: 13px; text-align: center; margin: 0 0 2px; }
  .receipt p { font-size: 8.5px; line-height: 1.25; text-align: center; margin: 3px 0 6px; }
  .receipt dl { display: grid; grid-template-columns: 18mm 1fr; gap: 2px 4px; margin: 6px 0; }
  .receipt dd { margin: 0; text-align: right; }
  .receipt table { border-collapse: collapse; width: 100%; }
  .receipt td { border-top: 1px dashed #777; padding: 4px 0; vertical-align: top; }
  .receipt td:first-child { padding-right: 4px; }
  .receipt td:last-child { text-align: right; white-space: nowrap; width: 18mm; }
  .receipt small { display: block; margin-top: 2px; }
  .receipt-total { border-top: 1px dashed #777; padding-top: 5px; }
}
</style>
