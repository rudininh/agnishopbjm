<template>
  <section class="page-shell">
    <header class="page-header">
      <div>
        <p>Marketplace</p>
        <h1>Tambah Semua Varian TikTok</h1>
        <small>Tambahkan varian Shopee yang belum ada pada produk TikTok terkait.</small>
      </div>
      <button class="ghost" type="button" :disabled="loading || submitting" @click="loadPreview">Muat Ulang</button>
    </header>

    <p v-if="message" :class="['notice', messageTone]">{{ message }}</p>

    <section class="controls" aria-label="Pengaturan proses">
      <fieldset>
        <legend>Cakupan</legend>
        <label><input v-model="execution.scope" type="radio" value="selected" /> Produk dipilih ({{ selectedProductIds.length }})</label>
        <label><input v-model="execution.scope" type="radio" value="all" /> Semua produk ({{ candidates.length }})</label>
      </fieldset>
      <fieldset>
        <legend>Harga TikTok</legend>
        <label><input v-model="execution.priceMode" type="radio" value="majority" /> Harga mayoritas per produk</label>
        <label><input v-model="execution.priceMode" type="radio" value="manual" /> Harga manual</label>
        <input
          v-if="execution.priceMode === 'manual'"
          v-model.number="execution.manualPrice"
          type="number"
          min="1"
          step="1"
          placeholder="Harga TikTok"
          aria-label="Harga manual TikTok"
        />
      </fieldset>
      <div class="run-panel">
        <strong>{{ targetVariantCount }} varian target</strong>
        <small>{{ execution.priceMode === 'majority' ? 'Harga paling sering pada varian TikTok dipakai per produk.' : 'Satu harga manual dipakai untuk seluruh varian baru.' }}</small>
        <button class="primary" type="button" :disabled="!canSubmit" @click="confirmationOpen = true">
          {{ submitting ? 'Memproses...' : 'Tambahkan Varian ke TikTok' }}
        </button>
      </div>
    </section>

    <section class="table-wrap">
      <div class="table-head">
        <div>
          <strong>Kandidat Varian Shopee</strong>
          <small>{{ loading ? 'Memuat preview...' : `${candidates.length} produk memenuhi syarat` }}</small>
        </div>
        <label class="select-all"><input :checked="allSelected" type="checkbox" :disabled="loading || !candidates.length" @change="toggleAll" /> Pilih semua</label>
      </div>
      <table>
        <thead>
          <tr>
            <th><span class="sr-only">Pilih</span></th>
            <th>Produk TikTok</th>
            <th>Varian Shopee yang akan ditambah</th>
            <th>Harga mayoritas TikTok</th>
            <th>Status preview</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading"><td colspan="5" class="empty">Sedang memuat kandidat...</td></tr>
          <tr v-else-if="!candidates.length"><td colspan="5" class="empty">{{ mappingOnlyCandidates.length ? 'Tidak ada SKU Shopee baru yang aman ditambahkan. SKU yang sudah ada di TikTok ditampilkan pada tabel rekonsiliasi di bawah.' : 'Tidak ada varian Shopee yang belum ada di TikTok.' }}</td></tr>
          <tr v-for="group in candidates" :key="group.tiktok_product_id">
            <td><input v-model="selectedProductIds" type="checkbox" :value="group.tiktok_product_id" :disabled="submitting" /></td>
            <td>
              <strong>{{ group.product_name || '-' }}</strong>
              <small>ID TikTok: {{ group.tiktok_product_id }}</small>
              <small>ID Shopee: {{ group.shopee_item_id || '-' }}</small>
            </td>
            <td>
              <ul class="variant-list">
                <li v-for="variant in group.variants" :key="variant.shopee_model_id">
                  <img v-if="variant.image_url" :src="variant.image_url" :alt="variant.variant_name || variant.seller_sku" />
                  <span v-else class="image-fallback">-</span>
                  <span><strong>{{ variant.variant_name || 'Varian Shopee' }}</strong><small>{{ variant.seller_sku }}</small></span>
                </li>
              </ul>
            </td>
            <td>
              <strong v-if="group.majority_price?.price">{{ formatCurrency(group.majority_price.price) }}</strong>
              <span v-else class="warning">{{ group.majority_price?.reason || 'Tidak tersedia' }}</span>
            </td>
            <td><span :class="['badge', group.majority_price?.price ? 'ready' : 'warning']">{{ group.majority_price?.price ? 'Siap diproses' : 'Perlu harga manual' }}</span></td>
          </tr>
        </tbody>
      </table>
    </section>

    <section v-if="mappingOnlyCandidates.length" class="table-wrap reconciliation">
      <div class="table-head">
        <div>
          <strong>SKU TikTok Sudah Ada, Mapping Belum Tersambung</strong>
          <small>{{ `${mappingOnlyCandidates.length} produk | ${mappingOnlyVariantCount} varian tidak dapat ditambahkan ulang` }}</small>
        </div>
        <button
          class="danger"
          type="button"
          :disabled="loading || submitting || cleanupLoading || cleanupSubmitting"
          @click="openSkuCleanupPreview"
        >
          {{ cleanupLoading ? 'Menyiapkan preview...' : 'Normalisasi SKU & Hapus Varian TikTok Lama' }}
        </button>
      </div>
      <table>
        <thead>
          <tr>
            <th>Produk TikTok</th>
            <th>Varian Shopee</th>
            <th>SKU TikTok Terdeteksi</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="group in mappingOnlyCandidates" :key="`mapping-${group.tiktok_product_id}`">
            <tr v-for="variant in group.mapping_only_variants" :key="`${group.tiktok_product_id}-${variant.shopee_model_id}`">
              <td>
                <strong>{{ group.product_name || '-' }}</strong>
                <small>ID TikTok: {{ group.tiktok_product_id }}</small>
              </td>
              <td>
                <strong>{{ variant.variant_name || 'Varian Shopee' }}</strong>
                <small>{{ variant.seller_sku }}</small>
              </td>
              <td>
                <strong>{{ variant.tiktok_sku_id || '-' }}</strong>
                <small>{{ variant.tiktok_variant_name || 'Nama varian TikTok tidak tersedia' }}</small>
              </td>
              <td>
                <span class="badge mapping">Tidak ditambahkan</span>
                <small>{{ variant.reason }}</small>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </section>
    <section v-if="cleanupHasResults" class="table-wrap results cleanup-results">
      <div class="table-head">
        <div>
          <strong>Hasil Normalisasi SKU TikTok</strong>
          <small>{{ cleanupResultSummary }}</small>
        </div>
        <span :class="['badge', cleanupPreview.tone]">{{ cleanupStatusLabel(cleanupPreview.status) }}</span>
      </div>
      <table>
        <thead><tr><th>Produk TikTok</th><th>Shopee item / model</th><th>Varian Shopee</th><th>SKU lama → target</th><th>SKU TikTok</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
          <tr v-for="item in cleanupPreview.items" :key="`cleanup-result-${item.item_key}`">
            <td>{{ item.tiktok_product_id || '-' }}</td>
            <td>{{ item.shopee_item_id || '-' }} / {{ item.shopee_model_id || '-' }}</td>
            <td>{{ item.shopee_variant_name || '-' }}</td>
            <td>{{ item.old_sku || '-' }} → {{ item.target_sku || '-' }}</td>
            <td>{{ item.tiktok_sku_id || '-' }}<small>{{ item.tiktok_variant_name || '-' }}</small></td>
            <td><span :class="['badge', cleanupTone(item.status)]">{{ cleanupStatusLabel(item.status) }}</span></td>
            <td>{{ item.block_reason || item.result?.message || '-' }}</td>
          </tr>
        </tbody>
      </table>
    </section>
    <section v-if="results.length" class="table-wrap results">
      <div class="table-head"><div><strong>Hasil Proses</strong><small>{{ resultSummary }}</small></div></div>
      <table>
        <thead><tr><th>Produk</th><th>SKU Shopee</th><th>Harga</th><th>Status</th><th>Keterangan</th></tr></thead>
        <tbody>
          <template v-for="item in results" :key="item.product_id">
            <tr v-for="variant in item.variants" :key="`${item.product_id}-${variant.seller_sku}-${variant.status}`">
              <td>{{ item.product_name || item.product_id }}</td>
              <td>{{ variant.seller_sku || '-' }}</td>
              <td>{{ variant.price ? formatCurrency(variant.price) : '-' }}</td>
              <td><span :class="['badge', variant.status]">{{ statusLabel(variant.status) }}</span></td>
              <td>{{ variant.reason || item.message || '-' }}</td>
            </tr>
          </template>
        </tbody>
      </table>
    </section>
    <div v-if="confirmationOpen" class="modal-backdrop" @click.self="!submitting && (confirmationOpen = false)">
      <section class="modal" role="dialog" aria-modal="true" aria-labelledby="confirmation-title">
        <h2 id="confirmation-title">Tambahkan varian ke TikTok?</h2>
        <p>{{ targetProductCount }} produk dan {{ targetVariantCount }} varian akan diproses.</p>
        <p>{{ execution.priceMode === 'majority' ? 'Setiap produk memakai harga TikTok yang paling sering muncul pada varian produk tersebut.' : `Setiap varian baru memakai harga manual ${formatCurrency(execution.manualPrice)}.` }}</p>
        <p>SKU TikTok, harga, gambar, dan varian yang sudah ada tidak akan diubah.</p>
        <div class="modal-actions">
          <button class="ghost" type="button" :disabled="submitting" @click="confirmationOpen = false">Batal</button>
          <button class="primary" type="button" :disabled="submitting" @click="submitBulk">{{ submitting ? 'Memproses...' : 'Konfirmasi Tambah Varian' }}</button>
        </div>
      </section>
    </div>
    <div v-if="cleanupModalOpen" class="modal-backdrop" @click.self="!cleanupSubmitting && closeSkuCleanupModal()">
      <section ref="cleanupDialog" class="modal cleanup-modal" role="dialog" aria-modal="true" aria-labelledby="cleanup-confirmation-title" tabindex="-1" @keydown.esc="!cleanupSubmitting && closeSkuCleanupModal()" @keydown.tab="trapSkuCleanupFocus">
        <h2 id="cleanup-confirmation-title">Normalisasi SKU &amp; hapus varian TikTok lama?</h2>
        <p class="cleanup-summary">{{ formatSkuCleanupSummary(cleanupPreview?.summary) }}</p>
        <div class="cleanup-counts" aria-label="Ringkasan baris preview">
          <span class="badge ready">Siap {{ cleanupPreviewBuckets.ready.length }}</span>
          <span class="badge unchanged">Tidak berubah {{ cleanupPreviewBuckets.unchanged.length }}</span>
          <span class="badge blocked">Terblokir {{ cleanupPreviewBuckets.blocked.length }}</span>
        </div>
        <ul class="cleanup-warnings">
          <li>Hanya <code>model_sku</code> Shopee yang diubah.</li>
          <li>Hanya varian TikTok yang tercantum sebagai <strong>Siap</strong> yang dihapus.</li>
          <li>Varian TikTok lain dipertahankan.</li>
          <li>Varian tidak dibuat ulang otomatis.</li>
          <li>Submit dapat ditolak bila katalog berubah setelah preview.</li>
        </ul>
        <p v-if="cleanupPreview.message" :class="['notice', cleanupPreview.tone]">{{ cleanupPreview.message }}</p>
        <div class="cleanup-preview-table" tabindex="0" aria-label="Rincian preview normalisasi SKU">
          <table>
            <thead><tr><th>Produk TikTok</th><th>Shopee item / model</th><th>Varian saat ini</th><th>SKU lama</th><th>SKU target</th><th>SKU TikTok</th><th>Status / alasan</th></tr></thead>
            <tbody>
              <tr v-for="item in cleanupPreview.items" :key="item.item_key">
                <td>{{ item.tiktok_product_id || '-' }}</td>
                <td>{{ item.shopee_item_id || '-' }} / {{ item.shopee_model_id || '-' }}</td>
                <td>{{ item.shopee_variant_name || '-' }}</td>
                <td>{{ item.old_sku || '-' }}</td>
                <td>{{ item.target_sku || '-' }}</td>
                <td>{{ item.tiktok_sku_id || '-' }}<small>{{ item.tiktok_variant_name || '-' }}</small></td>
                <td><span :class="['badge', cleanupTone(item.status)]">{{ cleanupStatusLabel(item.status) }}</span><small>{{ item.block_reason || item.result?.message || '-' }}</small></td>
              </tr>
              <tr v-if="!cleanupPreview.items?.length"><td colspan="7" class="empty">Tidak ada varian untuk dinormalisasi pada preview ini.</td></tr>
            </tbody>
          </table>
        </div>
        <div class="modal-actions">
          <button class="ghost" type="button" :disabled="cleanupSubmitting" @click="closeSkuCleanupModal()">Tutup</button>
          <button v-if="cleanupPreview.canRetry" class="danger" type="button" :disabled="!canSubmitCleanup" @click="submitSkuCleanup">
            {{ cleanupSubmitting ? 'Memproses...' : 'Coba Lagi yang Belum Selesai' }}
          </button>
          <button v-else class="danger" type="button" :disabled="!canSubmitCleanup" @click="submitSkuCleanup">
            {{ cleanupSubmitting ? 'Memproses...' : 'Konfirmasi Normalisasi & Hapus' }}
          </button>
        </div>
      </section>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { omnichannelService } from '@/services'
