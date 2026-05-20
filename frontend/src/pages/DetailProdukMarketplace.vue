<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Detail Produk</h1>
      </div>
      <div class="header-actions">
        <button type="button" class="ghost" @click="loadContexts" :disabled="contextBusy">
          {{ contextBusy ? 'Memuat...' : 'Pakai Token Aktif' }}
        </button>
        <button type="button" class="primary" @click="loadAll" :disabled="busyAny || !canLoadAny">
          {{ busyAny ? 'Mengambil...' : 'Ambil Detail' }}
        </button>
      </div>
    </header>

    <div class="control-grid">
      <section class="panel form-panel">
        <div class="panel-title">
          <div>
            <h2>Shopee</h2>
            <p>Item base info dan model list</p>
          </div>
          <button type="button" class="ghost mini" @click="loadShopeeBoth" :disabled="shopeeBusy || !resolveShopeeSearchId()">
            Ambil Shopee
          </button>
        </div>
        <div class="field-grid">
          <div class="lookup-field">
            <label for="shopee-product-search">Cari Produk Shopee</label>
            <input
              id="shopee-product-search"
              v-model.trim="shopeeSearch"
              type="search"
              autocomplete="off"
              placeholder="Ketik nama produk atau Item ID Shopee"
              @focus="shopeeDropdownOpen = true"
              @input="shopeeForm.item_id = ''; shopeeDropdownOpen = true"
              @blur="closeShopeeDropdownSoon"
              @keydown.enter.prevent="applyShopeeSearch"
            />
            <div v-if="shopeeDropdownOpen && filteredShopeeOptions.length" class="lookup-menu">
              <button
                v-for="option in filteredShopeeOptions"
                :key="option.id"
                type="button"
                @mousedown.prevent="selectShopeeOption(option)"
              >
                <span>{{ option.name }}</span>
                <small>Item ID: {{ option.id }}</small>
              </button>
            </div>
            <small class="lookup-value">Terpilih: {{ shopeeForm.item_id || 'belum ada Item ID' }}</small>
          </div>
        </div>
        <p v-if="shopeeError" class="notice error">{{ shopeeError }}</p>
      </section>

      <section class="panel form-panel">
        <div class="panel-title">
          <div>
            <h2>TikTok</h2>
            <p>Product detail aktif</p>
          </div>
          <button type="button" class="ghost mini" @click="loadTiktokDetail" :disabled="tiktokBusy || !resolveTiktokSearchId()">
            Ambil TikTok
          </button>
        </div>
        <div class="field-grid">
          <div class="lookup-field">
            <label for="tiktok-product-search">Cari Produk TikTok</label>
            <input
              id="tiktok-product-search"
              v-model.trim="tiktokSearch"
              type="search"
              autocomplete="off"
              placeholder="Ketik nama produk atau Product ID TikTok"
              @focus="tiktokDropdownOpen = true"
              @input="tiktokForm.product_id = ''; tiktokDropdownOpen = true"
              @blur="closeTiktokDropdownSoon"
              @keydown.enter.prevent="applyTiktokSearch"
            />
            <div v-if="tiktokDropdownOpen && filteredTiktokOptions.length" class="lookup-menu">
              <button
                v-for="option in filteredTiktokOptions"
                :key="option.id"
                type="button"
                @mousedown.prevent="selectTiktokOption(option)"
              >
                <span>{{ option.name }}</span>
                <small>Product ID: {{ option.id }}</small>
              </button>
            </div>
            <small class="lookup-value">Terpilih: {{ tiktokForm.product_id || 'belum ada Product ID' }}</small>
          </div>
        </div>
        <p v-if="tiktokError" class="notice error">{{ tiktokError }}</p>
      </section>
    </div>

    <section class="marketplace-copy-grid">
      <article v-for="product in marketplaceCopyProducts" :key="product.marketplace" class="panel copy-detail-panel">
        <div class="panel-title">
          <div>
            <h2>Detail {{ product.marketplace }}</h2>
            <p>{{ product.product_id || product.item_id || 'Belum ada produk dipilih' }}</p>
          </div>
          <button type="button" class="primary mini" @click="copyText(productCopyBlock(product))" :disabled="!hasProductDetail(product)">
            Copy Semua
          </button>
        </div>

        <div class="copy-section">
          <div class="copy-section-head">
            <strong>Gambar Etalase</strong>
            <small>{{ productImages(product).length }} gambar</small>
          </div>
          <div v-if="productImages(product).length" class="image-download-grid">
            <div v-for="(url, index) in productImages(product)" :key="`${product.marketplace}-image-${index}`" class="download-card">
              <img :src="url" :alt="`${product.marketplace} gambar ${index + 1}`" />
              <div>
                <span>Gambar {{ index + 1 }}</span>
                <div class="inline-actions">
                  <button type="button" class="ghost mini" @click="copyText(url)">Copy URL</button>
                  <a class="button-link" :href="url" target="_blank" rel="noreferrer" :download="`${product.marketplace.toLowerCase()}-${index + 1}`">Download</a>
                </div>
              </div>
            </div>
          </div>
          <p v-else class="empty-inline">Belum ada gambar dari {{ product.marketplace }}.</p>
        </div>

        <div class="copy-fields">
          <div v-for="field in productCopyFields(product)" :key="`${product.marketplace}-${field.label}`" class="copy-field">
            <label>{{ field.label }}</label>
            <div class="copy-input-row">
              <textarea v-if="field.multiline" readonly :value="field.value || '-'"></textarea>
              <input v-else readonly :value="field.value || '-'" />
              <button type="button" class="ghost mini" @click="copyText(field.value)" :disabled="!field.value">Copy</button>
            </div>
          </div>
        </div>

        <div class="copy-section">
          <div class="copy-section-head">
            <strong>Varian {{ product.marketplace }}</strong>
            <small>{{ product.variants.length }} varian</small>
          </div>
          <div v-if="product.variants.length" class="variant-copy-table">
            <div class="variant-copy-head">
              <span>Varian</span>
              <span>SKU</span>
              <span>Harga</span>
              <span>Stok</span>
              <span>Gambar</span>
            </div>
            <div v-for="(variant, index) in product.variants" :key="`${product.marketplace}-variant-${variant.id || index}`" class="variant-copy-row">
              <div>
                <strong>{{ variant.name || `Varian ${index + 1}` }}</strong>
                <button type="button" class="ghost mini" @click="copyText(variant.name)" :disabled="!variant.name">Copy</button>
              </div>
              <div>
                <span>{{ variant.sku || '-' }}</span>
                <button type="button" class="ghost mini" @click="copyText(variant.sku)" :disabled="!variant.sku">Copy</button>
              </div>
              <div>
                <span>{{ variant.price ? formatCurrency(variant.price) : '-' }}</span>
                <button type="button" class="ghost mini" @click="copyText(variant.price)" :disabled="!variant.price">Copy</button>
              </div>
              <div>
                <span>{{ variant.stock || '-' }}</span>
                <button type="button" class="ghost mini" @click="copyText(variant.stock)" :disabled="!variant.stock">Copy</button>
              </div>
              <div>
                <img v-if="variant.image_url" :src="variant.image_url" :alt="variant.name || `Varian ${index + 1}`" class="variant-thumb" />
                <span v-else>-</span>
                <div class="inline-actions">
                  <button type="button" class="ghost mini" @click="copyText(variant.image_url)" :disabled="!variant.image_url">Copy URL</button>
                  <a v-if="variant.image_url" class="button-link" :href="variant.image_url" target="_blank" rel="noreferrer" :download="`${product.marketplace.toLowerCase()}-variant-${index + 1}`">Download</a>
                </div>
              </div>
            </div>
          </div>
          <p v-else class="empty-inline">Belum ada varian dari {{ product.marketplace }}.</p>
        </div>
      </article>
    </section>

    <section class="detail-grid">
      <article class="panel">
        <div class="panel-title">
          <div>
            <h2>Preview Shopee</h2>
            <p>{{ shopeeProduct.item_id || '-' }}</p>
          </div>
          <button type="button" class="ghost mini" @click="copyText(shopeeRawText)" :disabled="!shopeeRawText">Copy JSON</button>
        </div>
        <ProductPreview :product="shopeeProduct" />
        <pre>{{ shopeeRawText || 'Belum ada response Shopee.' }}</pre>
      </article>

      <article class="panel">
        <div class="panel-title">
          <div>
            <h2>Preview TikTok</h2>
            <p>{{ tiktokProduct.product_id || '-' }}</p>
          </div>
          <button type="button" class="ghost mini" @click="copyText(tiktokRawText)" :disabled="!tiktokRawText">Copy JSON</button>
        </div>
        <ProductPreview :product="tiktokProduct" />
        <pre>{{ tiktokRawText || 'Belum ada response TikTok.' }}</pre>
      </article>
    </section>

    <p v-if="copyMessage" class="notice success">{{ copyMessage }}</p>
  </section>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const shopeeForm = reactive({
  item_id: '',
  shop_id: '',
  access_token: ''
})

