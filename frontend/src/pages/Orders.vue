<template>
  <div class="pages-orders">
    <div class="container">
      <h1>Pesanan Saya</h1>

      <div v-if="loading" class="loading">Memuat pesanan...</div>

      <div v-else-if="orders.length === 0" class="no-orders">
        <p>Anda belum memiliki pesanan.</p>
        <RouterLink to="/products" class="btn-shop">Mulai Belanja</RouterLink>
      </div>

      <div v-else class="orders-list">
        <div v-for="order in orders" :key="order.uuid" class="order-card">
          <div class="order-header">
            <div>
              <h3>{{ order.order_number }}</h3>
              <p class="order-date">{{ formatDate(order.created_at) }}</p>
            </div>
            <div class="order-status" :class="order.status">
              {{ order.status }}
            </div>
          </div>

          <table class="order-items">
            <thead>
              <tr>
                <th>Produk</th>
                <th>Harga</th>
                <th>Jumlah</th>
                <th>Subtotal</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="item in order.items" :key="item.uuid">
                <td>{{ item.product.name }}</td>
                <td>Rp {{ formatPrice(item.unit_price) }}</td>
                <td>{{ item.quantity }}</td>
                <td>Rp {{ formatPrice(item.subtotal) }}</td>
              </tr>
            </tbody>
          </table>

          <div class="order-total">
            <strong>Total: Rp {{ formatPrice(order.total) }}</strong>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { orderService } from '@/services'

const orders = ref([])
const loading = ref(false)

const fetchOrders = async () => {
  loading.value = true
  try {
    const { data } = await orderService.getAll(1, 100)
    orders.value = data.data
  } catch (error) {
    console.error('Error fetching orders:', error)
  } finally {
    loading.value = false
  }
}

const formatPrice = (price) => {
  return new Intl.NumberFormat('id-ID').format(price)
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('id-ID', {
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

onMounted(fetchOrders)
</script>

<style scoped>
.pages-orders {
  padding: 2rem 0;
  min-height: calc(100vh - 60px);
}

.container {
  max-width: 1000px;
  margin: 0 auto;
  padding: 0 2rem;
}

.pages-orders h1 {
  margin-bottom: 2rem;
  color: #2c3e50;
}

.loading,
.no-orders {
  text-align: center;
  padding: 2rem;
  color: #666;
}

.btn-shop {
  display: inline-block;
  margin-top: 1rem;
  padding: 0.75rem 1.5rem;
  background-color: #3498db;
  color: white;
  text-decoration: none;
  border-radius: 4px;
  transition: background 0.2s;
}

.btn-shop:hover {
  background-color: #2980b9;
}

.orders-list {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.order-card {
  border: 1px solid #ddd;
  border-radius: 8px;
  overflow: hidden;
}

.order-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background-color: #f9f9f9;
  padding: 1rem;
  border-bottom: 1px solid #ddd;
}

.order-header h3 {
  margin: 0 0 0.5rem;
  color: #2c3e50;
}

.order-date {
  margin: 0;
  font-size: 0.9rem;
  color: #666;
}

.order-status {
  padding: 0.5rem 1rem;
  border-radius: 4px;
  font-weight: bold;
  font-size: 0.9rem;
}

.order-status.pending {
  background-color: #f39c12;
  color: white;
}

.order-status.completed {
  background-color: #27ae60;
  color: white;
}

.order-items {
  width: 100%;
  border-collapse: collapse;
  padding: 1rem;
}

.order-items th {
  background-color: #f0f0f0;
  padding: 0.75rem;
  text-align: left;
  border-bottom: 1px solid #ddd;
  font-weight: 600;
}

.order-items td {
  padding: 0.75rem;
  border-bottom: 1px solid #eee;
}

.order-total {
  padding: 1rem;
  background-color: #f9f9f9;
  border-top: 1px solid #ddd;
  text-align: right;
  font-size: 1.1rem;
  color: #2c3e50;
}
</style>
