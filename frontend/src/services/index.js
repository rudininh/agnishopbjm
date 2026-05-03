import api from './api'

export const authService = {
  register(email, password, name) {
    return api.post('/auth/register', {
      email,
      password,
      password_confirmation: password,
      name
    })
  },

  login(email, password) {
    return api.post('/auth/login', {
      email,
      password
    })
  },

  logout() {
    return api.post('/auth/logout')
  }
}

export const productService = {
  getAll(page = 1, perPage = 20) {
    return api.get('/products', {
      params: { page, per_page: perPage }
    })
  },

  getById(id) {
    return api.get(`/products/${id}`)
  },

  create(data) {
    return api.post('/products', data)
  },

  update(id, data) {
    return api.put(`/products/${id}`, data)
  },

  delete(id) {
    return api.delete(`/products/${id}`)
  }
}

export const categoryService = {
  getAll(page = 1, perPage = 20) {
    return api.get('/categories', {
      params: { page, per_page: perPage }
    })
  },

  getById(id) {
    return api.get(`/categories/${id}`)
  },

  create(data) {
    return api.post('/categories', data)
  },

  update(id, data) {
    return api.put(`/categories/${id}`, data)
  },

  delete(id) {
    return api.delete(`/categories/${id}`)
  }
}

export const orderService = {
  getAll(page = 1, perPage = 20) {
    return api.get('/orders', {
      params: { page, per_page: perPage }
    })
  },

  getById(id) {
    return api.get(`/orders/${id}`)
  },

  checkout(data) {
    return api.post('/orders', data)
  }
}

export const omnichannelService = {
  dashboard() {
    return api.get('/omnichannel/dashboard')
  },

  shopeeItems() {
    return api.get('/get-shopee-items')
  },

  tiktokItems() {
    return api.get('/get-tiktok-items')
  },

  stockMaster() {
    return api.get('/get-stock-master')
  },

  syncShopeeToTiktok() {
    return api.post('/sync-shopee-to-tiktok')
  },

  runTokenAction(action) {
    return api.post(`/omnichannel/${action}`)
  }
}