const tiktokForm = reactive({
  product_id: '',
  version: '202309',
  shop_id: '',
  shop_cipher: '',
  access_token: ''
})

const contextBusy = ref(false)
const shopeeBusy = ref(false)
const tiktokBusy = ref(false)
const optionBusy = ref(false)
const shopeeError = ref('')
const tiktokError = ref('')
const copyMessage = ref('')
const shopeeBasePayload = ref(null)
const shopeeModelPayload = ref(null)
const tiktokPayload = ref(null)
const shopeeSearch = ref('')
const tiktokSearch = ref('')
const shopeeDropdownOpen = ref(false)
const tiktokDropdownOpen = ref(false)
const shopeeOptions = ref([])
const tiktokOptions = ref([])
const selectedShopeeCachedItem = ref(null)
const selectedTiktokCachedItem = ref(null)

const busyAny = computed(() => contextBusy.value || optionBusy.value || shopeeBusy.value || tiktokBusy.value)
const canLoadAny = computed(() => Boolean(resolveShopeeSearchId() || resolveTiktokSearchId()))
const normalizeSearch = (value) => String(value || '').trim().toLowerCase().replace(/\s+/g, ' ')
const includesSearch = (option, query) => {
  const text = normalizeSearch(`${option.name} ${option.id}`)
  return normalizeSearch(query).split(' ').filter(Boolean).every((word) => text.includes(word))
}
const uniqueOptions = (rows) => {
  const seen = new Set()
  return rows
    .filter((item) => item.id)
    .filter((item) => {
      if (seen.has(item.id)) return false
      seen.add(item.id)
      return true
    })
}
const filteredShopeeOptions = computed(() => {
  const query = shopeeSearch.value
  return uniqueOptions(shopeeOptions.value)
    .filter((option) => !query || includesSearch(option, query))
    .slice(0, 8)
})
const filteredTiktokOptions = computed(() => {
  const query = tiktokSearch.value
  return uniqueOptions(tiktokOptions.value)
    .filter((option) => !query || includesSearch(option, query))
    .slice(0, 8)
})
const resolveOptionId = (value, options) => {
  const query = String(value || '').trim()
  if (!query) return ''
  if (/^\d+$/.test(query)) return query

  const normalized = normalizeSearch(query)
  const exact = options.find((option) => normalizeSearch(option.name) === normalized || String(option.id) === query)
  if (exact) return exact.id

  const partial = options.find((option) => includesSearch(option, query))
  return partial?.id || ''
}
const resolveShopeeSearchId = () => shopeeForm.item_id || resolveOptionId(shopeeSearch.value, shopeeOptions.value)
const resolveTiktokSearchId = () => tiktokForm.product_id || resolveOptionId(tiktokSearch.value, tiktokOptions.value)
const formatCurrency = (value) => {
  const number = Number(value || 0)
  if (!Number.isFinite(number) || number <= 0) return '-'
  return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(number)
}
const prettyJson = (value) => value ? JSON.stringify(value, null, 2) : ''
const read = (value, paths, fallback = '') => {
  for (const path of paths) {
    const result = path.split('.').reduce((node, key) => {
      if (node === null || node === undefined) return undefined
      return node[key]
    }, value)
    if (result !== null && result !== undefined && String(result).trim() !== '') return result
  }
  return fallback
}
const asArray = (value) => Array.isArray(value) ? value : []
const uniqueStrings = (values) => {
  const seen = new Set()
  return values
    .map((value) => String(value || '').trim())
    .filter(Boolean)
    .filter((value) => {
      if (seen.has(value)) return false
      seen.add(value)
      return true
    })
}
const imageUrl = (node) => {
  const list = read(node, ['image.image_url_list', 'images', 'main_images', 'image.urls', 'sku_img.urls', 'sales_attributes.0.sku_img.urls'], [])
  if (Array.isArray(list) && list.length) {
    const first = list[0]
    return typeof first === 'string' ? first : read(first, ['url', 'urls.0', 'thumb_url', 'uri', 'thumb_urls.0'], '')
  }
  return read(node, [
    'image_url',
    'image.url',
    'image.thumb_url',
    'sku_img.url',
    'sku_img.uri',
    'sales_attributes.0.sku_img.url',
    'sales_attributes.0.sku_img.uri',
    'sales_attributes.0.sku_img.urls.0',
    'sales_attributes.0.sku_img.thumb_urls.0'
  ], '')
}
const deepImageUrls = (value, results = []) => {
  if (!value || typeof value !== 'object') return results

  Object.entries(value).forEach(([key, item]) => {
    if (typeof item === 'string') {
      const lowerKey = key.toLowerCase()
      if ((lowerKey.includes('image') || lowerKey.includes('url')) && /^https?:\/\//i.test(item)) {
        results.push(item)
      }
      return
    }

    if (Array.isArray(item)) {
      item.forEach((child) => deepImageUrls(child, results))
      return
    }

    deepImageUrls(item, results)
  })

  return results
}
const imageUrls = (node) => {
  const list = read(node, ['image.image_url_list', 'images', 'main_images', 'image.urls', 'sku_img.urls', 'sales_attributes.0.sku_img.urls'], [])
  const urls = []

  if (Array.isArray(list)) {
    list.forEach((item) => {
      if (typeof item === 'string') {
        urls.push(item)
        return
      }

      urls.push(read(item, ['url', 'urls.0', 'thumb_url', 'uri', 'thumb_urls.0'], ''))
    })
  }

  urls.push(imageUrl(node))
  deepImageUrls(node, urls)
  return uniqueStrings(urls)
}
const normalizeWeight = (value) => {
  const raw = String(value ?? '').trim()
  if (!raw) return ''
  if (/[a-z]/i.test(raw)) return raw

  const number = Number(raw)
  if (!Number.isFinite(number) || number <= 0) return raw

  if (number < 10) {
    const grams = Math.round(number * 1000)
    return `${grams} gram`
  }

  return `${Math.round(number)} gram`
}
const inferCategoryFromName = (name) => {
  const text = normalizeSearch(name)
  if (!text) return ''
  if (text.includes('pashmina')) return 'Pashmina'
  if (text.includes('segi empat') || text.includes('segiempat')) return 'Hijab Segi Empat'
  if (text.includes('hijab persegi') || text.includes('kerudung persegi')) return 'Hijab Persegi'
  if (text.includes('instan') || text.includes('bergo')) return 'Hijab Instan'
  if (text.includes('hijab') || text.includes('kerudung') || text.includes('jilbab')) return 'Hijab'
  return ''
}
const normalizeCategory = (node, fallbackName = '') => {
  const chains = read(node, ['category_chains', 'category_path', 'category_list'], [])
  if (Array.isArray(chains) && chains.length) {
    const names = chains
      .map((item) => read(item, ['local_name', 'display_name', 'category_name', 'name'], ''))
      .filter(Boolean)
    if (names.length) return names.join(' > ')
  }

  const direct = read(node, ['category_name', 'category.display_name', 'category.name', 'category.local_name'], '')
  if (direct) return String(direct).trim()

  const inferred = inferCategoryFromName(fallbackName)
  if (inferred) return inferred

  return String(read(node, ['category_id', 'category.id'], '') || '').trim()
}
const normalizeDescription = (value) => {
  if (Array.isArray(value)) return value.map(normalizeDescription).filter(Boolean).join('\n')
  if (value && typeof value === 'object') {
    return String(value.plain_text || value.text || value.content || value.value || '').trim()
  }
  return String(value || '').trim()
}
const normalizePrice = (node) => {
  const value = read(node, [
    'price_info.0.current_price',
    'price.current_price',
    'price_info.0.original_price',
    'price.sale_price',
    'price.amount',
    'price.tax_exclusive_price',
    'price',
    'sale_price'
  ], '')
  return String(value ?? '').trim()
}
const normalizeStock = (node) => {
  const stock = read(node, ['stock_info_v2.seller_stock.0.stock', 'stock_info.0.current_stock', 'inventory.0.quantity', 'stock', 'stock_qty'], '')
  return String(stock ?? '').trim()
}
const normalizeSellerSku = (node) => String(read(node, ['model_sku', 'seller_sku', 'seller_sku_id', 'sku', 'external_sku_id'], '') || '').trim()

