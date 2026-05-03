<template>
  <div class="pages-products">
    <div class="container">
      <h1>Produk AgniShop</h1>

      <div class="filter-section">
        <input 
          v-model="searchQuery" 
          type="text" 
          placeholder="Cari produk..."
          class="search-input"
        />
      </div>

      <div v-if="loading" class="loading">Memuat produk...</div>

      <div v-else-if="filteredProducts.length === 0" class="no-products">
        Tidak ada produk yang ditemukan.
      </div>

      <div v-else class="products-grid">
        <ProductCard 
          v-for="product in filteredProducts" 
          :key="product.uuid"
          :product="product"
          @add="addToCart(product)"
        />
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useCartStore } from '@/stores/cartStore'
import { productService } from '@/services'
import ProductCard from '@/components/ProductCard.vue'

const products = ref([])
const loading = ref(false)
const searchQuery = ref('')
const cartStore = useCartStore()

const filteredProducts = computed(() => {
  if (!searchQuery.value) return products.value
  
  return products.value.filter(p =>
    p.name.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
    p.sku.toLowerCase().includes(searchQuery.value.toLowerCase())
  )
})

const fetchProducts = async () => {
  loading.value = true
  try {
    const { data } = await productService.getAll(1, 100)
    products.value = data.data
  } catch (error) {
    console.error('Error fetching products:', error)
  } finally {
    loading.value = false
  }
}

const addToCart = async (product) => {
  try {
    await cartStore.addItem(product.uuid, 1)
    alert('Produk berhasil ditambahkan ke keranjang!')
  } catch (error) {
    alert('Gagal menambahkan produk ke keranjang.')
  }
}

onMounted(fetchProducts)
</script>

<style scoped>
.pages-products {
  padding: 2rem 0;
  min-height: calc(100vh - 60px);
}

.container {
  max-width: 1200px;
  margin: 0 auto;
  padding: 0 2rem;
}

.pages-products h1 {
  margin-bottom: 2rem;
  color: #2c3e50;
}

.filter-section {
  margin-bottom: 2rem;
}

.search-input {
  width: 100%;
  max-width: 400px;
  padding: 0.75rem;
  border: 1px solid #ddd;
  border-radius: 4px;
  font-size: 1rem;
}

.search-input:focus {
  outline: none;
  border-color: #3498db;
}

.loading,
.no-products {
  text-align: center;
  padding: 2rem;
  color: #666;
}

.products-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
  gap: 1.5rem;
}

@media (max-width: 768px) {
  .products-grid {
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  }
}
</style>
