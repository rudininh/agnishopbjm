<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Eksperimen TikTok</p>
        <h1>Tambah Varian</h1>
        <small class="subtitle">Halaman uji untuk satu etalase: create / update / mapping varian.</small>
      </div>
      <div class="header-actions">
        <button class="ghost" @click="loadData(true)" :disabled="loading">{{ loading ? 'Memuat...' : 'Refresh' }}</button>
        <button class="primary" @click="save" :disabled="!selectedItem || !form.stock_master_id || saving">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
        <button class="ghost" @click.stop="selectedItem && selectItem(selectedItem)" :disabled="!selectedItem">Edit</button>
        <button
          v-if="selectedItem && canPrepareMissingVariant(selectedItem)"
          class="primary"
          :disabled="preparing"
          @click="prepareMissingVariant(selectedItem)"
        >
          {{ preparing ? 'Menyiapkan...' : `Buat Varian Hilang di ${missingTargetChannel(selectedItem) === 'tiktok' ? 'TikTok' : 'Shopee'}` }}
        </button>
      </div>
    </header>

    <p v-if="loadError" class="notice error">{{ loadError }}</p>
    <p v-else-if="loading && !items.length" class="notice">Memuat data etalase uji...</p>

    <div class="control-band">
      <label>
        <span>Nama etalase uji</span>
        <input v-model.trim="filters.search" type="search" placeholder="Azara Hijab Segi Empat Polos Paris Packing Pouch Metal Logo" @keyup.enter="loadData(true)" />
      </label>
      <button class="ghost" @click="loadData(true)" :disabled="loading">Muat etalase ini</button>
    </div>

    <div class="summary-grid" v-if="summaryItem">
      <article class="metric">
        <span>Item id dan Model id Shopee</span>
        <strong>{{ summaryItem.shopee?.item_id || '-' }}</strong>
        <small>Model ID: {{ summaryItem.shopee?.model_id || '-' }}</small>
      </article>
      <article class="metric">
        <span>Product ID TikTok</span>
        <strong>{{ summaryItem.tiktok?.product_id || '-' }}</strong>
        <small>{{ summaryItem.tiktok?.variant_name || summaryItem.variant_name || '-' }}</small>
      </article>
    </div>

    <div class="layout">
      <div class="panel list-panel">
        <div class="group-head" v-if="activeGroup">
          <div class="group-title">
            <img v-if="activeGroup.image_url" :src="activeGroup.image_url" class="thumb large" :alt="activeGroup.name" />
            <div v-else class="thumb large fallback">{{ initials(activeGroup.name) }}</div>
            <div>
              <strong>{{ activeGroup.name }}</strong>
              <small>Shopee: {{ activeGroup.shopee.present }} varian, stok {{ activeGroup.shopee.total_stock }}</small>
              <small>TikTok: {{ activeGroup.tiktok.present }} varian, stok {{ activeGroup.tiktok.total_stock }}</small>
            </div>
          </div>
          <span :class="['badge', activeGroup.status]">{{ labelStatus(activeGroup.status) }}</span>
        </div>
        <div v-else class="group-empty">
          Tidak ada data untuk filter yang dipilih.
        </div>

        <div class="table-filters">
          <label>
            <span>Status</span>
            <select v-model="filters.status" @change="loadData(true)">
              <option value="all">Semua</option>
              <option value="ready_to_sync">Siap Disinkronkan</option>
              <option value="belum_ada_variant">Belum Ada Variant</option>
            </select>
          </label>
          <div class="filter-hint">Filter ini berlaku untuk data tabel dan pagination di bawahnya.</div>
        </div>

        <div class="table-wrap">
          <table class="mapping-table">
            <colgroup>
              <col class="col-product" />
              <col class="col-channel" />
              <col class="col-channel" />
              <col class="col-status" />
            </colgroup>
            <thead>
              <tr>
                <th>Varian</th>
                <th>Shopee</th>
                <th>TikTok</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="!displayVariants.length">
                <td colspan="4" class="empty-row">Tidak ada baris untuk filter ini.</td>
              </tr>
              <tr
                v-for="item in displayVariants"
                :key="item.id"
                :class="['variant-row', { active: selectedItem?.id === item.id }]"
                @click="selectItem(item)"
              >
                <td>
                  <div class="variant-title">
                    <strong>{{ item.variant_name || 'Tanpa Varian' }}</strong>
                    <small>SKU internal: {{ item.internal_sku }}</small>
                    <small>Stock Master: {{ item.stock_qty }}</small>
                  </div>
                </td>
                <td>
                  <div class="channel-cell">
                    <img v-if="item.shopee?.image_url" :src="item.shopee.image_url" class="thumb" :alt="item.shopee.variant_name" />
                    <div v-else class="thumb fallback">SP</div>
                    <div>
                      <strong>{{ item.shopee?.variant_name || item.shopee?.product_name || '-' }}</strong>
                      <small>{{ shopeePresenceLabel(item) }}</small>
                      <small>Item ID: {{ item.shopee?.item_id || '-' }}</small>
                      <small>Model ID: {{ item.shopee?.model_id || '-' }}</small>
                      <small>Kode Variasi: {{ item.shopee?.seller_sku || item.seller_sku || '-' }}</small>
                      <small>Stok: {{ displayStock(item.shopee?.stock_qty) }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <div :class="['channel-cell', { muted: !hasTiktok(item) }]">
                    <img v-if="item.tiktok?.image_url" :src="item.tiktok.image_url" class="thumb" :alt="item.tiktok.variant_name" />
                    <div v-else class="thumb fallback">TT</div>
                    <div>
                      <div class="channel-title-row">
                        <strong>{{ item.variant_name || item.tiktok?.variant_name || '-' }}</strong>
                        <span
                          v-if="item.tiktok?.status && item.tiktok.status !== 'unmapped'"
                          :class="['channel-badge', item.tiktok.status]"
                        >
                          {{ channelStatusLabel(item.tiktok.status) }}
                        </span>
                      </div>
                      <small>{{ tiktokPresenceLabel(item) }}</small>
                      <small>{{ item.tiktok?.product_name || '-' }}</small>
                      <small>Product ID: {{ hasTiktokActual(item) ? (item.tiktok?.product_id || '-') : '-' }}</small>
                      <small>SKU ID: {{ hasTiktokActual(item) ? (item.tiktok?.sku_id || '-') : '-' }}</small>
                      <small>Nama Varian Asli: {{ item.variant_name || item.tiktok?.variant_name || '-' }}</small>
                      <small>Kode Variasi: {{ item.tiktok?.seller_sku || item.seller_sku || '-' }}</small>
                      <small v-if="hasTiktokActual(item)">Kode SKU TikTok: {{ item.tiktok?.sku_name || '-' }}</small>
                      <small v-else>Kode ini belum menunjuk ke varian TikTok yang aktif.</small>
                      <small>Stok: {{ hasTiktokActual(item) ? displayStock(item.tiktok?.stock_qty) : '-' }}</small>
                    </div>
                  </div>
                </td>
                <td>
                  <span :class="['badge', item.status]">{{ labelStatus(item.status) }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="pagination" v-if="pagination.last_page > 1">
          <button class="ghost" @click="changePage(pagination.page - 1)" :disabled="loading || pagination.page <= 1">Prev</button>
          <span>Halaman {{ pagination.page }} / {{ pagination.last_page }} | {{ pagination.total }} data</span>
          <button class="ghost" @click="changePage(pagination.page + 1)" :disabled="loading || pagination.page >= pagination.last_page">Next</button>
        </div>

        <div class="api-panel">
          <div class="api-panel-head">
            <div>
              <strong>API Testing Tool</strong>
              <small>Quickly obtain real request parameters and response parameters through this tool. <a href="#" @click.prevent>View guide.</a></small>
            </div>
          </div>
          <div class="api-testing-tool">
            <div class="api-left">
              <div class="api-apirow">
                <div class="api-label">API name</div>
                <div class="api-apiname">
                  <div>
                    <strong>Get Product</strong>
                    <small>/product/{version}/products/{product_id}</small>
                  </div>
                  <span class="api-chip">GET</span>
                </div>
              </div>

              <div class="api-version-row">
                <label class="api-inline">
                  <span>Version</span>
                  <select v-model="getProductTool.version">
                    <option value="202309">202309</option>
                  </select>
                </label>
                <a href="#" class="api-doc-link" @click.prevent>View API doc</a>
              </div>

              <div class="api-section-title">Authorized shop info</div>
              <div class="api-shop-auth">
                <a href="#" class="api-shop-link" @click.prevent>Get shop authorization</a>
                <label>
                  <span>shop_id (string) (Optional)</span>
                  <input v-model.trim="getProductTool.shop_id" placeholder="7495811028690242494" />
                </label>
                <label>
                  <span>shop_cipher (string) (Optional)</span>
                  <input v-model.trim="getProductTool.shop_cipher" placeholder="ROW_AfAcCgAAAA..." />
                </label>
                <label>
                  <span>access_token (string)</span>
                  <input v-model.trim="getProductTool.access_token" placeholder="ROW_2ZAVYAAAA..." />
                </label>
                <label>
                  <span>Version (string)</span>
                  <input :value="getProductTool.version" disabled />
                </label>
              </div>

              <div class="api-section-title">Path parameters</div>
              <div class="api-path-params">
                <label>
                  <span>product_id (string)</span>
                  <input v-model.trim="getProductTool.product_id" />
                </label>
              </div>

              <div class="api-section-title">Query request parameters</div>
              <div class="api-query-params">
                <div class="api-bool-row">
                  <span>return_under_review_version (boolean) (Optional)</span>
                  <div class="api-radio-group">
                    <label><input type="radio" value="true" v-model="getProductTool.return_under_review_version" /> true</label>
                    <label><input type="radio" value="false" v-model="getProductTool.return_under_review_version" /> false</label>
                  </div>
                </div>
                <div class="api-bool-row">
                  <span>return_draft_version (boolean) (Optional)</span>
                  <div class="api-radio-group">
                    <label><input type="radio" value="true" v-model="getProductTool.return_draft_version" /> true</label>
                    <label><input type="radio" value="false" v-model="getProductTool.return_draft_version" /> false</label>
                  </div>
                </div>
                <label>
                  <span>locale (string) (Optional)</span>
                  <input v-model.trim="getProductTool.locale" />
                </label>
              </div>

              <div class="api-submit-row">
                <button class="primary" @click="submitGetProductDemo" :disabled="getProductRequestBusy">
                  {{ getProductRequestBusy ? 'Submitting...' : 'Submit Request' }}
                </button>
              </div>
            </div>

            <div class="api-right">
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Request demo (cURL)</strong>
                  <button class="ghost mini" type="button" @click="copyGetProductCurl">Copy</button>
                </div>
                <pre>{{ apiGetProductCurl }}</pre>
              </div>
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Response</strong>
                  <button class="ghost mini" type="button" @click="copyGetProductResponse">Copy</button>
                </div>
                <div class="api-status-line">
                  <span>Status:</span>
                  <span class="status-dot">{{ getProductResponseStatus }}</span>
                </div>
                <p v-if="apiGetProductResponseHint" class="api-response-hint">{{ apiGetProductResponseHint }}</p>
                <div class="api-response-viewer">
                  <div class="api-response-lines">
                    <span v-for="line in apiGetProductResponseLines" :key="line.no">{{ line.no }}</span>
                  </div>
                  <pre>{{ getProductResponseText || ' ' }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="api-panel variant-panel">
          <div class="api-panel-head">
            <div>
              <strong>Tambah Variant/SKU TikTok</strong>
              <small>Workflow aman: klik baris berstatus belum ada variant untuk autofill data Shopee, lalu GET product terbaru, normalize payload edit, append 1 SKU baru, lalu copy payload untuk PUT.</small>
            </div>
          </div>
          <div class="variant-tool">
            <div class="api-left">
              <div class="api-section-title">Target product</div>
              <div class="api-path-params">
                <label>
                  <span>product_id (string)</span>
                  <input v-model.trim="addVariantTool.product_id" />
                </label>
                <label>
                  <span>shop_cipher (string)</span>
                  <input v-model.trim="addVariantTool.shop_cipher" placeholder="ROW_AfAcCgAAAA..." />
                </label>
              </div>

              <div class="api-section-title">SKU baru</div>
              <div class="api-query-params">
                <label>
                  <span>seller_sku</span>
                  <input v-model.trim="addVariantTool.seller_sku" placeholder="SKU-BARU-001" />
                </label>
                <label>
                  <span>color_name</span>
                  <input v-model.trim="addVariantTool.color_name" placeholder="Biru" />
                </label>
                <label>
                  <span>image_uri</span>
                  <input v-model.trim="addVariantTool.image_uri" placeholder="tos-maliva-.../..." />
                </label>
                <label>
                  <span>price</span>
                  <input v-model.trim="addVariantTool.price" placeholder="50000" />
                </label>
                <label>
                  <span>quantity</span>
                  <input v-model.number="addVariantTool.quantity" type="number" min="0" />
                </label>
                <label class="dry-run-row">
                  <input type="checkbox" v-model="addVariantTool.dry_run" />
                  <span>Dry run saja, jangan PUT ke TikTok</span>
                </label>
              </div>

              <div class="api-submit-row">
                <button class="ghost" @click="loadAddVariantContext" :disabled="addVariantBusy">Ambil Context</button>
                <button class="primary" @click="submitAddVariant" :disabled="addVariantBusy">
                  {{ addVariantBusy ? 'Generating...' : 'Generate Payload' }}
                </button>
              </div>
            </div>

            <div class="api-right">
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Request demo</strong>
                  <button class="ghost mini" type="button" @click="copyAddVariantRequest">Copy</button>
                </div>
                <pre>{{ addVariantRequestPreview }}</pre>
              </div>
              <div class="api-right-block">
                <div class="api-right-head">
                  <strong>Response</strong>
                  <button class="ghost mini" type="button" @click="copyAddVariantResponse">Copy</button>
                </div>
                <div class="api-status-line">
                  <span>Status:</span>
                  <span class="status-dot">{{ addVariantResponseStatus }}</span>
                </div>
                <div class="api-response-viewer">
                  <div class="api-response-lines">
                    <span v-for="line in addVariantResponseLines" :key="line.no">{{ line.no }}</span>
                  </div>
                  <pre>{{ addVariantResponseText || ' ' }}</pre>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <aside class="panel detail-panel" v-if="selectedItem">
        <div class="detail-head">
          <div>
            <span>Nama Varian Asli</span>
            <strong>{{ selectedItem.variant_name || 'Tanpa Varian' }}</strong>
            <small>{{ selectedItem.product_name }}</small>
          </div>
          <img v-if="selectedItem.image_url" :src="selectedItem.image_url" class="thumb large" :alt="selectedItem.product_name" />
          <div v-else class="thumb large fallback">{{ initials(selectedItem.product_name) }}</div>
        </div>

        <label>
          <span>Shopee Item ID</span>
          <input v-model="form.shopee_item_id" />
        </label>
        <label>
          <span>Shopee Model ID</span>
          <input v-model="form.shopee_model_id" />
        </label>
        <label>
          <span>Kode Variasi</span>
          <input v-model="form.seller_sku" />
        </label>
        <label>
          <span>TikTok Product ID</span>
          <input v-model="form.tiktok_product_id" />
        </label>
        <label>
          <span>TikTok SKU ID</span>
          <input v-model="form.tiktok_sku_id" />
        </label>
        <label>
          <span>Nama Varian TikTok</span>
          <input v-model="form.tiktok_sku_name" />
        </label>
        <label>
          <span>Warehouse ID TikTok</span>
          <input v-model="form.warehouse_id" placeholder="7068517275539719942" />
        </label>
        <label>
          <span>Stok TikTok</span>
          <input v-model="form.inventory_qty" type="number" min="0" />
        </label>
        <label>
          <span>Notes</span>
          <textarea v-model="form.notes" rows="4"></textarea>
        </label>
        <p class="notice">{{ shopeeDetailHint(selectedItem) }} {{ tiktokDetailHint(selectedItem) }}</p>

        <div class="actions stacked">
          <button class="ghost" @click="fillFromSelected">Reset</button>
          <button class="primary" @click="save" :disabled="saving || !form.stock_master_id">{{ saving ? 'Saving...' : 'Save Mapping' }}</button>
        </div>
        <div class="action-grid">
          <button class="ghost" @click="runTiktokAction('upload_image')" :disabled="actionBusy || !selectedItem">Upload Gambar</button>
          <button class="ghost" @click="runTiktokAction('save_product')" :disabled="actionBusy || !selectedItem">Simpan Produk</button>
          <button class="ghost" @click="runTiktokAction('update_inventory')" :disabled="actionBusy || !selectedItem">Update Stok</button>
          <button class="primary" @click="runTiktokAction('full_sync')" :disabled="actionBusy || !selectedItem">{{ actionBusy ? 'Memproses...' : 'Full Sync TikTok' }}</button>
        </div>
        <p v-if="actionLog" class="notice">{{ actionLog }}</p>
        <p v-if="!form.stock_master_id" class="notice">Baris ini hanya ada di TikTok. Save Mapping aktif setelah dipasangkan ke varian Shopee.</p>
      </aside>
    </div>

  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { omnichannelService } from '@/services'

const DEFAULT_PRODUCT_NAME = 'Azara Hijab Segi Empat Polos Paris Packing Pouch Metal Logo'
const DEFAULT_API_PRODUCT_ID = '1732272903733872574'
const TIKTOK_VARIANT_ATTRIBUTE_ID = '100000'
const TIKTOK_VARIANT_ATTRIBUTE_NAME = 'Warna'
const TIKTOK_VARIANT_WAREHOUSE_ID = '7395901885692495617'

const loading = ref(false)
const saving = ref(false)
const preparing = ref(false)
const actionBusy = ref(false)
const loadError = ref('')
const actionLog = ref('')
const items = ref([])
const pagination = reactive({
  page: 1,
  per_page: 5,
  total: 0,
  last_page: 1
})
const selectedItem = ref(null)
const getProductRequestBusy = ref(false)
const getProductResponseText = ref('')
const getProductResponseStatus = ref('0')
const addVariantBusy = ref(false)
const addVariantResponseText = ref('')
const addVariantResponseStatus = ref('0')
const filters = reactive({
  search: DEFAULT_PRODUCT_NAME,
  status: 'belum_ada_variant'
})
const pageCache = new Map()
const form = reactive({
  stock_master_id: null,
  shopee_item_id: '',
  shopee_model_id: '',
  seller_sku: '',
  tiktok_product_id: '',
  tiktok_sku_id: '',
  tiktok_sku_name: '',
  warehouse_id: '',
  inventory_qty: 0,
  notes: ''
})

const addVariantTool = reactive({
  product_id: DEFAULT_API_PRODUCT_ID,
  shop_cipher: '',
  seller_sku: '',
  color_name: '',
  image_uri: '',
  price: '50000',
  quantity: 1,
  dry_run: true
})

const resetForm = () => {
  form.stock_master_id = null
  form.shopee_item_id = ''
  form.shopee_model_id = ''
  form.seller_sku = ''
  form.tiktok_product_id = ''
  form.tiktok_sku_id = ''
  form.tiktok_sku_name = ''
  form.warehouse_id = ''
  form.inventory_qty = 0
  form.notes = ''
}

const normalizeText = (value) => String(value || '').trim().toLowerCase().replace(/\s+/g, ' ')
const formatDate = (value) => value ? new Intl.DateTimeFormat('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(value)) : '-'
const initials = (name) => String(name || 'SK').split(' ').slice(0, 2).map((word) => word[0]).join('').toUpperCase()
const labelStatus = (status) => status === 'ready_to_sync' ? 'Siap Disinkronkan' : 'Belum Ada Variant'
const channelStatusLabel = (status) => status === 'mapped' ? 'Tersimpan' : status === 'suggested' ? 'Kandidat kode variasi' : 'Belum'
const displayStock = (value) => value === null || value === undefined || value === '' ? '-' : Number(value)
const hasTiktokActual = (item) => Boolean(item?.tiktok?.product_id || item?.tiktok?.sku_id || item?.tiktok?.image_url || item?.tiktok?.stock_qty !== null && item?.tiktok?.stock_qty !== undefined)
const hasTiktokCandidate = (item) => Boolean(item?.tiktok?.seller_sku || item?.seller_sku)
const hasShopeeActual = (item) => Boolean(item?.shopee?.item_id || item?.shopee?.model_id || item?.shopee?.image_url || item?.shopee?.stock_qty !== null && item?.shopee?.stock_qty !== undefined)
const hasShopeeCandidate = (item) => Boolean(item?.shopee?.seller_sku || item?.seller_sku)
const hasTiktok = (item) => hasTiktokActual(item) || hasTiktokCandidate(item)
const hasShopee = (item) => hasShopeeActual(item) || hasShopeeCandidate(item)
const shopeePresenceLabel = (item) => hasShopeeActual(item) ? 'Ada di Shopee' : hasShopeeCandidate(item) ? 'Kode variasi cocok, varian Shopee belum ada' : 'Varian ini tidak ada di Shopee'
const tiktokPresenceLabel = (item) => hasTiktokActual(item) ? 'Ada di TikTok' : hasTiktokCandidate(item) ? 'Kode variasi cocok, varian TikTok belum ada' : 'Varian ini tidak ada di TikTok'
const missingTargetChannel = (item) => {
  if (hasShopeeActual(item) && !hasTiktokActual(item)) return 'tiktok'
  if (hasTiktokActual(item) && !hasShopeeActual(item)) return 'shopee'
  return null
}
const canPrepareMissingVariant = (item) => Boolean(missingTargetChannel(item))
const variantSortRank = (item) => {
  return item?.status === 'ready_to_sync' ? 0 : 1
}
const tiktokDetailHint = (item) => hasTiktokActual(item)
  ? 'Data TikTok aktif sudah tersedia.'
  : hasTiktokCandidate(item)
    ? 'Yang cocok baru kode variasinya, belum ada varian TikTok aktif.'
    : 'Varian ini memang belum ada di TikTok.'
const shopeeDetailHint = (item) => hasShopeeActual(item)
  ? 'Data Shopee aktif sudah tersedia.'
  : hasShopeeCandidate(item)
    ? 'Yang cocok baru kode variasinya, belum ada varian Shopee aktif.'
    : 'Varian ini memang belum ada di Shopee.'

const fillAddVariantToolFromItem = (item) => {
  if (!item) return

  const productId = String(item.tiktok?.product_id || item.stock_tiktok_product_id || '').trim()
  if (productId) {
    addVariantTool.product_id = productId
  }

  if (item.status !== 'belum_ada_variant') return

  const sellerSku = String(
    item.shopee?.seller_sku ||
    item.seller_sku ||
    item.stock_shopee_seller_sku ||
    item.internal_sku ||
    item.tiktok?.seller_sku ||
    ''
  ).trim()
  const colorName = String(
    item.shopee?.variant_name ||
    item.variant_name ||
    item.tiktok?.variant_name ||
    item.tiktok?.sku_name ||
    ''
  ).trim()
  const imageUri = String(
    item.shopee?.image_url ||
    item.image_url ||
    item.shopee_model_image_url ||
    item.shopee_product_image_url ||
    item.tiktok?.image_url ||
    ''
  ).trim()
  const priceValue = item.shopee_variant_price ?? item.shopee?.price ?? item.price ?? addVariantTool.price
  const quantityValue = item.shopee_variant_stock ?? item.shopee?.stock_qty ?? item.stock_qty ?? 0

  addVariantTool.seller_sku = sellerSku
  addVariantTool.color_name = colorName
  addVariantTool.image_uri = imageUri
  addVariantTool.price = String(priceValue ?? '').trim() || '50000'
  addVariantTool.quantity = Number(quantityValue ?? 0)
}

const normalizeTextForSku = (value) => String(value || '').trim()

const getFirstSkuFromProductPayload = (payload) => {
  const skus = Array.isArray(payload?.skus) ? payload.skus : []
  return skus.length ? skus[0] : null
}

const mapProductPayloadToVariantDefaults = (payload) => {
  const firstSku = getFirstSkuFromProductPayload(payload)
  const salesAttributes = Array.isArray(firstSku?.sales_attributes) ? firstSku.sales_attributes : []
  const firstAttribute = salesAttributes[0] || {}
  const inventory = Array.isArray(firstSku?.inventory) ? firstSku.inventory[0] : null
  const mainImage = Array.isArray(payload?.main_images) ? payload.main_images[0] : null
  const skuImage = firstAttribute?.sku_img?.uri || ''
  const salePrice = firstSku?.price?.sale_price || firstSku?.price?.amount || firstSku?.price?.tax_exclusive_price || '50000'
  const quantity = inventory?.quantity ?? 0

  return {
    product_id: normalizeTextForSku(payload?.product_id || getProductTool.product_id || selectedItem.value?.tiktok?.product_id || ''),
    seller_sku: normalizeTextForSku(firstSku?.seller_sku || ''),
    color_name: normalizeTextForSku(firstAttribute?.value_name || firstAttribute?.name || ''),
    image_uri: normalizeTextForSku(skuImage || mainImage?.uri || ''),
    price: normalizeTextForSku(salePrice || '50000'),
    quantity: Number(quantity || 0)
  }
}

const groupedItems = computed(() => {
  const groups = new Map()

  items.value.forEach((item) => {
    const key = item.group_key || item.shopee?.item_id || item.tiktok?.product_id || item.product_name || item.internal_sku
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        name: item.product_name || item.shopee?.product_name || item.tiktok?.product_name || 'Produk',
        image_url: item.image_url || item.shopee?.image_url || item.tiktok?.image_url || '',
        variants: [],
        total_stock: 0,
        shopee: { present: 0, missing: 0, total_stock: 0, product_name: item.shopee?.product_name || '' },
        tiktok: { present: 0, missing: 0, total_stock: 0, suggested: 0, product_name: item.tiktok?.product_name || '' },
        status: 'unmapped'
      })
    }

    const group = groups.get(key)
    group.variants.push(item)
    group.total_stock += Number(item.stock_qty || 0)

    if (hasShopeeActual(item)) {
      group.shopee.present += 1
      group.shopee.total_stock += Number(item.shopee?.stock_qty || 0)
    } else {
      group.shopee.missing += 1
    }

    if (hasTiktokActual(item)) {
      group.tiktok.present += 1
      group.tiktok.total_stock += Number(item.tiktok?.stock_qty || 0)
    } else {
      group.tiktok.missing += 1
    }

    if (item.tiktok?.status === 'suggested') group.tiktok.suggested += 1
    if (!group.image_url) group.image_url = item.image_url || item.shopee?.image_url || item.tiktok?.image_url || ''
    if (!group.shopee.product_name && item.shopee?.product_name) group.shopee.product_name = item.shopee.product_name
    if (!group.tiktok.product_name && item.tiktok?.product_name) group.tiktok.product_name = item.tiktok.product_name
  })

  return Array.from(groups.values()).map((group) => {
    return {
      ...group,
      status: group.variants.every((variant) => variant.status === 'ready_to_sync')
        ? 'ready_to_sync'
        : 'belum_ada_variant'
    }
  })
})

const activeGroup = computed(() => {
  if (!groupedItems.value.length) return null
  const exact = groupedItems.value.find((group) => normalizeText(group.name) === normalizeText(filters.search))
  const group = exact || groupedItems.value[0]

  return {
    ...group,
    variants: [...group.variants].sort((a, b) => {
      const rankDiff = variantSortRank(a) - variantSortRank(b)
      if (rankDiff !== 0) return rankDiff

      const aName = normalizeText(a.variant_name || a.tiktok?.variant_name || '')
      const bName = normalizeText(b.variant_name || b.tiktok?.variant_name || '')
      return aName.localeCompare(bName)
    })
  }
})

const displayVariants = computed(() => activeGroup.value?.variants || [])
const summaryItem = computed(() => selectedItem.value || activeGroup.value?.variants?.[0] || null)

const getProductTool = reactive({
  version: '202309',
  shop_id: '',
  shop_cipher: '',
  access_token: '',
  product_id: DEFAULT_API_PRODUCT_ID,
  return_under_review_version: 'false',
  return_draft_version: 'false',
  locale: ''
})

const buildGetProductQuery = () => {
  const params = new URLSearchParams()
  const pushIfValue = (key, value) => {
    const text = String(value ?? '').trim()
    if (text || text === 'false') params.set(key, text)
  }

  pushIfValue('access_token', getProductTool.access_token)
  pushIfValue('app_key', '6i1cagd9f0p83')
  pushIfValue('shop_cipher', getProductTool.shop_cipher)
  pushIfValue('shop_id', getProductTool.shop_id)
  pushIfValue('sign', '4a76ac82d74afd02afd9cc3c41fe131a167592f0d374237ad301dba5bd3a6089')
  pushIfValue('timestamp', '1778278324')
  pushIfValue('version', getProductTool.version || '202309')
  pushIfValue('return_under_review_version', getProductTool.return_under_review_version)
  pushIfValue('return_draft_version', getProductTool.return_draft_version)
  pushIfValue('locale', getProductTool.locale)
  return params.toString()
}

const apiGetProductCurl = computed(() => {
  const productId = String(getProductTool.product_id || selectedItem.value?.tiktok?.product_id || '').trim()
  const url = `https://open-api.tiktokglobalshop.com/product/${getProductTool.version || '202309'}/products/${productId}`
  const query = buildGetProductQuery()
  const querySuffix = query ? `?${query}` : ''
  const accessToken = String(getProductTool.access_token || '').trim()
  return [
    "curl -k -X 'GET' \\",
    `  -H 'x-tts-access-token: ${accessToken}' \\`,
    `  '${url}${querySuffix}'`
  ].join('\n')
})

const apiGetProductResponseLines = computed(() => {
  return String(getProductResponseText.value || '')
    .split('\n')
    .map((line, index) => ({
      no: index + 1,
      text: line
    }))
})

const apiGetProductResponsePayload = computed(() => {
  if (!getProductResponseText.value) return null

  try {
    return JSON.parse(getProductResponseText.value)
  } catch {
    return null
  }
})

const apiGetProductResponseProduct = computed(() => {
  const payload = apiGetProductResponsePayload.value
  if (!payload) return null

  if (payload?.data && typeof payload.data === 'object') return payload.data
  return payload
})

const cloneJson = (value) => {
  if (value === null || value === undefined) return value
  return JSON.parse(JSON.stringify(value))
}

const normalizeNumericString = (value) => {
  const text = String(value ?? '').trim()
  if (!/^\d+$/.test(text)) return null
  return text.replace(/^0+/, '') || '0'
}

const compareNumericStrings = (left, right) => {
  const a = normalizeNumericString(left)
  const b = normalizeNumericString(right)
  if (a === null && b === null) return 0
  if (a === null) return -1
  if (b === null) return 1
  if (a.length !== b.length) return a.length - b.length
  return a.localeCompare(b)
}

const addNumericStrings = (value, step) => {
  const left = normalizeNumericString(value) || '0'
  const right = normalizeNumericString(step) || '0'
  let carry = 0
  let result = ''
  let leftIndex = left.length - 1
  let rightIndex = right.length - 1

  while (leftIndex >= 0 || rightIndex >= 0 || carry > 0) {
    const leftDigit = leftIndex >= 0 ? Number(left[leftIndex]) : 0
    const rightDigit = rightIndex >= 0 ? Number(right[rightIndex]) : 0
    const sum = leftDigit + rightDigit + carry
    result = String(sum % 10) + result
    carry = Math.floor(sum / 10)
    leftIndex -= 1
    rightIndex -= 1
  }

  return result
}

const subtractNumericStrings = (left, right) => {
  let a = normalizeNumericString(left) || '0'
  let b = normalizeNumericString(right) || '0'
  if (compareNumericStrings(a, b) < 0) return null

  let borrow = 0
  let result = ''
  let leftIndex = a.length - 1
  let rightIndex = b.length - 1

  while (leftIndex >= 0) {
    let leftDigit = Number(a[leftIndex]) - borrow
    const rightDigit = rightIndex >= 0 ? Number(b[rightIndex]) : 0
    borrow = 0

    if (leftDigit < rightDigit) {
      leftDigit += 10
      borrow = 1
    }

    result = String(leftDigit - rightDigit) + result
    leftIndex -= 1
    rightIndex -= 1
  }

  return normalizeNumericString(result)
}

const buildNumericFallback = (seed, digits = 19) => {
  const modulus = 1000000000
  let firstHash = 0
  let secondHash = 7
  const text = String(seed || '').trim() || 'tiktok'

  for (const character of text) {
    const code = character.charCodeAt(0)
    firstHash = (firstHash * 131 + code) % modulus
    secondHash = (secondHash * 137 + code) % modulus
  }

  return `${secondHash}${String(firstHash).padStart(9, '0')}`.padStart(digits, '0').slice(-digits)
}

const buildNextNumericValue = (values, seed, preferredStep = null) => {
  const numericValues = values
    .map(normalizeNumericString)
    .filter((value) => value !== null)

  if (!numericValues.length) {
    return buildNumericFallback(seed)
  }

  const positiveDiffs = []
  for (let index = 1; index < numericValues.length; index += 1) {
    const diff = subtractNumericStrings(numericValues[index], numericValues[index - 1])
    if (diff !== null && diff !== '0') positiveDiffs.push(diff)
  }

  let step = normalizeNumericString(preferredStep)
  if (step === null) {
    if (positiveDiffs.length) {
      step = positiveDiffs[0]
      for (let index = 1; index < positiveDiffs.length; index += 1) {
        if (compareNumericStrings(positiveDiffs[index], step) < 0) {
          step = positiveDiffs[index]
        }
      }
    } else {
      step = '1'
    }
  }

  if (step === '0') {
    step = '1'
  }

  const maxValue = numericValues.reduce((max, value) => (compareNumericStrings(value, max) > 0 ? value : max), numericValues[0])
  return addNumericStrings(maxValue, step)
}

const extractTiktokProductBody = (payload) => {
  if (!payload || typeof payload !== 'object') return null

  if (Array.isArray(payload.skus) || Array.isArray(payload.main_images) || String(payload.title || '').trim() !== '') {
    return payload
  }

  if (payload.data && typeof payload.data === 'object') {
    const data = payload.data
    if (Array.isArray(data.skus) || Array.isArray(data.main_images) || String(data.title || '').trim() !== '') {
      return data
    }
  }

  return null
}

const normalizeTiktokUploadedImageUri = (value) => {
  const text = String(value || '').trim()
  if (!text) return ''
  if (/^(https?:|data:|blob:)/i.test(text)) return ''
  if (text.startsWith('/') || text.includes('/cached-images/')) return ''
  if (text.startsWith('cached-images/')) return ''
  return text.includes('/') ? text : ''
}

const firstTiktokUploadedImageUri = (...values) => {
  for (const value of values) {
    const uri = normalizeTiktokUploadedImageUri(value)
    if (uri) return uri
  }

  return ''
}

const findExistingTiktokImageUri = (productBody, existingSkus, preferredValueName = '') => {
  const targetValueName = normalizeText(preferredValueName)
  const matchingSku = existingSkus.find((sku) => {
    return normalizeText(sku?.sales_attributes?.[0]?.value_name) === targetValueName
  })
  const skuWithImage = existingSkus.find((sku) => normalizeTiktokUploadedImageUri(sku?.sales_attributes?.[0]?.sku_img?.uri))
  const mainImages = Array.isArray(productBody.main_images) ? productBody.main_images : []

  return firstTiktokUploadedImageUri(
    matchingSku?.sales_attributes?.[0]?.sku_img?.uri,
    skuWithImage?.sales_attributes?.[0]?.sku_img?.uri,
    ...mainImages.map((image) => typeof image === 'string' ? image : image?.uri)
  )
}

const buildAddVariantRequestPreview = () => {
  const productBody = cloneJson(extractTiktokProductBody(apiGetProductResponsePayload.value)) || {}
  const existingSkus = Array.isArray(productBody.skus)
    ? productBody.skus.map((sku) => cloneJson(sku) || {})
    : []

  const productTitle = String(
    productBody.title ||
    selectedItem.value?.tiktok?.product_name ||
    selectedItem.value?.product_name ||
    DEFAULT_PRODUCT_NAME
  ).trim() || DEFAULT_PRODUCT_NAME

  const sellerSku = String(addVariantTool.seller_sku || selectedItem.value?.seller_sku || '').trim()
  const colorName = String(addVariantTool.color_name || selectedItem.value?.variant_name || 'Variant Baru').trim() || 'Variant Baru'
  const imageUri = String(addVariantTool.image_uri || selectedItem.value?.image_url || '').trim()
  const tiktokImageUri = firstTiktokUploadedImageUri(
    imageUri,
    findExistingTiktokImageUri(productBody, existingSkus, colorName)
  )
  const priceValue = String(addVariantTool.price || '50000').trim() || '50000'
  const quantityValue = Number(addVariantTool.quantity ?? 0)

  const generatedId = buildNextNumericValue(
    existingSkus.map((sku) => sku.id || sku.sku_id),
    `${productTitle}|${sellerSku}|id`,
    '65536'
  )
  const existingAttribute = existingSkus.find((sku) => sku?.sales_attributes?.[0]?.id || sku?.sales_attributes?.[0]?.name)?.sales_attributes?.[0] || {}

  const baseAttribute = {
    id: String(existingAttribute.id || TIKTOK_VARIANT_ATTRIBUTE_ID),
    name: String(existingAttribute.name || TIKTOK_VARIANT_ATTRIBUTE_NAME),
    value_name: colorName
  }

  if (tiktokImageUri) {
    baseAttribute.sku_img = {
      uri: tiktokImageUri
    }
  }

  const baseInventory = {
    quantity: quantityValue,
    warehouse_id: TIKTOK_VARIANT_WAREHOUSE_ID
  }

  const newSku = {
    seller_sku: sellerSku || `SKU-${generatedId.slice(-6)}`,
    sales_attributes: [baseAttribute],
    price: {
      currency: 'IDR',
      sale_price: priceValue,
      tax_exclusive_price: priceValue,
      amount: priceValue
    },
    inventory: [baseInventory]
  }

  const matchingSellerSkuIndex = existingSkus.findIndex((sku) => {
    return normalizeText(sku?.seller_sku) === normalizeText(newSku.seller_sku)
  })
  let placeholderIndex = -1
  for (let index = existingSkus.length - 1; index >= 0; index -= 1) {
    const sku = existingSkus[index]
    const skuId = String(sku?.id || sku?.sku_id || '').trim()
    const valueId = String(sku?.sales_attributes?.[0]?.value_id || '').trim()
    const warehouseId = String(sku?.inventory?.[0]?.warehouse_id || '').trim()

    if (skuId === '' && (valueId === '' || warehouseId === '')) {
      placeholderIndex = index
      break
    }
  }

  if (matchingSellerSkuIndex >= 0) {
    existingSkus[matchingSellerSkuIndex] = {
      ...existingSkus[matchingSellerSkuIndex],
      ...newSku,
      sales_attributes: newSku.sales_attributes,
      inventory: newSku.inventory
    }
  } else if (placeholderIndex >= 0) {
    const replacementSku = {
      ...existingSkus[placeholderIndex],
      ...newSku,
      sales_attributes: newSku.sales_attributes,
      inventory: newSku.inventory
    }
    delete replacementSku.id
    delete replacementSku.sku_id
    existingSkus[placeholderIndex] = replacementSku
  } else {
    existingSkus.push(newSku)
  }

  productBody.save_mode = 'LISTING'
  productBody.category_id = '601307'
  productBody.category_version = 'v2'
  productBody.title = productTitle
  productBody.skus = existingSkus

  if (!Array.isArray(productBody.main_images) || productBody.main_images.length === 0) {
    productBody.main_images = tiktokImageUri ? [{ uri: tiktokImageUri }] : []
  }

  return JSON.stringify(productBody, null, 2)
}

const apiGetProductResponseHint = computed(() => {
  const payload = apiGetProductResponsePayload.value
  if (!payload) return ''

  const code = String(payload.code ?? '')
  const message = String(payload.message ?? '').toLowerCase()

  if (code === '105001' || message.includes('access token is invalid')) {
    return 'Token TikTok aktif di database tidak valid. Jalankan GET TOKEN / REFRESH TOKEN dulu, lalu coba Submit Request lagi.'
  }

  if (code === '0') {
    return 'Response berhasil diambil langsung dari TikTok Shop.'
  }

  return ''
})

const addVariantRequestPreview = computed(() => {
  try {
    return buildAddVariantRequestPreview()
  } catch (error) {
    return JSON.stringify({
      product_id: addVariantTool.product_id || selectedItem.value?.tiktok?.product_id || '',
      shop_cipher: addVariantTool.shop_cipher,
      seller_sku: addVariantTool.seller_sku,
      color_name: addVariantTool.color_name,
      image_uri: addVariantTool.image_uri,
      price: addVariantTool.price,
      quantity: addVariantTool.quantity,
      dry_run: addVariantTool.dry_run,
      preview_error: error?.message || 'Request demo gagal digenerate.'
    }, null, 2)
  }
})

const addVariantResponseLines = computed(() => {
  return String(addVariantResponseText.value || '')
    .split('\n')
    .map((line, index) => ({
      no: index + 1,
      text: line
    }))
})

const copyTextToClipboard = async (value) => {
  const text = String(value ?? '')

  if (navigator.clipboard?.writeText && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(text)
      return
    } catch {
      // Domain lokal kadang menolak Clipboard API; lanjut pakai fallback.
    }
  }

  const textarea = document.createElement('textarea')
  textarea.value = text
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'
  textarea.style.left = '-9999px'
  textarea.style.top = '0'

  document.body.appendChild(textarea)
  textarea.focus()
  textarea.select()
  textarea.setSelectionRange(0, text.length)

  const copied = document.execCommand('copy')
  document.body.removeChild(textarea)

  if (!copied) {
    throw new Error('Browser menolak akses clipboard.')
  }
}