const shopeeBaseItem = computed(() => {
  const payload = shopeeBasePayload.value
  return read(payload, ['response.item_list'], [])[0] || read(payload, ['item'], {}) || {}
})
const shopeeModels = computed(() => asArray(read(shopeeModelPayload.value, ['response.model', 'response.model_list'], [])))
const shopeeTierVariations = computed(() => asArray(read(shopeeModelPayload.value, ['response.tier_variation', 'response.standardise_tier_variation', 'tier_variation'], [])))
const shopeeTierOptionImage = (model) => {
  const indexes = asArray(read(model, ['tier_index'], []))
  if (!indexes.length) return ''

  for (let tierIndex = 0; tierIndex < indexes.length; tierIndex += 1) {
    const optionIndex = Number(indexes[tierIndex])
    if (!Number.isInteger(optionIndex) || optionIndex < 0) continue

    const tier = shopeeTierVariations.value[tierIndex]
    const option = asArray(read(tier, ['option_list'], []))[optionIndex]
    const url = imageUrl(option)
    if (url) return url
  }

  return ''
}
const cachedShopeeVariantImage = (model) => {
  const modelId = String(read(model, ['model_id'], '') || '').trim()
  const modelName = normalizeSearch(read(model, ['model_name', 'name'], ''))
  const cachedModels = asArray(selectedShopeeCachedItem.value?.models)
  const match = cachedModels.find((item) => {
    const cachedId = String(item?.model_id || '').trim()
    const cachedName = normalizeSearch(item?.name || item?.model_name || '')
    return (modelId && cachedId === modelId) || (modelName && cachedName === modelName)
  })

  return String(match?.image_url || '').trim()
}
const shopeeProduct = computed(() => ({
  marketplace: 'Shopee',
  product_id: String(read(shopeeBaseItem.value, ['item_id'], shopeeForm.item_id) || '').trim(),
  item_id: String(read(shopeeBaseItem.value, ['item_id'], shopeeForm.item_id) || '').trim(),
  name: String(read(shopeeBaseItem.value, ['item_name', 'name'], '') || '').trim(),
  description: normalizeDescription(read(shopeeBaseItem.value, ['description'], '')),
  category_id: normalizeCategory(shopeeBaseItem.value, read(shopeeBaseItem.value, ['item_name', 'name'], '')),
  brand: String(read(shopeeBaseItem.value, ['brand.original_brand_name', 'brand.name'], '') || '').trim(),
  weight: normalizeWeight(read(shopeeBaseItem.value, ['weight'], '')),
  dimensions: [
    read(shopeeBaseItem.value, ['dimension.package_length'], ''),
    read(shopeeBaseItem.value, ['dimension.package_width'], ''),
    read(shopeeBaseItem.value, ['dimension.package_height'], '')
  ].filter(Boolean).join(' x '),
  image_url: imageUrl(shopeeBaseItem.value),
  image_urls: imageUrls(shopeeBaseItem.value),
  variants: shopeeModels.value.map((model) => ({
    name: String(read(model, ['model_name', 'name'], '') || '').trim(),
    sku: normalizeSellerSku(model),
    id: String(read(model, ['model_id'], '') || '').trim(),
    price: normalizePrice(model),
    stock: normalizeStock(model),
    image_url: imageUrl(model) || shopeeTierOptionImage(model) || cachedShopeeVariantImage(model)
  }))
}))

