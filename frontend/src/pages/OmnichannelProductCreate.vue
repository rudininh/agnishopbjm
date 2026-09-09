<template>
  <main class="omnichannel-create-page">
    <div class="page-shell">
      <header class="page-header">
        <div>
          <p class="eyebrow">Katalog omnichannel</p>
          <h1>Tambah Produk</h1>
          <p class="page-subtitle">Input sekali untuk menyiapkan produk Shopee dan TikTok.</p>
        </div>
        <RouterLink class="back-link" to="/products">Kembali ke produk</RouterLink>
      </header>

      <div v-if="notice.message" class="notice" :class="notice.type" role="status">
        {{ notice.message }}
      </div>

      <form class="product-form" @submit.prevent="submitProduct">
        <section class="panel">
          <div class="section-heading">
            <div>
              <p class="section-kicker">Langkah 1</p>
              <h2>Informasi produk</h2>
            </div>
            <button class="secondary-button" type="button" @click="useRandomExample">
              Isi contoh random
            </button>
          </div>

          <div class="field-grid">
            <label class="field field-wide">
              <span>Nama produk</span>
              <input v-model="form.name" type="text" placeholder="Contoh: Kemeja Flanel" />
              <small v-if="fieldError('name')">{{ fieldError('name') }}</small>
            </label>

            <label class="field">
              <span>SKU produk</span>
              <input v-model="form.sku" type="text" placeholder="FLANEL-001" />
              <small v-if="fieldError('sku')">{{ fieldError('sku') }}</small>
            </label>

            <label class="field">
              <span>Kategori internal</span>
              <select v-model="form.category_id">
                <option value="">Pilih kategori</option>
                <option v-for="category in categories" :key="category.uuid" :value="category.uuid">
                  {{ category.name }}
                </option>
              </select>
              <small v-if="fieldError('category_id')">{{ fieldError('category_id') }}</small>
            </label>

            <label class="field">
              <span>Harga dasar</span>
              <input v-model="form.price" type="number" min="0" step="1" placeholder="125000" />
            </label>

            <label class="field">
              <span>Total stok</span>
              <input v-model="form.stock" type="number" min="0" step="1" placeholder="15" />
            </label>

            <label class="field field-wide">
              <span>Deskripsi</span>
              <textarea v-model="form.description" rows="4" placeholder="Jelaskan produk secara singkat..."></textarea>
            </label>
          </div>
        </section>

        <section class="panel">
          <div class="section-heading">
            <div>
              <p class="section-kicker">Langkah 2</p>
              <h2>Target marketplace</h2>
            </div>
            <span class="live-badge">Siap publish live</span>
          </div>

          <div class="marketplace-grid">
            <label class="marketplace-card" :class="{ selected: form.accounts.includes('shopee-agnishopbjm') }">
              <input v-model="form.accounts" type="checkbox" value="shopee-agnishopbjm" />
              <span>
                <strong>Shopee AgniShopBJM</strong>
                <small>Listing akan menggunakan SKU varian yang sama.</small>
              </span>
            </label>
            <label class="marketplace-card" :class="{ selected: form.accounts.includes('tiktok-agnishopbjm') }">
              <input v-model="form.accounts" type="checkbox" value="tiktok-agnishopbjm" />
              <span>
                <strong>TikTok AgniShopBJM</strong>
                <small>Warehouse dan kategori dipakai saat publish live.</small>
              </span>
            </label>
          </div>

          <div class="marketplace-settings">
            <div v-if="selectedAccount('shopee-agnishopbjm')" class="marketplace-setting-card">
              <h3>Mapping Shopee</h3>
              <div class="field-grid">
                <label class="field">
                  <span>Kategori Shopee</span>
                  <input v-model="form.marketplace.shopee.category_id" type="text" placeholder="ID kategori Shopee" />
                  <small v-if="fieldError('marketplace.shopee.category_id')">{{ fieldError('marketplace.shopee.category_id') }}</small>
                </label>
                <label class="field">
                  <span>Berat (kg)</span>
                  <input v-model="form.marketplace.shopee.weight" type="number" min="0.01" step="0.01" placeholder="0.5" />
                  <small v-if="fieldError('marketplace.shopee.weight')">{{ fieldError('marketplace.shopee.weight') }}</small>
                </label>
              </div>
            </div>

            <div v-if="selectedAccount('tiktok-agnishopbjm')" class="marketplace-setting-card">
              <h3>Mapping TikTok</h3>
              <div class="field-grid">
                <label class="field">
                  <span>Kategori TikTok</span>
                  <input v-model="form.marketplace.tiktok.category_id" type="text" placeholder="ID kategori TikTok" />
                  <small v-if="fieldError('marketplace.tiktok.category_id')">{{ fieldError('marketplace.tiktok.category_id') }}</small>
                </label>
                <label class="field">
                  <span>Warehouse ID</span>
                  <input v-model="form.marketplace.tiktok.warehouse_id" type="text" placeholder="Warehouse TikTok" />
                  <small v-if="fieldError('marketplace.tiktok.warehouse_id')">{{ fieldError('marketplace.tiktok.warehouse_id') }}</small>
                </label>
              </div>
            </div>
          </div>
          <p class="helper-text">Setelah produk tersimpan, sistem langsung membuat run publish dan menyimpan status Shopee/TikTok terpisah.</p>
        </section>

        <section class="panel">
          <div class="section-heading">
            <div>
              <p class="section-kicker">Langkah 3</p>
              <h2>Varian produk</h2>
            </div>
            <button class="secondary-button" type="button" @click="addVariant">+ Tambah varian</button>
          </div>

          <div v-if="form.variants.length === 0" class="empty-variants">Belum ada varian.</div>

          <article v-for="(variant, index) in form.variants" :key="index" class="variant-card">
            <div class="variant-heading">
              <strong>Varian {{ index + 1 }}</strong>
              <button v-if="form.variants.length > 1" class="remove-button" type="button" @click="removeVariant(index)">
                Hapus
              </button>
            </div>
            <div class="field-grid variant-grid">
              <label class="field">
                <span>Nama varian</span>
                <input v-model="variant.variant_name" type="text" placeholder="Merah - M" />
                <small v-if="fieldError(`variants.${index}.variant_name`)">{{ fieldError(`variants.${index}.variant_name`) }}</small>
              </label>
              <label class="field">
                <span>SKU varian</span>
                <input v-model="variant.sku" type="text" placeholder="FLANEL-001-MERAH-M" />
                <small v-if="fieldError(`variants.${index}.sku`)">{{ fieldError(`variants.${index}.sku`) }}</small>
              </label>
              <label class="field">
                <span>Harga</span>
                <input v-model="variant.price" type="number" min="0" step="1" placeholder="125000" />
              </label>
              <label class="field">
                <span>Stok</span>
                <input v-model="variant.stock" type="number" min="0" step="1" placeholder="8" />
              </label>
              <label class="field field-wide">
                <span>URL gambar</span>
                <input v-model="variant.image_url" type="url" placeholder="https://.../gambar.jpg" />
                <small v-if="fieldError(`variants.${index}.image_url`)">{{ fieldError(`variants.${index}.image_url`) }}</small>
              </label>
            </div>
          </article>
        </section>

        <div v-if="errors.length" class="error-summary" role="alert">
          <strong>Periksa kembali:</strong>
          <span v-for="error in errors" :key="`${error.code}-${error.field}`">{{ error.message }}</span>
        </div>

        <section v-if="publicationRun" class="panel publication-panel">
          <div class="section-heading">
            <div>
              <p class="section-kicker">Status publish</p>
              <h2>Run {{ publicationRun.id }}</h2>
            </div>
            <span class="status-pill" :class="publicationRun.status">{{ publicationRun.status }}</span>
          </div>
          <div class="publication-grid">
            <article v-for="result in publicationRun.results" :key="result.account_key" class="publication-card">
              <strong>{{ accountLabel(result.account_key) }}</strong>
              <span class="status-pill" :class="result.status">{{ result.status }}</span>
              <small v-if="result.remote_product_id">Remote ID: {{ result.remote_product_id }}</small>
              <small v-if="result.error_message">{{ result.error_message }}</small>
            </article>
          </div>
          <button v-if="canRetry" class="secondary-button retry-button" type="button" :disabled="saving" @click="retryPublication">
            {{ saving ? 'Memproses...' : 'Retry yang gagal' }}
          </button>
        </section>

        <div class="form-actions">
          <RouterLink class="cancel-button" to="/products">Batal</RouterLink>
          <button class="primary-button" type="submit" :disabled="saving">
            {{ saving ? 'Menyimpan + publish...' : 'Simpan & Publish Live' }}
          </button>
        </div>
      </form>
    </div>
  </main>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { categoryService, productService } from '@/services'