import { buildBulkSubmitFeedback, mergeBulkPreviewState } from './bulkTiktokSubmitState'
import {
  canSubmitSkuCleanup,
  formatSkuCleanupResultSummary,
  formatSkuCleanupSummary,
  mergeSkuCleanupResult,
  partitionSkuCleanupPreview,
  skuCleanupStatusLabel,
  skuCleanupTone
} from './shopeeSkuTiktokCleanupState'

const candidates = ref([])
const mappingOnlyCandidates = ref([])
const results = ref([])
const selectedProductIds = ref([])
const loading = ref(false)
const submitting = ref(false)
const confirmationOpen = ref(false)
const cleanupLoading = ref(false)
const cleanupSubmitting = ref(false)
const cleanupModalOpen = ref(false)
const cleanupPreview = ref(null)
const cleanupDialog = ref(null)
const cleanupOpener = ref(null)
const message = ref('')
const messageTone = ref('info')
const execution = reactive({ scope: 'selected', priceMode: 'majority', manualPrice: null })

const selectedGroups = computed(() => candidates.value.filter((group) => selectedProductIds.value.includes(group.tiktok_product_id)))
const targetGroups = computed(() => execution.scope === 'all' ? candidates.value : selectedGroups.value)
const targetProductCount = computed(() => targetGroups.value.length)
const targetVariantCount = computed(() => targetGroups.value.reduce((count, group) => count + (group.variants || []).length, 0))
const mappingOnlyVariantCount = computed(() => mappingOnlyCandidates.value.reduce((count, group) => count + (group.mapping_only_variants || []).length, 0))
const allSelected = computed(() => candidates.value.length > 0 && selectedProductIds.value.length === candidates.value.length)
const canSubmit = computed(() => !loading.value && !submitting.value && targetVariantCount.value > 0
  && (execution.priceMode === 'majority' || Number(execution.manualPrice) > 0))