const tiktokData = computed(() => {
  const payload = tiktokPayload.value
  const data = read(payload, ['data.product'], null) || read(payload, ['data'], null) || payload || {}
  return data?.product && typeof data.product === 'object' ? data.product : data
})
const tiktokSkus = computed(() => asArray(read(tiktokData.value, ['skus'], [])))
const cachedTiktokVariantImage = (sku) => {
  const skuId = String(read(sku, ['id', 'sku_id'], '') || '').trim()
  const skuName = normalizeSearch(read(sku, ['sales_attributes.0.value_name', 'sales_attributes.0.name', 'sku_name', 'name'], ''))
  const cachedSkus = asArray(selectedTiktokCachedItem.value?.skus)
  const match = cachedSkus.find((item) => {
    const cachedId = String(item?.sku_id || item?.tiktok_sku || '').trim()
    const cachedName = normalizeSearch(item?.sku_name || item?.name || '')
    return (skuId && cachedId === skuId) || (skuName && cachedName === skuName)
  })

  return String(match?.image_url || '').trim()
}
const tiktokProduct = computed(() => ({
  marketplace: 'TikTok',
  product_id: String(read(tiktokData.value, ['id', 'product_id'], tiktokForm.product_id) || '').trim(),
  item_id: '',
  name: String(read(tiktokData.value, ['title', 'product_name'], '') || '').trim(),
  description: normalizeDescription(read(tiktokData.value, ['description', 'description.plain_text'], '')),
  category_id: normalizeCategory(tiktokData.value, read(tiktokData.value, ['title', 'product_name'], '')),
  brand: String(read(tiktokData.value, ['brand.name', 'brand'], '') || '').trim(),
  weight: normalizeWeight(read(tiktokData.value, ['package_weight.value', 'weight'], '')),
  dimensions: [
    read(tiktokData.value, ['package_dimensions.length'], ''),
    read(tiktokData.value, ['package_dimensions.width'], ''),
    read(tiktokData.value, ['package_dimensions.height'], '')
  ].filter(Boolean).join(' x '),
  image_url: imageUrl(tiktokData.value),
  image_urls: imageUrls(tiktokData.value),
  variants: tiktokSkus.value.map((sku) => ({
    name: String(read(sku, ['sales_attributes.0.value_name', 'sales_attributes.0.name', 'sku_name', 'name'], '') || '').trim(),
    sku: normalizeSellerSku(sku),
    id: String(read(sku, ['id', 'sku_id'], '') || '').trim(),
    price: normalizePrice(sku),
    stock: normalizeStock(sku),
    image_url: imageUrl(sku) || cachedTiktokVariantImage(sku)
  }))
}))

