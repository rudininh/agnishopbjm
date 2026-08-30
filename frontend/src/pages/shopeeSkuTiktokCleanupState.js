const PREVIEW_REVISION = /^[a-f0-9]{64}$/i

const count = (value) => {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

const hasRunIdentity = (state = {}) => (
  typeof state.run_id === 'string'
  && state.run_id.trim() !== ''
  && typeof state.revision === 'string'
  && PREVIEW_REVISION.test(state.revision)
)

export const formatSkuCleanupSummary = (summary = {}) => (
  `Siap ${count(summary.eligible)} | Tidak berubah ${count(summary.unchanged)} | Terblokir ${count(summary.blocked)}`
)

export const formatSkuCleanupResultSummary = (summary = {}) => (
  `Berhasil ${count(summary.updated)} | Sebagian ${count(summary.partial) + count(summary.submitted_unverified)} | Terblokir ${count(summary.blocked)} | Gagal ${count(summary.failed)} | Kedaluwarsa ${count(summary.stale_revision)}`
)

export const partitionSkuCleanupPreview = (items = []) => {
  const buckets = { ready: [], unchanged: [], blocked: [] }

  for (const item of Array.isArray(items) ? items : []) {
    if (item?.status === 'ready') buckets.ready.push(item)
    else if (item?.status === 'unchanged') buckets.unchanged.push(item)
    else buckets.blocked.push(item)
  }

  return buckets
}

export const skuCleanupStatusLabel = (status) => ({
  ready: 'Siap',
  ready_for_review: 'Siap ditinjau',
  unchanged: 'Tidak berubah',
  completed: 'Selesai',
  updated: 'Berhasil',
  partial: 'Sebagian',
  submitted_unverified: 'Belum terverifikasi',
  blocked: 'Terblokir',
  stale_revision: 'Preview kedaluwarsa',
  failed: 'Gagal'
}[status] || 'Gagal')

export const summarizeSkuCleanupItems = (items = []) => (
  (Array.isArray(items) ? items : []).reduce((summary, item) => {
    const status = item?.status
    if (Object.hasOwn(summary, status)) summary[status] += 1
    return summary
  }, { updated: 0, partial: 0, submitted_unverified: 0, blocked: 0, stale_revision: 0, failed: 0 })
)

export const canSubmitSkuCleanup = ({
  loading = false,
  submitting = false,
  cleanupLoading = false,
  cleanupSubmitting = false,
  preview = null
} = {}) => {
  if (loading || submitting || cleanupLoading || cleanupSubmitting) return false

  return Boolean(
    preview
    && typeof preview.run_id === 'string'
    && preview.run_id.trim() !== ''
    && typeof preview.revision === 'string'
    && PREVIEW_REVISION.test(preview.revision)
    && count(preview.summary?.eligible) > 0
  )
}

export const skuCleanupTone = (status) => {
  switch (status) {
    case 'completed':
    case 'updated':
      return 'success'
    case 'partial':
    case 'submitted_unverified':
    case 'busy':
    case 'claimed':
      return 'warning'
    case 'ready':
      return 'info'
    case 'unchanged':
      return 'neutral'
    case 'failed':
    case 'stale_revision':
    case 'not_found':
    case 'error':
    default:
      return 'error'
  }
}

const resultMessage = (status, summary) => {
  if (status === 'stale_revision') {
    return 'Data katalog berubah; buat preview baru sebelum mencoba lagi.'
  }
  if (status === 'busy') {
    return 'Normalisasi SKU sedang diproses. Coba lagi sebentar lagi.'
  }
  if (status === 'claimed') {
    return 'Normalisasi SKU sedang diproses. Coba lagi setelah proses berjalan selesai.'
  }
  if (status === 'not_found') {
    return 'Preview normalisasi SKU tidak ditemukan. Buat preview baru.'
  }
  if (status === 'failed') {
    return `Normalisasi SKU gagal. ${formatSkuCleanupResultSummary(summary)}`
  }
  if (status === 'partial') {
    return `Normalisasi SKU selesai sebagian. ${formatSkuCleanupResultSummary(summary)}`
  }
  if (status === 'completed') {
    return `Normalisasi SKU selesai. ${formatSkuCleanupResultSummary(summary)}`
  }

  return 'Terjadi kesalahan saat normalisasi SKU. Coba lagi.'
}

export const mergeSkuCleanupResult = (current = {}, result = {}) => {
  const status = typeof result.status === 'string' ? result.status : 'error'
  const summary = result.summary || current.summary || {}
  const isStale = status === 'stale_revision'
  const resultItems = Array.isArray(result.items) ? result.items : (current.items || [])
  const items = isStale
    ? resultItems.map((item) => ({
      ...item,
      status: 'stale_revision',
      block_reason: result.message || 'Data katalog berubah setelah preview.'
    }))
    : resultItems
  const resultSummary = (isStale || Array.isArray(result.items))
    ? summarizeSkuCleanupItems(items)
    : (current.resultSummary || summary)
  const next = {
    ...current,
    status,
    summary,
    items,
    resultSummary,
    message: result.message || resultMessage(status, resultSummary),
    tone: skuCleanupTone(status),
    run_id: isStale ? null : (result.run_id ?? current.run_id ?? null),
    revision: isStale ? null : (result.revision ?? current.revision ?? null),
    shouldCloseModal: status === 'completed',
    shouldRefreshCandidates: status === 'completed',
    canRetry: false
  }

  next.canRetry = status === 'partial' && hasRunIdentity(next)

  return next
}

export const buildSkuCleanupFeedback = (result = {}) => resultMessage(result.status, result.summary || {})