const cleanupPreviewBuckets = computed(() => partitionSkuCleanupPreview(cleanupPreview.value?.previewItems || cleanupPreview.value?.items))
const canSubmitCleanup = computed(() => canSubmitSkuCleanup({
  loading: loading.value,
  submitting: submitting.value,
  cleanupLoading: cleanupLoading.value,
  cleanupSubmitting: cleanupSubmitting.value,
  preview: cleanupPreview.value
}))
const cleanupResultSummary = computed(() => formatSkuCleanupResultSummary(cleanupPreview.value?.resultSummary))
const cleanupHasResults = computed(() => Boolean(
  cleanupPreview.value
  && ['completed', 'partial', 'failed', 'stale_revision'].includes(cleanupPreview.value.status)
  && cleanupPreview.value.items?.length
))
const resultSummary = computed(() => {
  const totals = results.value.reduce((summary, item) => ({
    updated: summary.updated + Number(item.updated || 0),
    unverified: summary.unverified + Number(item.unverified || 0),
    skipped: summary.skipped + Number(item.skipped || 0),
    failed: summary.failed + Number(item.failed || 0)
  }), { updated: 0, unverified: 0, skipped: 0, failed: 0 })
  return `Berhasil ${totals.updated} | Belum terverifikasi ${totals.unverified} | Dilewati ${totals.skipped} | Gagal ${totals.failed}`
})