const hasProductDetail = (product) => Boolean(product?.name || product?.description || product?.image_url || product?.variants?.length)
const marketplaceCopyProducts = computed(() => [shopeeProduct.value, tiktokProduct.value])
const productImages = (product) => uniqueStrings([
  ...(product?.image_urls || []),
  product?.image_url
])
const productCopyFields = (product) => [
  { label: 'Nama Produk', value: product?.name || '', multiline: false },
  { label: 'Deskripsi', value: product?.description || '', multiline: true },
  { label: 'Kategori', value: product?.category_id || '', multiline: false },
  { label: 'Berat', value: product?.weight || '', multiline: false },
  { label: 'Dimensi', value: product?.dimensions || '', multiline: false },
  { label: product?.marketplace === 'Shopee' ? 'Item ID Shopee' : 'Product ID TikTok', value: product?.product_id || product?.item_id || '', multiline: false }
]
const productCopyBlock = (product) => {
  if (!hasProductDetail(product)) return ''

  const fields = productCopyFields(product)
    .map((field) => `${field.label}: ${field.value || '-'}`)
    .join('\n')
  const images = productImages(product)
    .map((url, index) => `Gambar ${index + 1}: ${url}`)
    .join('\n')
  const variants = (product.variants || []).map((variant, index) => [
    `Varian ${index + 1}: ${variant.name || '-'}`,
    `SKU: ${variant.sku || '-'}`,
    `ID: ${variant.id || '-'}`,
    `Harga: ${variant.price || '-'}`,
    `Stok: ${variant.stock || '-'}`,
    `Gambar: ${variant.image_url || '-'}`
  ].join('\n')).join('\n\n')

  return [fields, images, variants].filter(Boolean).join('\n\n')
}
const shopeeRawText = computed(() => {
  if (!shopeeBasePayload.value && !shopeeModelPayload.value) return ''
  return prettyJson({ base_info: shopeeBasePayload.value, model_list: shopeeModelPayload.value })
})
const tiktokRawText = computed(() => prettyJson(tiktokPayload.value))

