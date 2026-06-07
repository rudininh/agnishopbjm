<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Kasir Toko</p>
        <h1>POS Offline</h1>
      </div>
      <div class="header-actions">
        <button class="secondary" @click="loadProducts" :disabled="loading">
          {{ loading ? 'Memuat...' : 'Refresh Produk' }}
        </button>
        <button class="danger" @click="clearSale" :disabled="!cart.length || submitting">Reset</button>
      </div>
    </header>

    <div v-if="errorMessage" class="alert">{{ errorMessage }}</div>
    <div v-if="successMessage" class="success">{{ successMessage }}</div>

    <div class="pos-grid">
      <section class="product-panel">
        <div class="toolbar">
          <label class="search-box">
            <span>Cari</span>
            <input v-model="search" type="search" placeholder="Nama produk atau SKU" autocomplete="off" />
          </label>
          <label class="stock-toggle">
            <input v-model="onlyAvailable" type="checkbox" />
            Stok tersedia
          </label>
        </div>

        <div class="product-list">
          <button
            v-for="product in filteredProducts"
            :key="product.uuid"
            class="product-row"
            :disabled="Number(product.stock || 0) <= 0"
            @click="addToCart(product)"
          >
            <span>
              <strong>{{ product.name }}</strong>
              <small>{{ product.sku || 'Tanpa SKU' }}</small>
            </span>
            <span class="product-meta">
              <b>{{ formatRupiah(product.price) }}</b>
              <small>Stok {{ product.stock }}</small>
            </span>
          </button>

          <p v-if="!filteredProducts.length" class="empty">Produk tidak ditemukan.</p>
        </div>
      </section>

      <aside class="checkout-panel">
        <div class="checkout-header">
          <div>
            <p>Transaksi</p>
            <h2>{{ cart.length }} item</h2>
          </div>
          <strong>{{ formatRupiah(total) }}</strong>
        </div>

        <div class="cart-list">
          <article v-for="item in cart" :key="item.uuid" class="cart-item">
            <div>
              <strong>{{ item.name }}</strong>
              <small>{{ item.sku }}</small>
              <span>{{ formatRupiah(item.price) }}</span>
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
            <button class="remove" @click="removeItem(item.uuid)">Hapus</button>
          </article>

          <p v-if="!cart.length" class="empty">Keranjang masih kosong.</p>
        </div>

        <div class="payment-box">
          <label>
            Nama pelanggan
            <input v-model="customerName" type="text" placeholder="Pelanggan Offline" />
          </label>

          <label>
            Nama kasir
            <input v-model="cashierName" type="text" placeholder="Kasir" />
          </label>

          <label>
            Metode bayar
            <select v-model="paymentMethod">
              <option value="cash">Tunai</option>
              <option value="qris">QRIS</option>
              <option value="debit">Debit</option>
              <option value="transfer">Transfer</option>
            </select>
          </label>

          <label>
            Uang diterima
            <input v-model.number="cashReceived" type="number" min="0" :disabled="paymentMethod !== 'cash'" />
          </label>

          <div class="totals">
            <span>Total</span>
            <strong>{{ formatRupiah(total) }}</strong>
            <span>Kembali</span>
            <strong>{{ formatRupiah(change) }}</strong>
          </div>

          <button class="pay-button" @click="submitSale" :disabled="!canPay || submitting">
            {{ submitting ? 'Memproses...' : 'Bayar & Cetak Nota' }}
          </button>
          <button class="secondary full" @click="printReceipt" :disabled="!lastReceipt">Cetak Ulang Nota</button>
        </div>
      </aside>
    </div>
  </section>

  <section v-if="lastReceipt" class="receipt-print" aria-hidden="true">
    <div class="receipt">
      <h2>AGNI SHOP BJM</h2>
      <p>Nota POS Offline</p>
      <dl>
        <dt>No</dt>
        <dd>{{ lastReceipt.order_number }}</dd>
        <dt>Tanggal</dt>
        <dd>{{ formatDate(lastReceipt.created_at) }}</dd>
        <dt>Kasir</dt>
        <dd>{{ lastReceipt.cashier_name }}</dd>
        <dt>Pelanggan</dt>
        <dd>{{ lastReceipt.customer_name }}</dd>
      </dl>
      <table>
        <tbody>
          <tr v-for="item in lastReceipt.items" :key="item.uuid">
            <td>
              <strong>{{ item.product.name }}</strong>
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
import { productService, posService } from '@/services'

