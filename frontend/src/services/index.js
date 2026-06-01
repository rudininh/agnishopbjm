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

  shopeeItems(sync = false, params = {}) {
    return api.get('/get-shopee-items', {
      params: { ...(sync ? { sync: 1 } : {}), ...params }
    })
  },

  tiktokItems(sync = false, params = {}) {
    return api.get('/get-tiktok-items', {
      params: { ...(sync ? { sync: 1 } : {}), ...params }
    })
  },

  stockMaster() {
    return api.get('/get-stock-master')
  },

  productVariantAnalysis(params = {}) {
    return api.get('/product-variant-analysis', { params })
  },

  confirmProductVariantAnalysisIssue(data) {
    return api.post('/product-variant-analysis/confirm', data)
  },

  skuMapping(params = {}) {
    return api.get('/sku-mapping', { params })
  },

  saveSkuMapping(data) {
    return api.post('/sku-mapping', data)
  },

  syncSkuMappingMarketplaces() {
    return api.post('/sku-mapping/sync-marketplaces')
  },

  updateSkuMappingMarketplaceSku(data) {
    return api.post('/sku-mapping/update-marketplace-sku', data)
  },

  prepareMissingVariant(data) {
    return api.post('/sku-mapping/prepare-missing-variant', data)
  },

  tiktokVariantAction(data) {
    return api.post('/tiktok-variant/action', data)
  },

  tiktokSubmitGeneratedPayload(data) {
    return api.post('/tiktok/submit-generated-payload', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  tiktokGetProduct(data) {
    return api.post('/tiktok/get-product', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  tiktokGetProductContext() {
    return api.get('/tiktok/get-product-context')
  },

  shopeeApiTest(data) {
    return api.post('/shopee/api-test', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  shopeeApiTestContext() {
    return api.get('/shopee/api-test-context')
  },

  shopeeAddVariant(data) {
    return api.post('/shopee/add-variant', data, {
      responseType: 'text',
      validateStatus: () => true
    })
  },

  syncShopeeToTiktok() {
    return api.post('/sync-shopee-to-tiktok')
  },

  runTokenAction(action) {
    return api.post(`/omnichannel/${action}`)
  }
}

export const shopeeDocsService = {
  modules() {
    return api.get('/shopee-docs/modules')
  },

  apiDetail(apiName) {
    return api.get('/shopee-docs/api', {
      params: { api_name: apiName }
    })
  }
}
