<template>
  <div class="pages-cart">
    <div class="container">
      <h1>Keranjang Belanja</h1>

      <div v-if="cartStore.loading" class="loading">Memuat keranjang...</div>

      <div v-else-if="cartStore.items.length === 0" class="empty-cart">
        <p>Keranjang Anda kosong.</p>
        <RouterLink to="/products" class="btn-continue-shopping">
          Lanjut Belanja
        </RouterLink>
      </div>

      <div v-else class="cart-content">
        <table class="cart-table">
          <thead>
            <tr>
              <th>Produk</th>
              <th>Harga</th>
              <th>Jumlah</th>
              <th>Subtotal</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in cartStore.items" :key="item.uuid">
              <td>{{ item.product.name }}</td>
              <td>Rp {{ formatPrice(item.unit_price) }}</td>
              <td>
                <input 
                  type="number" 
                  v-model.number="item.quantity" 
                  min="1"
                  @change="handleUpdateItem(item)"
                  class="qty-input"
                />
              </td>
              <td>Rp {{ formatPrice(item.unit_price * item.quantity) }}</td>
              <td>
                <button @click="handleRemoveItem(item.uuid)" class="btn-remove">
                  Hapus
                </button>
              </td>
            </tr>
          </tbody>
        </table>

        <div class="cart-summary">
          <div class="summary-row">
            <span>Total:</span>
            <span class="total-price">Rp {{ formatPrice(cartStore.total) }}</span>
          </div>

          <RouterLink to="/checkout" class="btn-checkout">Lanjut checkout</RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useCartStore } from '@/stores/cartStore'

const cartStore = useCartStore()

const formatPrice = (price) => {
  return new Intl.NumberFormat('id-ID').format(price)
}

const handleUpdateItem = async (item) => {
  try {
    await cartStore.updateItem(item.uuid, item.quantity)
  } catch (error) {
    alert('Gagal memperbarui item keranjang.')
  }
}

const handleRemoveItem = async (itemId) => {
  if (confirm('Hapus item ini?')) {
    try {
      await cartStore.removeItem(itemId)
    } catch (error) {
      alert('Gagal menghapus item.')
    }
  }
}

onMounted(() => {
  if (cartStore.items.length === 0) {
    cartStore.fetchCart()
  }
})
</script>

<style scoped>
.pages-cart {
  padding: 2rem 0;
  min-height: calc(100vh - 60px);
}

.container {
  max-width: 1000px;
  margin: 0 auto;
  padding: 0 2rem;
}

.pages-cart h1 {
  margin-bottom: 2rem;
  color: #2c3e50;
}

.loading,
.empty-cart {
  text-align: center;
  padding: 2rem;
  color: #666;
}

.btn-continue-shopping {
  display: inline-block;
  margin-top: 1rem;
  padding: 0.75rem 1.5rem;
  background-color: #3498db;
  color: white;
  text-decoration: none;
  border-radius: 4px;
  transition: background 0.2s;
}

.btn-continue-shopping:hover {
  background-color: #2980b9;
}

.cart-table {
  width: 100%;
  border-collapse: collapse;
  margin-bottom: 2rem;
}

.cart-table th {
  background-color: #f5f5f5;
  padding: 0.75rem;
  text-align: left;
  border-bottom: 2px solid #ddd;
}

.cart-table td {
  padding: 0.75rem;
  border-bottom: 1px solid #ddd;
}

.qty-input {
  width: 60px;
  padding: 0.5rem;
  border: 1px solid #ddd;
  border-radius: 4px;
}

.btn-remove {
  padding: 0.5rem 1rem;
  background-color: #e74c3c;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
}

.btn-remove:hover {
  background-color: #c0392b;
}

.cart-summary {
  background-color: #f9f9f9;
  padding: 1.5rem;
  border-radius: 8px;
}

.summary-row {
  display: flex;
  justify-content: space-between;
  margin-bottom: 1.5rem;
  font-size: 1.25rem;
  font-weight: bold;
}

.total-price {
  color: #e74c3c;
}

.checkout-form {
  border-top: 1px solid #ddd;
  padding-top: 1.5rem;
}

.form-group {
  margin-bottom: 1rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-weight: 500;
  color: #333;
}

.form-group textarea,
.form-group select {
  width: 100%;
  padding: 0.75rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 1rem;
  box-sizing: border-box;
}

.form-group textarea:focus,
.form-group select:focus {
  outline: none;
  border-color: #3498db;
}

.btn-checkout {
  display: block;
  text-align: center;
  text-decoration: none;
  width: 100%;
  padding: 0.75rem;
  background-color: #27ae60;
  color: white;
  border: none;
  border-radius: 4px;
  font-size: 1rem;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-checkout:hover:not(:disabled) {
  background-color: #229954;
}

.btn-checkout:disabled {
  background-color: #bdc3c7;
  cursor: not-allowed;
}
</style>
