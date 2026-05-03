import { defineStore } from 'pinia'
import { ref, computed } from 'vue'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(localStorage.getItem('auth_token') || null)
  const loading = ref(false)

  const isAuthenticated = computed(() => !!token.value)

  const setToken = (newToken) => {
    const normalizedToken = typeof newToken === 'string' && newToken.trim() ? newToken : null

    token.value = normalizedToken
    localStorage.removeItem('auth')

    if (normalizedToken) {
      localStorage.setItem('auth_token', normalizedToken)
    } else {
      localStorage.removeItem('auth_token')
    }
  }

  const setUser = (userData) => {
    user.value = userData
  }

  const logout = () => {
    user.value = null
    token.value = null
    localStorage.removeItem('auth_token')
    localStorage.removeItem('auth')
  }

  return {
    user,
    token,
    loading,
    isAuthenticated,
    setToken,
    setUser,
    logout
  }
})
