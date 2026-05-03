<template>
  <section class="page">
    <div class="container">
      <h1>Checkout</h1>
      <div class="layout">
        <form class="panel" @submit.prevent="handleCheckout">
          <label>
            Alamat pengiriman
            <textarea v-model="form.shipping_address" required rows="5" />
          </label>
          <label>
            Metode pembayaran
            <select v-model="form.payment_method" required>
              <option value="">Pilih metode</option>
              <option value="bank_transfer">Transfer Bank</option>
              <option value="cash_on_delivery">Bayar di Tempat</option>
            </select>
          </label>
          <button :disabled="loading || cartStore.items.length === 0">
            {{ loading ? 'Memproses...' : 'Buat order' }}
          </button>
          <p v-if="error" class="error">{{ error }}</p>
        </form>

        <aside class="panel">
          <h2>Ringkasan</h2>
          <div v-for="item in cartStore.items" :key="item.uuid" class="item">
            <span>{{ item.product.name }} x {{ item.quantity }}</span>
            <strong>Rp {{ formatPrice(item.subtotal) }}</strong>
          </div>
          <div class="total">
            <span>Total</span>
            <strong>Rp {{ formatPrice(cartStore.total) }}</strong>
          </div>
        </aside>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { orderService } from '@/services'
import { useCartStore } from '@/stores/cartStore'

const router = useRouter()
const cartStore = useCartStore()
const loading = ref(false)
const error = ref('')
const form = reactive({ shipping_address: '', payment_method: '' })

const formatPrice = (price) => new Intl.NumberFormat('id-ID').format(price || 0)

const handleCheckout = async () => {
  loading.value = true
  error.value = ''
  try {
    await orderService.checkout(form)
    cartStore.clear()
    router.push('/orders')
  } catch (err) {
    error.value = err.response?.data?.message || 'Checkout gagal.'
  } finally {
    loading.value = false
  }
}

onMounted(cartStore.fetchCart)
</script>

<style scoped>
.page { padding: 2rem 0; }
.container { max-width: 1100px; margin: 0 auto; padding: 0 2rem; }
h1 { margin-bottom: 1rem; color: #25313d; }
.layout { display: grid; grid-template-columns: 1.4fr .9fr; gap: 1rem; }
.panel { background: white; border: 1px solid #e3e8ef; border-radius: 8px; padding: 1rem; }
label { display: grid; gap: .5rem; margin-bottom: 1rem; font-weight: 600; color: #334155; }
textarea, select { border: 1px solid #cbd5e1; border-radius: 6px; padding: .75rem; font: inherit; }
button { width: 100%; border: 0; border-radius: 6px; padding: .8rem; background: #16a34a; color: white; font-weight: 700; cursor: pointer; }
button:disabled { background: #94a3b8; cursor: not-allowed; }
.item, .total { display: flex; justify-content: space-between; gap: 1rem; padding: .75rem 0; border-bottom: 1px solid #e2e8f0; }
.total { border-bottom: 0; font-size: 1.1rem; }
.error { color: #c62828; margin-top: .75rem; }
@media (max-width: 820px) { .layout { grid-template-columns: 1fr; } }
</style>
