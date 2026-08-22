import test from 'node:test'
import assert from 'node:assert/strict'
import {
  canSubmitSkuCleanup,
  formatSkuCleanupSummary,
  mergeSkuCleanupResult
} from '../src/pages/shopeeSkuTiktokCleanupState.js'

test('allows submit only for a reviewed run with eligible rows', () => {
  assert.equal(canSubmitSkuCleanup({
    loading: false,
    submitting: false,
    preview: { run_id: 'run-1', revision: 'a'.repeat(64), summary: { eligible: 2 } }
  }), true)
})

test('does not allow submit while loading or without a usable preview', () => {
  const preview = { run_id: 'run-1', revision: 'a'.repeat(64), summary: { eligible: 2 } }

  assert.equal(canSubmitSkuCleanup({ loading: true, submitting: false, preview }), false)
  assert.equal(canSubmitSkuCleanup({ loading: false, submitting: true, preview }), false)
  assert.equal(canSubmitSkuCleanup({ loading: false, submitting: false, preview: { ...preview, summary: { eligible: 0 } } }), false)
  assert.equal(canSubmitSkuCleanup({ loading: false, submitting: false, preview: { ...preview, revision: 'short' } }), false)
})

test('formats cleanup summary counts', () => {
  assert.equal(
    formatSkuCleanupSummary({ eligible: 2, unchanged: 1, blocked: 3 }),
    'Siap 2 | Tidak berubah 1 | Terblokir 3'
  )
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

test('stale revision requires a new preview', () => {
  const state = mergeSkuCleanupResult({ run_id: 'run-1', revision: 'a'.repeat(64) }, {
    status: 'stale_revision',
    run_id: 'run-1'
  })

  assert.equal(state.tone, 'error')
  assert.equal(state.canRetry, false)
  assert.equal(state.run_id, null)
  assert.equal(state.revision, null)
  assert.match(state.message, /buat preview baru/i)
})