const loadPreview = async ({ preserveFeedback = false } = {}) => {
  loading.value = true
  if (!preserveFeedback) message.value = ''
  try {
    const { data } = await omnichannelService.bulkTiktokMissingVariantsPreview()
    const next = mergeBulkPreviewState({
      message: message.value,
      messageTone: messageTone.value,
      selectedProductIds: selectedProductIds.value
    }, data, { preserveFeedback })
    candidates.value = next.candidates
    mappingOnlyCandidates.value = next.mappingOnlyCandidates
    selectedProductIds.value = next.selectedProductIds
    message.value = next.message
    messageTone.value = next.messageTone
  } catch (error) {
    message.value = error.response?.data?.message || 'Preview varian TikTok gagal dimuat.'
    messageTone.value = 'error'
  } finally {
    loading.value = false
  }
}

const toggleAll = (event) => {
  selectedProductIds.value = event.target.checked ? candidates.value.map((group) => group.tiktok_product_id) : []
}

const cleanupDialogControls = () => Array.from(cleanupDialog.value?.querySelectorAll('button:not(:disabled), [href], input:not(:disabled), select:not(:disabled), textarea:not(:disabled), [tabindex]:not([tabindex="-1"])') || [])
  .filter((control) => !control.hasAttribute('disabled') && control.getAttribute('aria-hidden') !== 'true')