const products = ref([])
const cart = ref([])
const search = ref('')
const onlyAvailable = ref(true)
const loading = ref(false)
const submitting = ref(false)
const errorMessage = ref('')
const successMessage = ref('')
const customerName = ref('')
const cashierName = ref('Kasir')
const paymentMethod = ref('cash')
const cashReceived = ref(0)
const lastReceipt = ref(null)

const formatRupiah = (value) => new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0
}).format(Number(value || 0))

const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short'
}).format(new Date(value)) : '-'

const formatPayment = (method) => ({
  cash: 'Tunai',
  qris: 'QRIS',
  debit: 'Debit',
  transfer: 'Transfer'
}[method] || method)

const total = computed(() => cart.value.reduce((sum, item) => sum + (item.price * item.quantity), 0))
const change = computed(() => paymentMethod.value === 'cash' ? Math.max(0, Number(cashReceived.value || 0) - total.value) : 0)
const canPay = computed(() => cart.value.length > 0 && total.value > 0 && (paymentMethod.value !== 'cash' || Number(cashReceived.value || 0) >= total.value))

const filteredProducts = computed(() => {
  const keyword = search.value.trim().toLowerCase()
  return products.value.filter((product) => {
    const matchesKeyword = !keyword
      || product.name?.toLowerCase().includes(keyword)
      || product.sku?.toLowerCase().includes(keyword)
    const matchesStock = !onlyAvailable.value || Number(product.stock || 0) > 0
    return matchesKeyword && matchesStock
  })
})

const loadProducts = async () => {
  loading.value = true
  errorMessage.value = ''
  try {
    const response = await productService.getAll(1, 100)
    products.value = response.data.data || []
  } catch (error) {
    errorMessage.value = error.response?.data?.message || 'Gagal memuat produk.'
  } finally {
    loading.value = false
  }
}