import {
  createDefaultProductForm,
  fillRandomProduct,
  normalizeProductPayload,
  normalizePublicationPayload,
  validateProductForm
} from './omnichannelProductFormState.js'

const form = ref(createDefaultProductForm())
const categories = ref([])
const errors = ref([])
const saving = ref(false)
const publicationRun = ref(null)
const notice = ref({ type: '', message: '' })

const fieldError = (field) => errors.value.find((error) => error.field === field)?.message || ''
const selectedAccount = (accountKey) => form.value.accounts.includes(accountKey)
const canRetry = computed(() => publicationRun.value?.results?.some((result) => result.status === 'failed'))
const accountLabel = (accountKey) => ({
  'shopee-agnishopbjm': 'Shopee AgniShopBJM',
  'tiktok-agnishopbjm': 'TikTok AgniShopBJM'
}[accountKey] || accountKey)

const addVariant = () => {
  form.value.variants.push({ variant_name: '', sku: '', price: '', stock: 0, image_url: '' })
}

const removeVariant = (index) => {
  form.value.variants.splice(index, 1)
}

const useRandomExample = () => {
  form.value = fillRandomProduct(form.value)
  if (!form.value.category_id && categories.value[0]) form.value.category_id = categories.value[0].uuid
  errors.value = []
  notice.value = { type: 'success', message: 'Contoh produk random sudah diisi.' }
}