const restoreCleanupOpenerFocus = async () => {
  await nextTick()
  cleanupOpener.value?.focus?.()
  cleanupOpener.value = null
}

const focusSkuCleanupDialog = async () => {
  await nextTick()
  const [firstControl] = cleanupDialogControls()
  ;(firstControl || cleanupDialog.value)?.focus?.()
}

const closeSkuCleanupModal = async () => {
  if (cleanupSubmitting.value) return
  cleanupModalOpen.value = false
  await restoreCleanupOpenerFocus()
}

const trapSkuCleanupFocus = (event) => {
  const controls = cleanupDialogControls()
  if (controls.length === 0) {
    event.preventDefault()
    cleanupDialog.value?.focus?.()
    return
  }

  const first = controls[0]
  const last = controls[controls.length - 1]
  if (event.shiftKey && (document.activeElement === first || !cleanupDialog.value?.contains(document.activeElement))) {
    event.preventDefault()
    last.focus()
  } else if (!event.shiftKey && (document.activeElement === last || !cleanupDialog.value?.contains(document.activeElement))) {
    event.preventDefault()
    first.focus()
  }
}

const openSkuCleanupPreview = async (event) => {
  if (loading.value || submitting.value || cleanupLoading.value || cleanupSubmitting.value) return

  cleanupOpener.value = event?.currentTarget || null
  cleanupLoading.value = true
  try {
    const { data } = await omnichannelService.previewShopeeSkuTiktokCleanup()
    const items = Array.isArray(data.items) ? data.items : []
    cleanupPreview.value = {
      ...data,
      items,
      previewItems: items,
      summary: data.summary || {},
      message: '',
      tone: 'info',
      canRetry: false
    }
    cleanupModalOpen.value = true
    await focusSkuCleanupDialog()
  } catch (error) {
    message.value = error.response?.data?.message || 'Preview normalisasi SKU gagal dimuat.'
    messageTone.value = 'error'
  } finally {
    cleanupLoading.value = false
  }
}