const responseData = (response) => {
  if (typeof response?.data === 'string') {
    try {
      return JSON.parse(response.data)
    } catch {
      return { raw: response.data }
    }
  }
  return response?.data || null
}
const selectShopeeOption = (option) => {
  shopeeForm.item_id = option.id
  shopeeSearch.value = `${option.name} (${option.id})`
  selectedShopeeCachedItem.value = option.source || null
  shopeeDropdownOpen.value = false
}
const selectTiktokOption = (option) => {
  tiktokForm.product_id = option.id
  tiktokSearch.value = `${option.name} (${option.id})`
  selectedTiktokCachedItem.value = option.source || null
  tiktokDropdownOpen.value = false
}
const closeShopeeDropdownSoon = () => {
  window.setTimeout(() => {
    shopeeDropdownOpen.value = false
  }, 120)
}
const closeTiktokDropdownSoon = () => {
  window.setTimeout(() => {
    tiktokDropdownOpen.value = false
  }, 120)
}
const applyShopeeSearch = () => {
  const id = resolveShopeeSearchId()
  if (id) {
    shopeeForm.item_id = id
    selectedShopeeCachedItem.value = shopeeOptions.value.find((option) => option.id === id)?.source || selectedShopeeCachedItem.value
  }
  shopeeDropdownOpen.value = false
}
const applyTiktokSearch = () => {
  const id = resolveTiktokSearchId()
  if (id) {
    tiktokForm.product_id = id
    selectedTiktokCachedItem.value = tiktokOptions.value.find((option) => option.id === id)?.source || selectedTiktokCachedItem.value
  }
  tiktokDropdownOpen.value = false
}
const loadProductOptions = async () => {
  optionBusy.value = true
  try {
    const [shopeeResult, tiktokResult] = await Promise.allSettled([
      omnichannelService.shopeeItems(false),
      omnichannelService.tiktokItems(false)
    ])

    if (shopeeResult.status === 'fulfilled') {
      shopeeOptions.value = (shopeeResult.value.data.items || []).map((item) => ({
        id: String(item.item_id || '').trim(),
        name: String(item.nama || item.item_name || item.product_name || 'Produk Shopee').trim(),
        source: item
      }))
    }

    if (tiktokResult.status === 'fulfilled') {
      tiktokOptions.value = (tiktokResult.value.data.items || []).map((item) => ({
        id: String(item.product_id || '').trim(),
        name: String(item.product_name || item.title || 'Produk TikTok').trim(),
        source: item
      }))
    }
  } finally {
    optionBusy.value = false
  }
}
const loadContexts = async () => {
  contextBusy.value = true
  shopeeError.value = ''
  tiktokError.value = ''
  try {
    const [shopeeContext, tiktokContext] = await Promise.allSettled([
      omnichannelService.shopeeApiTestContext(),
      omnichannelService.tiktokGetProductContext()
    ])

    if (shopeeContext.status === 'fulfilled') {
      shopeeForm.shop_id = shopeeContext.value.data.shop_id || ''
      shopeeForm.access_token = shopeeContext.value.data.access_token || ''
    } else {
      shopeeError.value = shopeeContext.reason?.response?.data?.message || 'Context Shopee belum tersedia.'
    }

    if (tiktokContext.status === 'fulfilled') {
      tiktokForm.shop_id = tiktokContext.value.data.shop_id || ''
      tiktokForm.shop_cipher = tiktokContext.value.data.shop_cipher || ''
      tiktokForm.access_token = tiktokContext.value.data.access_token || ''
      tiktokForm.version = tiktokContext.value.data.version || tiktokForm.version
    } else {
      tiktokError.value = tiktokContext.reason?.response?.data?.message || 'Context TikTok belum tersedia.'
    }
  } finally {
    contextBusy.value = false
  }
}
const loadShopeeApi = async (apiName) => {
  const response = await omnichannelService.shopeeApiTest({
    api_name: apiName,
    item_id: shopeeForm.item_id,
    shop_id: shopeeForm.shop_id,
    access_token: shopeeForm.access_token,
    need_tax_info: 'true',
    need_complaint_policy: 'true'
  })
  return responseData(response)
}
const loadShopeeBoth = async () => {
  applyShopeeSearch()
  if (!shopeeForm.item_id) return
  shopeeBusy.value = true
  shopeeError.value = ''
  try {
    const [baseInfo, modelList] = await Promise.all([
      loadShopeeApi('get_item_base_info'),
      loadShopeeApi('get_model_list')
    ])
    shopeeBasePayload.value = baseInfo
    shopeeModelPayload.value = modelList
  } catch (error) {
    shopeeError.value = error.response?.data?.message || 'Detail Shopee gagal diambil.'
  } finally {
    shopeeBusy.value = false
  }
}
const loadTiktokDetail = async () => {
  applyTiktokSearch()
  if (!tiktokForm.product_id) return
  tiktokBusy.value = true
  tiktokError.value = ''
  try {
    const response = await omnichannelService.tiktokGetProduct({
      product_id: tiktokForm.product_id,
      version: tiktokForm.version,
      shop_id: tiktokForm.shop_id,
      shop_cipher: tiktokForm.shop_cipher,
      access_token: tiktokForm.access_token,
      return_under_review_version: 'false',
      return_draft_version: 'false'
    })
    tiktokPayload.value = responseData(response)
  } catch (error) {
    tiktokError.value = error.response?.data?.message || 'Detail TikTok gagal diambil.'
  } finally {
    tiktokBusy.value = false
  }
}
const loadAll = async () => {
  applyShopeeSearch()
  applyTiktokSearch()
  const jobs = []
  if (shopeeForm.item_id) jobs.push(loadShopeeBoth())
  if (tiktokForm.product_id) jobs.push(loadTiktokDetail())
  await Promise.all(jobs)
}
const copyText = async (text) => {
  if (!text) return
  copyMessage.value = ''
  await navigator.clipboard.writeText(text)
  copyMessage.value = 'Teks berhasil disalin.'
  window.setTimeout(() => {
    copyMessage.value = ''
  }, 1800)
}

