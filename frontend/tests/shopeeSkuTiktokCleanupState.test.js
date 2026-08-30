import test from 'node:test'
import assert from 'node:assert/strict'
import {
  canSubmitSkuCleanup,
  formatSkuCleanupSummary,
  mergeSkuCleanupResult,
  partitionSkuCleanupPreview,
  skuCleanupStatusLabel
} from '../src/pages/shopeeSkuTiktokCleanupState.js'

test('allows submit only for a reviewed run with eligible rows', () => {
  assert.equal(canSubmitSkuCleanup({
    loading: false,
    submitting: false,
    preview: { run_id: 'run-1', revision: 'a'.repeat(64), summary: { eligible: 2 } }
  }), true)
})

test('does not allow submit while either bulk-add or cleanup action is busy', () => {
  const preview = { run_id: 'run-1', revision: 'a'.repeat(64), summary: { eligible: 2 } }

  assert.equal(canSubmitSkuCleanup({ loading: true, submitting: false, preview }), false)
  assert.equal(canSubmitSkuCleanup({ loading: false, submitting: true, preview }), false)
  assert.equal(canSubmitSkuCleanup({ cleanupLoading: true, preview }), false)
  assert.equal(canSubmitSkuCleanup({ cleanupSubmitting: true, preview }), false)
  assert.equal(canSubmitSkuCleanup({ loading: false, submitting: false, preview: { ...preview, summary: { eligible: 0 } } }), false)
  assert.equal(canSubmitSkuCleanup({ loading: false, submitting: false, preview: { ...preview, revision: 'short' } }), false)
})

test('formats cleanup summary counts', () => {
  assert.equal(
    formatSkuCleanupSummary({ eligible: 2, unchanged: 1, blocked: 3 }),
    'Siap 2 | Tidak berubah 1 | Terblokir 3'
  )
})

test('partitions preview rows into ready, unchanged, and blocked buckets', () => {
  const preview = partitionSkuCleanupPreview([
    { item_key: 'ready', status: 'ready' },
    { item_key: 'unchanged', status: 'unchanged' },
    { item_key: 'blocked', status: 'blocked' },
    { item_key: 'unknown', status: 'failed' }
  ])

  assert.deepEqual(preview.ready.map((item) => item.item_key), ['ready'])
  assert.deepEqual(preview.unchanged.map((item) => item.item_key), ['unchanged'])
  assert.deepEqual(preview.blocked.map((item) => item.item_key), ['blocked', 'unknown'])
})

test('labels every cleanup execution row status shown by the result table', () => {
  assert.equal(skuCleanupStatusLabel('updated'), 'Berhasil')
  assert.equal(skuCleanupStatusLabel('partial'), 'Sebagian')
  assert.equal(skuCleanupStatusLabel('submitted_unverified'), 'Belum terverifikasi')
  assert.equal(skuCleanupStatusLabel('blocked'), 'Terblokir')
  assert.equal(skuCleanupStatusLabel('stale_revision'), 'Preview kedaluwarsa')
  assert.equal(skuCleanupStatusLabel('failed'), 'Gagal')
  assert.equal(skuCleanupStatusLabel('completed'), 'Selesai')
})

test('formats partial results and keeps retry available', () => {
  const state = mergeSkuCleanupResult({ run_id: 'run-1', revision: 'a'.repeat(64) }, {
    status: 'partial',
    summary: { updated: 1, partial: 1, blocked: 3 }
  })

  assert.equal(state.tone, 'warning')
  assert.equal(state.canRetry, true)
  assert.equal(state.run_id, 'run-1')
  assert.equal(state.revision, 'a'.repeat(64))
  assert.match(state.message, /Berhasil 1/)
})

test('completed cleanup closes its modal and refreshes the add-candidate preview', () => {
  const state = mergeSkuCleanupResult({ run_id: 'run-1', revision: 'a'.repeat(64) }, {
    status: 'completed',
    summary: { updated: 2 }
  })

  assert.equal(state.shouldCloseModal, true)
  assert.equal(state.shouldRefreshCandidates, true)
})

test('derives the execution summary from per-row cleanup results', () => {
  const state = mergeSkuCleanupResult({ run_id: 'run-1', revision: 'a'.repeat(64) }, {
    status: 'partial',
    items: [
      { status: 'updated' },
      { status: 'partial' },
      { status: 'submitted_unverified' },
      { status: 'blocked' },
      { status: 'failed' }
    ]
  })

  assert.deepEqual(state.resultSummary, {
    updated: 1,
    partial: 1,
    submitted_unverified: 1,
    blocked: 1,
    stale_revision: 0,
    failed: 1
  })
  assert.match(state.message, /Berhasil 1/)
})

test('stale revision requires a new preview and marks every displayed row stale', () => {
  const state = mergeSkuCleanupResult({
    run_id: 'run-1',
    revision: 'a'.repeat(64),
    items: [{ item_key: 'row-1', status: 'ready' }]
  }, {
    status: 'stale_revision',
    run_id: 'run-1'
  })

  assert.equal(state.tone, 'error')
  assert.equal(state.canRetry, false)
  assert.equal(state.run_id, null)
  assert.equal(state.revision, null)
  assert.equal(state.items[0].status, 'stale_revision')
  assert.equal(state.resultSummary.stale_revision, 1)
  assert.match(state.message, /buat preview baru/i)
})