const copyAddVariantRequest = async () => {
  try {
    await copyTextToClipboard(addVariantRequestPreview.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy request gagal.'
  }
}

const copyAddVariantResponse = async () => {
  try {
    if (!String(addVariantResponseText.value || '').trim()) {
      addVariantResponseText.value = addVariantRequestPreview.value
      addVariantResponseStatus.value = 'READY'
    }

    await copyTextToClipboard(addVariantResponseText.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy response gagal.'
  }
}

const loadAddVariantContext = async () => {
  try {
    const response = await omnichannelService.tiktokGetProductContext()
    const data = response.data?.data || {}

    if (data.shop_cipher) addVariantTool.shop_cipher = data.shop_cipher
    if (!String(addVariantTool.product_id || '').trim() && data.product_id) {
      addVariantTool.product_id = data.product_id
    }
  } catch {
    // Tetap biarkan user isi manual bila context belum tersedia.
  }
}

const submitAddVariant = async () => {
  addVariantBusy.value = true
  loadError.value = ''
  addVariantResponseText.value = ''
  addVariantResponseStatus.value = '0'

  try {
    addVariantResponseText.value = buildAddVariantRequestPreview()
    addVariantResponseStatus.value = 'READY'
  } catch (error) {
    addVariantResponseText.value = JSON.stringify({
      message: 'Payload tambah variant gagal digenerate dari response GET Product.',
      error: error?.message || 'Request demo gagal digenerate.'
    }, null, 2)
    addVariantResponseStatus.value = 'ERROR'
    loadError.value = error?.message || 'Payload tambah variant gagal digenerate.'
  } finally {
    addVariantBusy.value = false
  }
}

const copyGetProductCurl = async () => {
  try {
    await copyTextToClipboard(apiGetProductCurl.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy cURL gagal.'
  }
}

const copyGetProductResponse = async () => {
  try {
    await copyTextToClipboard(getProductResponseText.value)
    loadError.value = ''
  } catch (error) {
    loadError.value = error?.message || 'Copy response gagal.'
  }
}

const loadGetProductContext = async () => {
  try {
    const response = await omnichannelService.tiktokGetProductContext()
    const data = response.data?.data || {}

    if (data.shop_id) getProductTool.shop_id = data.shop_id
    if (data.shop_cipher) getProductTool.shop_cipher = data.shop_cipher
    if (data.access_token) getProductTool.access_token = data.access_token
    if (data.version) getProductTool.version = data.version
  } catch {
    // Kalau context belum tersedia, user tetap bisa lanjut pakai nilai yang ada.
  }
}

const extractResponseText = async (response) => {
  const contentType = response.headers.get('content-type') || ''
  if (contentType.includes('application/json')) {
    try {
      const data = await response.json()
      return JSON.stringify(data, null, 2)
    } catch {
      return await response.text()
    }
  }

  return await response.text()
}

const submitGetProductDemo = async () => {
  getProductRequestBusy.value = true
  loadError.value = ''
  getProductResponseText.value = ''
  getProductResponseStatus.value = '0'

  try {
    if (selectedItem.value?.tiktok?.product_id && !String(getProductTool.product_id || '').trim()) {
      getProductTool.product_id = selectedItem.value.tiktok.product_id
    }

    const response = await fetch('/api/tiktok/get-product', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify({
      product_id: getProductTool.product_id || selectedItem.value?.tiktok?.product_id || '',
      version: getProductTool.version,
      shop_id: getProductTool.shop_id,
      shop_cipher: getProductTool.shop_cipher,
      access_token: getProductTool.access_token,
      return_under_review_version: getProductTool.return_under_review_version,
      return_draft_version: getProductTool.return_draft_version,
      locale: getProductTool.locale
      })
    })

    const responseText = await extractResponseText(response)
    getProductResponseText.value = responseText
    try {
      const parsed = responseText ? JSON.parse(responseText) : null
      getProductResponseStatus.value = String(parsed?.code ?? response.status ?? 0)
      const payload = parsed?.data || parsed
      if (payload) {
        const mapped = mapProductPayloadToVariantDefaults(payload)
        if (mapped.product_id) addVariantTool.product_id = mapped.product_id
        if (mapped.seller_sku) addVariantTool.seller_sku = mapped.seller_sku
        if (mapped.color_name) addVariantTool.color_name = mapped.color_name
        if (mapped.image_uri) addVariantTool.image_uri = mapped.image_uri
        if (mapped.price) addVariantTool.price = mapped.price
        addVariantTool.quantity = Number(mapped.quantity ?? 0)
      }
    } catch {
      getProductResponseStatus.value = String(response.status ?? 0)
    }
  } catch (error) {
    getProductResponseText.value = error?.message ? String(error.message) : ''
    getProductResponseStatus.value = '0'
    loadError.value = error.message || 'Request TikTok gagal diproses.'
  } finally {
    getProductRequestBusy.value = false
  }
}

const loadData = async (resetPage = false) => {
  loading.value = true
  loadError.value = ''
  if (resetPage) pagination.page = 1
  const cacheKey = JSON.stringify({
    search: filters.search,
    status: filters.status,
    sort: 'updated_desc',
    page: pagination.page,
    per_page: pagination.per_page
  })
  try {
    if (pageCache.has(cacheKey)) {
      const cached = pageCache.get(cacheKey)
      items.value = cached.items
      Object.assign(pagination, cached.pagination)
      const firstCached = activeGroup.value?.variants?.[0] || null
      if (firstCached) {
        selectItem(firstCached)
      } else {
        selectedItem.value = null
        resetForm()
      }
      return
    }

    const response = await omnichannelService.skuMapping({
      search: filters.search,
      status: filters.status,
      sort: 'updated_desc',
      page: pagination.page,
      per_page: pagination.per_page
    })
    items.value = response.data.items || []
    Object.assign(pagination, response.data.pagination || {})
    pageCache.set(cacheKey, {
      items: items.value,
      pagination: { ...pagination }
    })
    const first = activeGroup.value?.variants?.[0] || null
    if (first) {
      selectItem(first)
    } else {
      selectedItem.value = null
      resetForm()
    }
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Data tambah varian gagal dimuat.'
    items.value = []
    selectedItem.value = null
    resetForm()
  } finally {
    loading.value = false
  }
}

const changePage = async (nextPage) => {
  const targetPage = Math.max(1, Number(nextPage) || 1)
  if (targetPage === pagination.page) return
  pagination.page = targetPage
  await loadData()
}

const selectItem = (item) => {
  selectedItem.value = item
  form.stock_master_id = item.stock_master_id || (typeof item.id === 'number' ? item.id : null)
  form.shopee_item_id = item.shopee?.item_id || ''
  form.shopee_model_id = item.shopee?.model_id || ''
  form.seller_sku = item.seller_sku || item.shopee?.seller_sku || item.tiktok?.seller_sku || ''
  form.tiktok_product_id = item.tiktok?.product_id || ''
  form.tiktok_sku_id = item.tiktok?.sku_id || ''
  form.tiktok_sku_name = item.tiktok?.variant_name || item.tiktok?.sku_name || item.variant_name || ''
  form.warehouse_id = item.tiktok?.warehouse_id || form.warehouse_id || ''
  form.inventory_qty = Number(item.tiktok?.stock_qty ?? item.stock_qty ?? 0)
  form.notes = item.notes || ''
  if (String(item.tiktok?.product_id || '').trim()) {
    getProductTool.product_id = item.tiktok.product_id
  }
  getProductResponseText.value = ''
  getProductResponseStatus.value = '0'
  fillAddVariantToolFromItem(item)
}

const fillFromSelected = () => {
  if (selectedItem.value) selectItem(selectedItem.value)
}

const save = async () => {
  if (!form.stock_master_id) return
  saving.value = true
  loadError.value = ''
  try {
    await omnichannelService.saveSkuMapping({ ...form })
    await loadData()
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Mapping gagal disimpan.'
  } finally {
    saving.value = false
  }
}

const prepareMissingVariant = async (item) => {
  const targetChannel = missingTargetChannel(item)
  if (!targetChannel) return

  preparing.value = true
  loadError.value = ''
  try {
    await omnichannelService.prepareMissingVariant({
      stock_master_id: item.stock_master_id || item.id,
      target_channel: targetChannel
    })
    await loadData()
    const refreshed = activeGroup.value?.variants?.find((candidate) => candidate.stock_master_id === item.stock_master_id) || activeGroup.value?.variants?.[0]
    if (refreshed) selectItem(refreshed)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Draft varian gagal disiapkan.'
  } finally {
    preparing.value = false
  }
}

const runTiktokAction = async (action) => {
  if (!form.stock_master_id && selectedItem.value) {
    form.stock_master_id = selectedItem.value.stock_master_id || selectedItem.value.id
  }

  if (!form.stock_master_id) return
  if ((action === 'update_inventory' || action === 'full_sync') && !String(form.warehouse_id || '').trim()) {
    loadError.value = 'Warehouse ID TikTok wajib diisi untuk update stok.'
    return
  }

  actionBusy.value = true
  loadError.value = ''
  actionLog.value = ''
  try {
    const response = await omnichannelService.tiktokVariantAction({
      stock_master_id: form.stock_master_id,
      action,
      warehouse_id: form.warehouse_id,
      quantity: form.inventory_qty
    })

    actionLog.value = response.data?.message || 'Aksi TikTok berhasil diproses.'
    await loadData()
    const refreshed = activeGroup.value?.variants?.find((candidate) => candidate.stock_master_id === form.stock_master_id) || activeGroup.value?.variants?.[0]
    if (refreshed) selectItem(refreshed)
  } catch (error) {
    loadError.value = error.response?.data?.message || 'Aksi TikTok gagal diproses.'
  } finally {
    actionBusy.value = false
  }
}

onMounted(async () => {
  await Promise.all([
    loadData(true),
    loadGetProductContext(),
    loadAddVariantContext()
  ])
})
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; }
.page-header { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:16px; }
.page-header p { color:#64748b; margin-bottom:4px; }
.subtitle { display:block; margin-top:4px; color:#64748b; }
.header-actions { display:flex; gap:10px; }
.primary,.ghost,.mini { border:0; border-radius:6px; cursor:pointer; }
.primary,.ghost { padding:10px 14px; }
.primary { background:#0f5fc7; color:#fff; }
.ghost { background:#fff; border:1px solid #d9e2ec; color:#334155; }
.primary:disabled,.ghost:disabled { cursor:wait; opacity:.72; }
.notice { color:#334155; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:12px; font-size:13px; }
.notice.error { color:#991b1b; background:#fef2f2; border-color:#fecaca; }
.control-band { display:flex; gap:12px; align-items:end; margin-bottom:12px; padding:12px; background:#fff; border:1px solid #d9e2ec; border-radius:8px; }
.control-band label { flex:1; }
.control-band span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.control-band input,
.control-band select { width:100%; border:1px solid #d7dde8; border-radius:6px; padding:10px; font-size:13px; background:#fff; }
.summary-grid { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:12px; margin-bottom:12px; }
.metric { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.metric span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.metric strong { display:block; font-size:18px; color:#111827; line-height:1.2; }
.metric small { color:#64748b; display:block; margin-top:6px; }
.layout { display:grid; grid-template-columns:minmax(0,1fr) 340px; gap:12px; }
.panel { background:#fff; border:1px solid #d9e2ec; border-radius:8px; padding:14px; }
.group-head { display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:12px; }
.group-title { display:flex; gap:12px; align-items:flex-start; min-width:0; }
.group-title strong { display:block; color:#111827; font-size:18px; line-height:1.25; margin-bottom:4px; }
.group-title small { color:#64748b; display:block; margin-top:3px; }
.table-wrap { max-height:72vh; overflow:auto; border:1px solid #e5e7eb; border-radius:8px; }
.group-empty { margin-bottom:12px; padding:12px; border:1px dashed #cbd5e1; border-radius:8px; color:#64748b; background:#f8fafc; }
.empty-row { text-align:center; color:#64748b; padding:20px !important; }
.table-filters { display:flex; align-items:end; gap:12px; margin: 0 0 12px; padding: 12px; background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; }
.table-filters label { min-width: 260px; }
.table-filters span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.table-filters select { width:100%; border:1px solid #d7dde8; border-radius:6px; padding:10px; font-size:13px; background:#fff; }
.filter-hint { color:#64748b; font-size:12px; }
.api-panel { margin-top:14px; padding:0; border:0; background:transparent; }
.api-panel-head { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; margin-bottom:12px; }
.api-panel-head strong { display:block; color:#111827; font-size:18px; line-height:1.2; }
.api-panel-head small { color:#64748b; display:block; margin-top:4px; }
.api-panel-head a { color:#0f766e; text-decoration:none; font-weight:600; }
.api-testing-tool { display:grid; grid-template-columns:minmax(0,1fr) minmax(360px, 0.95fr); gap:0; border:1px solid #e5e7eb; border-radius:4px; overflow:hidden; background:#fff; }
.api-left, .api-right { min-width:0; padding:18px; }
.api-left { border-right:1px solid #e5e7eb; }
.api-apirow { display:grid; grid-template-columns:72px minmax(0,1fr); gap:12px; padding:12px; border:1px solid #e5e7eb; background:#fff; align-items:center; }
.api-label { color:#6b7280; font-size:12px; line-height:1.2; }
.api-apiname { display:flex; justify-content:space-between; align-items:center; gap:12px; min-width:0; }
.api-apiname strong { display:block; color:#111827; font-size:13px; line-height:1.25; }
.api-apiname small { display:block; color:#6b7280; font-size:12px; margin-top:2px; }
.api-chip { flex:0 0 auto; border-radius:4px; background:#e5e7eb; color:#444; font-size:12px; font-weight:700; padding:5px 9px; }
.api-version-row { display:flex; justify-content:space-between; align-items:flex-end; gap:12px; margin:14px 0 10px; }
.api-inline { display:grid; gap:6px; width:170px; }
.api-inline span { color:#6b7280; font-size:12px; }
.api-inline select, .api-shop-auth input, .api-path-params input, .api-query-params input { width:100%; border:1px solid #d1d5db; border-radius:4px; padding:8px 10px; font-size:13px; background:#fff; }
.api-inline select:disabled, .api-shop-auth input:disabled { background:#f3f4f6; color:#9ca3af; }
.api-doc-link, .api-shop-link { color:#0ea5a1; font-size:12px; font-weight:700; text-decoration:none; }
.api-section-title { font-size:14px; font-weight:600; color:#111827; margin:18px 0 10px; }
.api-shop-auth, .api-path-params, .api-query-params { position:relative; }
.api-shop-auth { padding-top:18px; border-top:1px solid #f1f5f9; }
.api-shop-link { position:absolute; right:0; top:-8px; }
.api-shop-auth label, .api-path-params label, .api-query-params label { display:block; margin-bottom:12px; }
.api-shop-auth span, .api-path-params span, .api-query-params span { display:block; color:#6b7280; font-size:12px; margin-bottom:6px; }
.api-shop-auth em, .api-path-params em, .api-query-params em { font-style:normal; color:#9ca3af; }
.api-bool-row { margin-bottom:12px; }
.api-bool-row > span { display:block; color:#6b7280; font-size:12px; margin-bottom:6px; }
.api-radio-group { display:flex; align-items:center; gap:18px; color:#111827; font-size:13px; }
.api-radio-group label { display:flex; align-items:center; gap:6px; margin:0; }
.api-submit-row { display:flex; justify-content:flex-end; margin-top:14px; }
.api-submit-row .primary { padding:9px 16px; }
.api-right { background:#fff; }
.api-right-block { margin-bottom:16px; }
.api-right-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:10px; }
.api-right-head strong { color:#111827; font-size:13px; }
.api-status-line { display:flex; align-items:center; gap:8px; color:#6b7280; font-size:12px; margin-bottom:8px; }
.status-dot { display:inline-flex; align-items:center; justify-content:center; gap:6px; color:#111827; font-weight:700; }
.status-dot::before { content:''; width:10px; height:10px; border-radius:999px; background:#16a34a; box-shadow:0 0 0 2px rgba(22,163,74,.15); display:inline-block; }
.api-right-block pre { margin:0; padding:12px; border:1px solid #e5e7eb; background:#fff; font-size:12px; line-height:1.55; color:#111827; overflow:auto; white-space:pre-wrap; word-break:break-word; max-height:460px; }
.api-right-block:first-of-type pre { max-height:150px; }
.variant-tool .api-right-block:first-of-type pre { max-height:520px; min-height:520px; }
.api-response-hint { margin:8px 0 10px; color:#b45309; font-size:12px; line-height:1.45; background:#fffbeb; border:1px solid #fcd34d; border-radius:4px; padding:8px 10px; }
.api-response-viewer { display:grid; grid-template-columns:44px minmax(0,1fr); border:1px solid #e5e7eb; background:#fff; max-height:460px; overflow:auto; }
.variant-tool .api-response-viewer { max-height:520px; min-height:520px; }
.api-response-lines { background:#f8fafc; border-right:1px solid #e5e7eb; color:#94a3b8; font-size:12px; line-height:1.55; text-align:right; padding:12px 8px 12px 0; user-select:none; }
.api-response-lines span { display:block; min-height:1.55em; }
.api-response-viewer pre { margin:0; padding:12px; border:0; max-height:none; }
.variant-panel { margin-top:14px; }
.variant-tool { display:grid; grid-template-columns:minmax(0,1fr) minmax(360px, 0.95fr); gap:0; border:1px solid #e5e7eb; border-radius:4px; overflow:hidden; background:#fff; }
.variant-panel .api-right { padding-top:12px; }
.mapping-table { width:100%; border-collapse:collapse; font-size:13px; min-width:1240px; table-layout:fixed; }
.col-product { width:28%; }
.col-channel { width:31%; }
.col-status { width:10%; }
th,td { border-bottom:1px solid #e5e7eb; padding:10px; vertical-align:top; text-align:left; }
thead th { position:sticky; top:0; background:#1f2937; color:#fff; z-index:1; }
tbody:last-child tr:last-child td { border-bottom:0; }
td small { color:#64748b; display:block; margin-top:3px; }
.variant-row { cursor:pointer; }
.variant-row:hover,.variant-row.active { background:#eff6ff; }
.variant-title strong { display:block; margin-bottom:4px; }
.channel-cell { display:grid; grid-template-columns:58px minmax(0,1fr); gap:10px; align-items:start; min-width:0; }
.channel-cell strong { display:block; line-height:1.25; margin-bottom:4px; }
.channel-cell small,.variant-title small { overflow-wrap:anywhere; }
.channel-cell.muted { color:#64748b; }
.channel-title-row { display:flex; align-items:flex-start; justify-content:space-between; gap:8px; min-width:0; }
.channel-title-row strong { min-width:0; overflow-wrap:anywhere; }
.channel-badge { flex:0 0 auto; border-radius:999px; padding:3px 7px; font-size:11px; font-weight:800; line-height:1.2; }
.channel-badge.mapped { color:#047857; background:#d1fae5; }
.channel-badge.suggested { color:#b45309; background:#fef3c7; }
.thumb { width:58px; height:58px; border-radius:6px; object-fit:cover; background:#eef2f7; border:1px solid #e2e8f0; }
.thumb.large { width:82px; height:82px; }
.fallback { display:grid; place-items:center; color:#64748b; font-weight:800; font-size:12px; }
.mini { display:block; margin-top:8px; padding:6px 9px; background:#e2e8f0; color:#334155; font-size:12px; }
.badge { display:inline-block; border-radius:999px; padding:4px 8px; font-size:12px; font-weight:700; }
.badge.ready_to_sync { background:#d1fae5; color:#047857; }
.badge.belum_ada_variant { background:#eef2f7; color:#475569; }
.detail-panel label { display:block; margin-bottom:10px; }
.detail-panel span { display:block; color:#64748b; font-size:12px; margin-bottom:6px; }
.detail-head { display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:12px; }
.detail-head strong { display:block; color:#111827; line-height:1.25; }
.actions { display:flex; gap:10px; margin-top:12px; }
.actions.stacked { margin-top: 10px; }
.action-grid { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px; }
@media (max-width: 1180px) { .layout { grid-template-columns:1fr; } .detail-panel { order:-1; } .api-testing-tool, .variant-tool { grid-template-columns:1fr; } .api-left { border-right:0; border-bottom:1px solid #e5e7eb; } }
@media (max-width: 820px) { .page-shell { margin-left:0; padding:16px; } .summary-grid,.control-band { grid-template-columns:1fr; flex-direction:column; align-items:stretch; } .page-header { align-items:flex-start; flex-direction:column; } .action-grid { grid-template-columns:1fr; } .api-version-row { flex-direction:column; align-items:stretch; } .api-shop-link { position:static; display:block; margin-bottom:10px; } .api-left, .api-right { padding:14px; } }
</style>