const addToCart = (product) => {
  const existing = cart.value.find((item) => item.uuid === product.uuid)
  if (existing) {
    increaseQty(existing)
    return
  }

  cart.value.push({
    uuid: product.uuid,
    name: product.name,
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
    removeItem(item.uuid)
    return
  }
  item.quantity -= 1
}

const removeItem = (uuid) => {
  cart.value = cart.value.filter((item) => item.uuid !== uuid)
}

const clearSale = () => {
  cart.value = []
  customerName.value = ''
  paymentMethod.value = 'cash'
  cashReceived.value = 0
  errorMessage.value = ''
  successMessage.value = ''
}

const printReceipt = () => {
  if (!lastReceipt.value) return
  window.setTimeout(() => window.print(), 60)
}

const submitSale = async () => {
  submitting.value = true
  errorMessage.value = ''
  successMessage.value = ''
  try {
    const response = await posService.checkout({
      customer_name: customerName.value || 'Pelanggan Offline',
      cashier_name: cashierName.value || 'Kasir',
      payment_method: paymentMethod.value,
      cash_received: paymentMethod.value === 'cash' ? Number(cashReceived.value || 0) : total.value,
      items: cart.value.map((item) => ({
        product_id: item.uuid,
        quantity: item.quantity
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
    successMessage.value = `Transaksi ${order.order_number} berhasil.`
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
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display: flex; justify-content: space-between; align-items: center; gap: 16px; margin-bottom: 14px; }
.page-header p, .checkout-header p { color: #64748b; margin-bottom: 4px; }
.page-header h1 { font-size: 30px; }
.header-actions { display: flex; gap: 8px; }
button, input, select { font: inherit; }
button { border: 0; cursor: pointer; }
button:disabled { cursor: not-allowed; opacity: .55; }
.secondary, .danger, .pay-button { border-radius: 6px; padding: 10px 14px; font-weight: 800; }
.secondary { background: #e2e8f0; color: #0f172a; }
.danger { background: #dc2626; color: #fff; }
.full { width: 100%; }
.alert, .success { border-radius: 6px; margin-bottom: 12px; padding: 12px 14px; font-weight: 700; }
.alert { background: #fee2e2; color: #991b1b; }
.success { background: #dcfce7; color: #166534; }
.pos-grid { display: grid; grid-template-columns: minmax(0, 1fr) 420px; gap: 16px; align-items: start; }
.product-panel, .checkout-panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 16px; }
.toolbar { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 12px; align-items: end; margin-bottom: 12px; }
.search-box, .payment-box label { display: grid; gap: 6px; color: #334155; font-size: 13px; font-weight: 800; }
.search-box input, .payment-box input, .payment-box select {
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  min-height: 40px;
  padding: 9px 10px;
}
.stock-toggle { display: flex; align-items: center; gap: 8px; min-height: 40px; font-weight: 800; color: #334155; }
.product-list { display: grid; gap: 8px; max-height: calc(100vh - 210px); overflow: auto; }
.product-row {
  align-items: center;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  color: #0f172a;
  display: flex;
  justify-content: space-between;
  gap: 12px;
  min-height: 70px;
  padding: 12px;
  text-align: left;
}
.product-row:hover:not(:disabled) { border-color: #2563eb; background: #eff6ff; }
.product-row strong, .cart-item strong { display: block; line-height: 1.25; }
.product-row small, .cart-item small, .cart-item span { color: #64748b; display: block; margin-top: 4px; }
.product-meta { text-align: right; white-space: nowrap; }
.product-meta b { color: #15803d; display: block; }
.checkout-header { align-items: start; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; margin-bottom: 12px; padding-bottom: 12px; }
.checkout-header h2 { font-size: 22px; }
.checkout-header > strong { color: #15803d; font-size: 22px; white-space: nowrap; }
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
.qty-control button, .remove { border-radius: 6px; min-height: 32px; }
.qty-control button { background: #1f2937; color: #fff; font-weight: 900; }
.qty-control input { border: 1px solid #cbd5e1; border-radius: 6px; min-width: 0; padding: 6px; text-align: center; }
.remove { background: #fee2e2; color: #991b1b; font-size: 12px; font-weight: 900; }
.payment-box { border-top: 1px solid #e2e8f0; display: grid; gap: 12px; margin-top: 14px; padding-top: 14px; }
.totals { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; display: grid; grid-template-columns: 1fr auto; gap: 8px; padding: 12px; }
.totals span { color: #475569; font-weight: 700; }
.totals strong { font-size: 18px; }
.pay-button { background: #15803d; color: #fff; min-height: 46px; width: 100%; }
.empty { color: #64748b; padding: 18px; text-align: center; }
.receipt-print { display: none; }

@media (max-width: 1100px) {
  .pos-grid { grid-template-columns: 1fr; }
  .product-list { max-height: 48vh; }
}

@media (max-width: 820px) {
  .page-shell { margin-left: 0; padding: 16px; }
  .page-header, .toolbar { grid-template-columns: 1fr; align-items: stretch; display: grid; }
  .cart-item { grid-template-columns: 1fr; }
}

@media print {
  body * { visibility: hidden; }
  .receipt-print, .receipt-print * { visibility: visible; }
  .receipt-print {
    display: block;
    position: fixed;
    inset: 0;
    background: #fff;
    color: #000;
    padding: 0;
  }
  .receipt {
    width: 72mm;
    padding: 8mm 5mm;
    font-family: Arial, sans-serif;
    font-size: 11px;
  }
  .receipt h2 { font-size: 16px; text-align: center; margin-bottom: 2px; }
  .receipt p { text-align: center; margin: 4px 0 8px; }
  .receipt dl { display: grid; grid-template-columns: 22mm 1fr; gap: 3px 6px; margin: 8px 0; }
  .receipt dd { margin: 0; text-align: right; }
  .receipt table { border-collapse: collapse; width: 100%; }
  .receipt td { border-top: 1px dashed #777; padding: 6px 0; vertical-align: top; }
  .receipt td:last-child { text-align: right; white-space: nowrap; }
  .receipt small { display: block; margin-top: 2px; }
  .receipt-total { border-top: 1px dashed #777; padding-top: 6px; }
}
</style>