const loadCategories = async () => {
  try {
    const { data } = await categoryService.getAll(1, 100)
    categories.value = data.data || []
  } catch (error) {
    notice.value = { type: 'warning', message: error.response?.data?.message || 'Kategori gagal dimuat.' }
  }
}

const submitProduct = async () => {
  errors.value = validateProductForm(form.value).errors
  notice.value = { type: '', message: '' }
  publicationRun.value = null
  if (errors.value.length) return

  saving.value = true
  try {
    const created = await productService.create(normalizeProductPayload(form.value))
    const productId = created.data?.data?.uuid
    const published = await productService.publishToMarketplaces(productId, normalizePublicationPayload(form.value))
    publicationRun.value = published.data?.data || null
    notice.value = {
      type: publicationRun.value?.status === 'success' ? 'success' : 'warning',
      message: publicationRun.value?.status === 'success'
        ? 'Produk tersimpan dan publish berhasil diproses.'
        : 'Produk tersimpan. Beberapa marketplace perlu retry atau adapter live final.'
    }
  } catch (error) {
    notice.value = {
      type: 'warning',
      message: error.response?.data?.message || 'Produk gagal disimpan/publish.'
    }
  } finally {
    saving.value = false
  }
}

const retryPublication = async () => {
  if (!publicationRun.value?.id) return

  saving.value = true
  try {
    const retried = await productService.retryPublicationRun(publicationRun.value.id)
    publicationRun.value = retried.data?.data || publicationRun.value
    notice.value = {
      type: publicationRun.value?.status === 'success' ? 'success' : 'warning',
      message: publicationRun.value?.status === 'success' ? 'Retry berhasil.' : 'Retry selesai, masih ada marketplace gagal.'
    }
  } catch (error) {
    notice.value = { type: 'warning', message: error.response?.data?.message || 'Retry publish gagal.' }
  } finally {
    saving.value = false
  }
}

onMounted(loadCategories)
</script>

