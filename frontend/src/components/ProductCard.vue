<template>
  <div class="product-card">
    <div class="product-image">
      <img :src="product.image || 'https://via.placeholder.com/200'" :alt="product.name" />
    </div>
    <div class="product-content">
      <h3>{{ product.name }}</h3>
      <p class="product-sku">SKU: {{ product.sku }}</p>
      <p class="product-price">Rp {{ formatPrice(product.price) }}</p>
      <p class="product-stock" :class="{ 'out-of-stock': product.stock === 0 }">
        Stock: {{ product.stock > 0 ? product.stock : 'Habis' }}
      </p>
      <p class="product-category">{{ product.category?.name }}</p>
      <button 
        @click="$emit('add')" 
        :disabled="product.stock === 0"
        class="btn-add-cart"
      >
        {{ product.stock > 0 ? 'Tambah ke Keranjang' : 'Stok Habis' }}
      </button>
    </div>
  </div>
</template>

<script setup>
defineProps({
  product: {
    type: Object,
    required: true
  }
})

defineEmits(['add'])

const formatPrice = (price) => {
  return new Intl.NumberFormat('id-ID').format(price)
}
</script>

<style scoped>
.product-card {
  border: 1px solid #ddd;
  border-radius: 8px;
  overflow: hidden;
  transition: box-shadow 0.3s;
}

.product-card:hover {
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
}

.product-image {
  width: 100%;
  height: 200px;
  overflow: hidden;
  background-color: #f5f5f5;
}

.product-image img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.product-content {
  padding: 1rem;
}

.product-content h3 {
  margin: 0 0 0.5rem;
  font-size: 1rem;
}

.product-sku,
.product-category {
  font-size: 0.85rem;
  color: #666;
  margin: 0.25rem 0;
}

.product-price {
  font-size: 1.25rem;
  font-weight: bold;
  color: #e74c3c;
  margin: 0.5rem 0;
}

.product-stock {
  font-size: 0.9rem;
  color: #27ae60;
  margin: 0.25rem 0;
}

.product-stock.out-of-stock {
  color: #e74c3c;
}

.btn-add-cart {
  width: 100%;
  padding: 0.75rem;
  margin-top: 0.5rem;
  background-color: #3498db;
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background 0.2s;
}

.btn-add-cart:hover:not(:disabled) {
  background-color: #2980b9;
}

.btn-add-cart:disabled {
  background-color: #bdc3c7;
  cursor: not-allowed;
}
</style>