const ProductPreview = defineComponent({
  props: {
    product: { type: Object, required: true }
  },
  setup(props) {
    return () => h('div', { class: 'product-preview' }, [
      props.product.image_url
        ? h('img', { src: props.product.image_url, alt: props.product.name || props.product.marketplace })
        : h('div', { class: 'image-fallback' }, props.product.marketplace === 'Shopee' ? 'SP' : 'TT'),
      h('div', [
        h('strong', props.product.name || 'Belum ada nama produk'),
        h('small', `${props.product.marketplace} ID: ${props.product.product_id || props.product.item_id || '-'}`),
        h('small', `Brand: ${props.product.brand || '-'} | Kategori: ${props.product.category_id || '-'}`),
        h('small', `Varian: ${props.product.variants.length}`)
      ])
    ])
  }
})

onMounted(() => {
  loadContexts()
  loadProductOptions()
})
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 16px; }
.page-header p, .panel-title p, label span, small { color: #64748b; }
.page-header p { margin-bottom: 4px; font-size: 13px; }
.page-header h1 { color: #0f172a; font-size: 26px; letter-spacing: 0; }
.header-actions, .panel-title { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.control-grid, .marketplace-copy-grid, .detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
.panel { background: #fff; border: 1px solid #d9e2ec; border-radius: 8px; padding: 14px; min-width: 0; }
.panel-title { margin-bottom: 12px; }
.panel-title h2 { color: #0f172a; font-size: 16px; letter-spacing: 0; }
.panel-title p { font-size: 12px; margin-top: 2px; }
.field-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
.wide { grid-column: 1 / -1; }
label span, .lookup-field label { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
input, textarea { width: 100%; border: 1px solid #d7dde8; border-radius: 4px; color: #0f172a; background: #fff; font-size: 13px; }
input { height: 36px; padding: 0 10px; }
textarea { min-height: 92px; resize: vertical; padding: 10px; line-height: 1.55; }
.lookup-field { position: relative; min-width: 0; }
.lookup-menu { position: absolute; top: 58px; left: 0; right: 0; max-height: 280px; overflow: auto; background: #fff; border: 1px solid #cbd5e1; border-radius: 6px; box-shadow: 0 14px 30px rgba(15, 23, 42, .16); z-index: 20; }
.lookup-menu button { width: 100%; display: block; border-radius: 0; border-bottom: 1px solid #edf0f5; background: #fff; color: #0f172a; text-align: left; padding: 10px 12px; }
.lookup-menu button:hover { background: #f8fafc; }
.lookup-menu span { display: block; font-weight: 700; line-height: 1.35; }
.lookup-value { margin-top: 6px; }
.copy-detail-panel { display: flex; flex-direction: column; gap: 14px; }
.copy-section { border-top: 1px solid #edf0f5; padding-top: 12px; }
.copy-section-head { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.image-download-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.download-card { display: grid; grid-template-columns: 78px minmax(0, 1fr); gap: 10px; align-items: center; border: 1px solid #edf0f5; border-radius: 6px; padding: 8px; background: #fbfdff; }
.download-card img { width: 78px; height: 78px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.download-card span { color: #0f172a; display: block; font-size: 13px; font-weight: 700; margin-bottom: 6px; }
.inline-actions { display: flex; flex-wrap: wrap; gap: 6px; }
.button-link { display: inline-flex; align-items: center; justify-content: center; min-height: 31px; border: 1px solid #d7dde8; border-radius: 6px; color: #334155; background: #fff; font-size: 13px; font-weight: 700; padding: 6px 10px; text-decoration: none; }
.copy-fields { display: grid; gap: 10px; }
.copy-field label { color: #64748b; display: block; font-size: 12px; margin-bottom: 6px; }
.copy-input-row { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 8px; align-items: start; }
.copy-input-row input { min-height: 36px; }
.variant-copy-table { border: 1px solid #edf0f5; border-radius: 6px; overflow: hidden; }
.variant-copy-head, .variant-copy-row { display: grid; grid-template-columns: 1.4fr 1fr .8fr .6fr 1fr; gap: 8px; align-items: stretch; }
.variant-copy-head { color: #64748b; background: #f8fafc; font-size: 12px; font-weight: 800; padding: 9px 10px; }
.variant-copy-row { border-top: 1px solid #edf0f5; padding: 9px 10px; }
.variant-copy-row > div { min-width: 0; display: grid; gap: 6px; align-content: start; }
.variant-copy-row span, .variant-copy-row strong { overflow-wrap: anywhere; }
.variant-thumb { width: 54px; height: 54px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.empty-inline { color: #64748b; border: 1px dashed #d7dde8; border-radius: 6px; padding: 14px; text-align: center; }
button { border: 0; border-radius: 6px; cursor: pointer; font-size: 13px; font-weight: 700; padding: 9px 12px; }
button:disabled { cursor: not-allowed; opacity: .6; }
.primary { color: #fff; background: #0f5fc7; }
.ghost { color: #334155; background: #fff; border: 1px solid #d7dde8; }
.mini { padding: 7px 10px; white-space: nowrap; }
.notice { border: 1px solid #d9e2ec; border-radius: 6px; font-size: 13px; margin-top: 10px; padding: 10px 12px; }
.notice.error { color: #991b1b; background: #fef2f2; border-color: #fecaca; }
.notice.success { color: #166534; background: #ecfdf5; border-color: #86efac; position: fixed; right: 18px; bottom: 18px; z-index: 30; }
.product-preview { display: grid; grid-template-columns: 76px minmax(0, 1fr); gap: 12px; align-items: center; border-bottom: 1px solid #e5e7eb; margin-bottom: 12px; padding-bottom: 12px; }
.product-preview img, .image-fallback { width: 76px; height: 76px; border-radius: 6px; object-fit: cover; background: #eef2f7; }
.image-fallback { display: grid; place-items: center; color: #64748b; font-weight: 800; }
strong { color: #0f172a; display: block; line-height: 1.35; }
small { display: block; line-height: 1.55; }
pre { max-height: 360px; overflow: auto; border: 1px solid #edf0f5; border-radius: 6px; background: #f8fafc; color: #334155; font-size: 12px; line-height: 1.5; padding: 10px; white-space: pre-wrap; }
@media (max-width: 1100px) {
  .control-grid, .marketplace-copy-grid, .detail-grid { grid-template-columns: 1fr; }
  .variant-copy-head { display: none; }
  .variant-copy-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 820px) {
  .page-shell { margin-left: 0; padding: 16px; }
  .page-header, .header-actions, .panel-title { align-items: stretch; flex-direction: column; }
  .field-grid { grid-template-columns: 1fr; }
  .image-download-grid, .copy-input-row, .variant-copy-row { grid-template-columns: 1fr; }
}
</style>