<style scoped>
.omnichannel-create-page { min-height: 100vh; padding: 24px 16px 48px; background: #f5f7fb; color: #172033; }
.page-shell { width: min(980px, 100%); margin: 0 auto; }
.page-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; margin-bottom: 20px; }
.eyebrow, .section-kicker { margin: 0 0 5px; color: #64748b; font-size: .76rem; font-weight: 800; letter-spacing: .1em; text-transform: uppercase; }
h1, h2 { margin: 0; color: #172033; }
h1 { font-size: clamp(1.65rem, 5vw, 2.2rem); }
h2 { font-size: 1.15rem; }
.page-subtitle { margin: 8px 0 0; color: #64748b; }
.back-link, .cancel-button { color: #475569; font-weight: 700; text-decoration: none; }
.panel { margin-bottom: 16px; padding: 20px; border: 1px solid #e2e8f0; border-radius: 16px; background: #fff; box-shadow: 0 8px 24px rgba(15, 23, 42, .04); }
.section-heading, .variant-heading, .form-actions { display: flex; align-items: center; justify-content: space-between; gap: 14px; }
.section-heading { margin-bottom: 18px; }
.field-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.field { display: flex; min-width: 0; flex-direction: column; gap: 7px; color: #334155; font-size: .88rem; font-weight: 700; }
.field-wide { grid-column: 1 / -1; }
input, select, textarea { width: 100%; box-sizing: border-box; border: 1px solid #cbd5e1; border-radius: 10px; padding: 11px 12px; background: #fff; color: #172033; font: inherit; font-weight: 500; }
input:focus, select:focus, textarea:focus { outline: 3px solid rgba(37, 99, 235, .14); border-color: #2563eb; }
small { color: #b42318; font-size: .78rem; font-weight: 600; }
.secondary-button, .primary-button, .cancel-button, .remove-button { border-radius: 10px; padding: 10px 14px; font: inherit; font-weight: 800; cursor: pointer; }
.secondary-button { border: 1px solid #bfdbfe; background: #eff6ff; color: #1d4ed8; }
.primary-button { border: 0; background: #2563eb; color: #fff; }
.primary-button:disabled { cursor: wait; opacity: .65; }
.cancel-button { border: 1px solid #cbd5e1; color: #475569; }
.remove-button { border: 0; background: #fef2f2; color: #b91c1c; padding: 7px 10px; }
.notice, .error-summary { margin-bottom: 16px; border-radius: 10px; padding: 12px 14px; }
.notice.success { border: 1px solid #bbf7d0; background: #f0fdf4; color: #166534; }
.notice.warning, .error-summary { border: 1px solid #fecaca; background: #fef2f2; color: #991b1b; }
.error-summary { display: flex; flex-direction: column; gap: 4px; }
.error-summary strong { margin-bottom: 2px; }
.marketplace-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.marketplace-card { display: flex; align-items: flex-start; gap: 10px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; cursor: pointer; }
.marketplace-card.selected { border-color: #93c5fd; background: #eff6ff; }
.marketplace-card input { width: auto; margin-top: 3px; }
.marketplace-card span { display: flex; flex-direction: column; gap: 4px; }
.marketplace-card small, .helper-text { color: #64748b; }
.live-badge { border-radius: 999px; padding: 6px 9px; background: #fef3c7; color: #92400e; font-size: .73rem; font-weight: 800; }
.helper-text { margin: 14px 0 0; font-size: .84rem; }
.marketplace-settings { display: grid; gap: 12px; margin-top: 14px; }
.marketplace-setting-card { border: 1px solid #dbeafe; border-radius: 12px; padding: 14px; background: #f8fbff; }
.marketplace-setting-card h3 { margin: 0 0 12px; color: #1e3a8a; font-size: .98rem; }
.publication-panel { border-color: #bfdbfe; }
.publication-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.publication-card { display: flex; flex-direction: column; gap: 7px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; background: #fff; }
.status-pill { align-self: flex-start; border-radius: 999px; padding: 5px 9px; background: #e2e8f0; color: #334155; font-size: .74rem; font-weight: 900; text-transform: uppercase; }
.status-pill.success { background: #dcfce7; color: #166534; }
.status-pill.failed { background: #fee2e2; color: #991b1b; }
.status-pill.partial_success, .status-pill.running, .status-pill.pending { background: #fef3c7; color: #92400e; }
.retry-button { margin-top: 14px; }
.variant-card { margin-top: 12px; border: 1px solid #e2e8f0; border-radius: 12px; padding: 14px; }
.variant-heading { margin-bottom: 14px; }
.empty-variants { border: 1px dashed #cbd5e1; border-radius: 10px; padding: 18px; color: #64748b; text-align: center; }
.form-actions { justify-content: flex-end; margin-top: 18px; }

@media (max-width: 640px) {
  .omnichannel-create-page { padding: 16px 10px 32px; }
  .page-header, .section-heading { align-items: stretch; flex-direction: column; }
  .back-link { align-self: flex-start; }
  .panel { padding: 16px; border-radius: 13px; }
  .field-grid, .marketplace-grid, .publication-grid { grid-template-columns: 1fr; }
  .field-wide { grid-column: auto; }
  .form-actions { align-items: stretch; flex-direction: column-reverse; }
  .form-actions > * { width: 100%; box-sizing: border-box; text-align: center; }
}
</style>