const submitSkuCleanup = async () => {
  if (!canSubmitCleanup.value) return

  const { run_id: runId, revision } = cleanupPreview.value
  cleanupSubmitting.value = true
  try {
    const { data } = await omnichannelService.submitShopeeSkuTiktokCleanup(runId, revision)
    const next = mergeSkuCleanupResult(cleanupPreview.value, data)
    cleanupPreview.value = next
    message.value = next.message
    messageTone.value = next.tone

    if (next.shouldCloseModal) {
      cleanupModalOpen.value = false
      await loadPreview({ preserveFeedback: true })
      await restoreCleanupOpenerFocus()
    }
  } catch (error) {
    const response = error.response?.data
    const stale = error.response?.status === 409 || response?.status === 'stale_revision'
    if (stale) {
      const next = mergeSkuCleanupResult(cleanupPreview.value, { status: 'stale_revision', ...response })
      cleanupPreview.value = next
      message.value = next.message
      messageTone.value = next.tone
      return
    }

    if (response?.status) {
      const next = mergeSkuCleanupResult(cleanupPreview.value, response)
      cleanupPreview.value = next
      message.value = next.message
      messageTone.value = next.tone
      return
    }

    message.value = response?.message || 'Normalisasi SKU TikTok gagal diproses.'
    messageTone.value = 'error'
  } finally {
    cleanupSubmitting.value = false
  }
}

const submitBulk = async () => {
  if (!canSubmit.value) return
  submitting.value = true
  message.value = ''
  try {
    const { data } = await omnichannelService.bulkSubmitTiktokMissingVariants({
      scope: execution.scope,
      product_ids: execution.scope === 'selected' ? selectedProductIds.value : [],
      price_mode: execution.priceMode,
      manual_price: execution.priceMode === 'manual' ? Number(execution.manualPrice) : undefined
    })
    results.value = data.items || []
    message.value = buildBulkSubmitFeedback(data)
    messageTone.value = data.status === 'success' ? 'success' : data.status === 'partial' || Number(data.unverified || 0) > 0 ? 'warning' : 'error'
    confirmationOpen.value = false
    await loadPreview({ preserveFeedback: true })
  } catch (error) {
    message.value = error.response?.data?.message || 'Proses tambah varian TikTok gagal.'
    messageTone.value = 'error'
  } finally {
    submitting.value = false
  }
}

watch(() => execution.scope, () => { confirmationOpen.value = false })
const formatCurrency = (value) => new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(Number(value || 0))
const statusLabel = (status) => ({ updated: 'Berhasil', submitted_unverified: 'Belum terverifikasi', skipped: 'Dilewati', failed: 'Gagal', partial: 'Sebagian' }[status] || status || '-')

onMounted(loadPreview)
</script>

