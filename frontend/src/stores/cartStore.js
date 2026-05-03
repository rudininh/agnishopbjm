import { defineStore } from 'pinia'
import { ref } from 'vue'
import api from '@/services/api'

export const useCartStore = defineStore('cart', () => {
  const items = ref([])
  const total = ref(0)
  const loading = ref(false)

  const fetchCart = async () => {
    loading.value = true
    try {
      const { data } = await api.get('/cart')
      items.value = data.data.items || []
      total.value = data.data.total || 0
    } catch (error) {
      console.error('Error fetching cart:', error)
    } finally {
      loading.value = false
    }
  }

  const addItem = async (productId, quantity) => {
    try {
      const { data } = await api.post('/cart/items', {
        product_id: productId,
        quantity
      })
      items.value = data.data.items || []
      total.value = data.data.total || 0
    } catch (error) {
      console.error('Error adding item to cart:', error)
      throw error
    }
  }

  const updateItem = async (itemId, quantity) => {
    try {
      const { data } = await api.put(`/cart/items/${itemId}`, { quantity })
      items.value = data.data.items || []
      total.value = data.data.total || 0
    } catch (error) {
      console.error('Error updating cart item:', error)
      throw error
    }
  }

  const removeItem = async (itemId) => {
    try {
      const { data } = await api.delete(`/cart/items/${itemId}`)
      items.value = data.data.items || []
      total.value = data.data.total || 0
    } catch (error) {
      console.error('Error removing item:', error)
      throw error
    }
  }

  const clear = () => {
    items.value = []
    total.value = 0
  }

  return {
    items,
    total,
    loading,
    fetchCart,
    addItem,
    updateItem,
    removeItem,
    clear
  }
})
