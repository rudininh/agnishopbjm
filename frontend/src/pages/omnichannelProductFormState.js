const primaryAccounts = ['shopee-agnishopbjm', 'tiktok-agnishopbjm']

const createVariant = () => ({
  variant_name: '',
  sku: '',
  price: '',
  stock: 0,
  image_url: ''
})

export const createDefaultProductForm = () => ({
  name: '',
  sku: '',
  description: '',
  price: '',
  stock: 0,
  category_id: '',
  accounts: [...primaryAccounts],
  marketplace: {
    shopee: { category_id: '', weight: '' },
    tiktok: { category_id: '', warehouse_id: '' }
  },
  variants: [createVariant()]
})

export const fillRandomProduct = (form) => {
  const next = structuredClone(form)

  next.name = 'Kemeja Flanel Random'
  next.sku = 'FLANEL-RANDOM-001'
  next.description = 'Produk random untuk uji input omnichannel.'
  next.price = 125000
  next.stock = 15
  next.category_id = 'demo-category'
  next.marketplace = {
    shopee: { category_id: '123456', weight: '0.5' },
    tiktok: { category_id: '987654', warehouse_id: 'WAREHOUSE-RANDOM' }
  }
  next.variants = [
    {
      variant_name: 'Merah - M',
      sku: 'FLANEL-RANDOM-001-MERAH-M',
      price: 125000,
      stock: 8,
      image_url: 'https://example.com/flanel-merah.jpg'
    },
    {
      variant_name: 'Biru - L',
      sku: 'FLANEL-RANDOM-001-BIRU-L',
      price: 130000,
      stock: 7,
      image_url: 'https://example.com/flanel-biru.jpg'
    }
  ]

  return next
}

export const validateProductForm = (form) => {
  const errors = []
  const addError = (code, field, message) => errors.push({ code, field, message })

  if (!String(form.name || '').trim()) addError('name_required', 'name', 'Nama produk wajib diisi.')
  if (!String(form.sku || '').trim()) addError('sku_required', 'sku', 'SKU produk wajib diisi.')
  if (!String(form.category_id || '').trim()) addError('category_required', 'category_id', 'Kategori wajib dipilih.')
  if (!Array.isArray(form.accounts) || form.accounts.length === 0) addError('accounts_required', 'accounts', 'Pilih minimal satu marketplace.')
  if (!Array.isArray(form.variants) || form.variants.length === 0) {
    addError('variants_required', 'variants', 'Tambahkan minimal satu varian.')
  }

  if ((form.accounts || []).includes('shopee-agnishopbjm')) {
    if (!String(form.marketplace?.shopee?.category_id || '').trim()) addError('shopee_category_required', 'marketplace.shopee.category_id', 'Kategori Shopee wajib diisi.')
    if (form.marketplace?.shopee?.weight === '' || Number(form.marketplace?.shopee?.weight || 0) <= 0) addError('shopee_weight_required', 'marketplace.shopee.weight', 'Berat Shopee wajib lebih dari 0 kg.')
  }

  if ((form.accounts || []).includes('tiktok-agnishopbjm')) {
    if (!String(form.marketplace?.tiktok?.category_id || '').trim()) addError('tiktok_category_required', 'marketplace.tiktok.category_id', 'Kategori TikTok wajib diisi.')
    if (!String(form.marketplace?.tiktok?.warehouse_id || '').trim()) addError('tiktok_warehouse_required', 'marketplace.tiktok.warehouse_id', 'Warehouse TikTok wajib diisi.')
  }

  const skuSet = new Set()
  const productSku = String(form.sku || '').trim().toLowerCase()
  if (productSku) skuSet.add(productSku)

  for (const [index, variant] of (form.variants || []).entries()) {
    const prefix = `variants.${index}`
    const sku = String(variant.sku || '').trim().toLowerCase()
    if (!String(variant.variant_name || '').trim()) addError('variant_name_required', `${prefix}.variant_name`, 'Nama varian wajib diisi.')
    if (!sku) addError('variant_sku_required', `${prefix}.sku`, 'SKU varian wajib diisi.')
    if (sku && skuSet.has(sku)) addError('duplicate_sku', `${prefix}.sku`, 'SKU produk/varian harus unik.')
    if (sku) skuSet.add(sku)
    if (variant.price === '' || variant.price === null || Number(variant.price) < 0) addError('variant_price_invalid', `${prefix}.price`, 'Harga varian tidak valid.')
    if (variant.stock === '' || variant.stock === null || Number(variant.stock) < 0) addError('variant_stock_invalid', `${prefix}.stock`, 'Stok varian tidak valid.')
    if (!String(variant.image_url || '').trim()) addError('variant_image_required', `${prefix}.image_url`, 'URL gambar varian wajib diisi.')
  }

  return { valid: errors.length === 0, errors }
}

export const normalizeProductPayload = (form) => ({
  name: String(form.name || '').trim(),
  sku: String(form.sku || '').trim(),
  description: String(form.description || '').trim(),
  price: Number(form.price || 0),
  stock: Number(form.stock || 0),
  category_id: form.category_id,
  variants: (form.variants || []).map((variant, position) => ({
    variant_name: String(variant.variant_name || '').trim(),
    sku: String(variant.sku || '').trim(),
    price: Number(variant.price || 0),
    stock: Number(variant.stock || 0),
    image_url: String(variant.image_url || '').trim(),
    position
  }))
})

export const normalizePublicationPayload = (form) => ({
  accounts: (form.accounts || []).map((accountKey) => {
    if (accountKey === 'shopee-agnishopbjm') {
      return {
        account_key: accountKey,
        context: {
          category_id: String(form.marketplace?.shopee?.category_id || '').trim(),
          weight: Number(form.marketplace?.shopee?.weight || 0)
        }
      }
    }

    if (accountKey === 'tiktok-agnishopbjm') {
      return {
        account_key: accountKey,
        context: {
          category_id: String(form.marketplace?.tiktok?.category_id || '').trim(),
          warehouse_id: String(form.marketplace?.tiktok?.warehouse_id || '').trim()
        }
      }
    }

    return { account_key: accountKey, context: {} }
  })
})