<style scoped>
.page-shell { margin-left: 240px; padding: 24px; color: #1e293b; }
.page-header { display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:16px; }
.page-header p { margin:0 0 4px; color:#64748b; font-size:13px; }
h1 { margin:0; font-size:26px; line-height:1.2; } small { display:block; color:#64748b; font-size:12px; line-height:1.45; }
.controls { display:grid; grid-template-columns:minmax(240px,1fr) minmax(260px,1.1fr) minmax(240px,.8fr); gap:12px; margin-bottom:16px; }
fieldset,.run-panel,.table-wrap { min-width:0; border:1px solid #d9e2ec; border-radius:6px; background:#fff; }
fieldset { display:grid; gap:8px; padding:12px; } legend { padding:0 4px; color:#475569; font-size:12px; font-weight:800; } label { display:flex; gap:8px; align-items:center; font-size:13px; } input[type='number'] { width:100%; min-height:36px; border:1px solid #cbd5e1; border-radius:4px; padding:0 9px; }
.run-panel { display:grid; align-content:center; gap:7px; padding:12px; } .run-panel strong { font-size:16px; }
.primary,.ghost,.danger { border-radius:6px; padding:9px 12px; border:1px solid transparent; cursor:pointer; font-weight:700; } .primary { background:#0f5fc7; color:#fff; } .ghost { background:#fff; border-color:#cbd5e1; color:#334155; } .danger { background:#b42318; color:#fff; border-color:#991b1b; } button:disabled { cursor:not-allowed; opacity:.56; }
.notice { margin:0 0 14px; padding:10px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:13px; } .notice.success { color:#166534; background:#f0fdf4; border-color:#86efac; } .notice.error { color:#991b1b; background:#fef2f2; border-color:#fecaca; } .notice.warning { color:#92400e; background:#fffbeb; border-color:#fcd34d; }
.table-wrap { overflow:auto; margin-bottom:16px; } .table-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:12px; border-bottom:1px solid #d9e2ec; } .table-head strong { display:block; } .select-all { white-space:nowrap; }
table { width:100%; min-width:860px; border-collapse:collapse; } th,td { padding:10px; border-bottom:1px solid #e5eaf0; text-align:left; vertical-align:top; font-size:13px; } th { color:#475569; background:#f8fafc; font-size:11px; text-transform:uppercase; } td:first-child,th:first-child { width:42px; text-align:center; } .empty { padding:30px; color:#64748b; text-align:center; }
.variant-list { display:grid; gap:7px; margin:0; padding:0; list-style:none; min-width:260px; } .variant-list li { display:grid; grid-template-columns:36px minmax(0,1fr); gap:8px; align-items:center; } .variant-list img,.image-fallback { width:36px; height:36px; object-fit:cover; border-radius:4px; background:#e2e8f0; } .image-fallback { display:grid; place-items:center; color:#64748b; } .variant-list strong { display:block; overflow-wrap:anywhere; }
.badge { display:inline-flex; width:max-content; border-radius:4px; padding:3px 6px; font-size:11px; font-weight:800; } .ready,.updated,.success { color:#166534; background:#dcfce7; } .warning,.skipped,.submitted_unverified,.partial { color:#92400e; background:#fef3c7; } .unchanged,.neutral { color:#475569; background:#e2e8f0; } .blocked,.failed,.stale_revision,.error { color:#991b1b; background:#fee2e2; } .mapping { color:#1d4ed8; background:#dbeafe; }
.modal-backdrop { position:fixed; inset:0; z-index:50; display:grid; place-items:center; padding:18px; background:rgba(15,23,42,.45); } .modal { width:min(520px,100%); max-height:calc(100vh - 36px); overflow:auto; border-radius:6px; background:#fff; padding:20px; box-shadow:0 20px 45px rgba(15,23,42,.25); } .modal h2 { margin:0 0 12px; font-size:19px; } .modal p { margin:8px 0; color:#475569; line-height:1.5; } .modal-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:18px; }
.cleanup-modal { width:min(1180px,100%); } .cleanup-summary { font-weight:800; } .cleanup-counts { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0; } .cleanup-warnings { display:grid; gap:6px; margin:12px 0; padding:12px 12px 12px 30px; color:#7c2d12; background:#fff7ed; border:1px solid #fdba74; border-radius:5px; font-size:13px; line-height:1.45; } .cleanup-warnings code { font-weight:800; } .cleanup-preview-table { overflow:auto; margin-top:12px; border:1px solid #d9e2ec; border-radius:5px; } .cleanup-preview-table:focus { outline:3px solid #93c5fd; outline-offset:2px; } .cleanup-preview-table table { min-width:1080px; } .cleanup-results .table-head { align-items:flex-start; }
.sr-only { position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0,0,0,0); }
@media (max-width: 980px) { .page-shell { margin-left:0; padding:16px; } .controls { grid-template-columns:1fr; } .page-header { align-items:flex-start; flex-direction:column; } }
</style>
