import assert from 'node:assert/strict'
import test from 'node:test'
import * as massUploadState from '../src/pages/gitashopMassUploadState.js'
import {
  isMassUploadTerminal,
  toMassUploadViewModel
} from '../src/pages/gitashopMassUploadState.js'

const createJob = (overrides = {}) => ({
  id: 42,
  account_key: 'shopee-gitacollectionbjm',
  expected_shop_name: 'Gitashopcollection',
  status: 'berjalan',
  message: 'Upload sedang berjalan.',
  requested_at: '2026-08-12T20:49:00Z',
  started_at: '2026-08-12T20:50:00Z',
  files: [
    {
      id: 1,
      sequence: 1,
      file_type: 'basic-info',
      filename: 'mass_update_basic_info.xlsx',
      row_count: 60,
      sha256: 'abcdef1234567890abcdef1234567890abcdef1234567890abcdef1234567890',
      status: 'selesai',
      shopee_status: 'Selesai',
      shopee_processed_count: 60,
      completed_at: '2026-08-12T20:53:00Z'
    },
    {
      id: 6,
      sequence: 6,
      file_type: 'republish-items',
      filename: 'mass_republish_items.xlsx',
      row_count: 0,
      sha256: '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
      status: 'selesai',
      shopee_status: 'Selesai',
      shopee_processed_count: 0,
      completed_at: '2026-08-12T20:58:00Z'
    }
  ],
  ...overrides
})

test('formats job audit timestamps in WITA', () => {
  const viewModel = toMassUploadViewModel(createJob())

  assert.equal(viewModel.requestedAt, '13 Agu 2026, 04.49 WITA')
  assert.equal(viewModel.startedAt, '13 Agu 2026, 04.50 WITA')
  assert.equal(viewModel.files[0].completedAt, '13 Agu 2026, 04.53 WITA')
})

test('shows safe visible copy for every terminal job state', () => {
  const cases = [
    ['menunggu_verifikasi', 'Perlu verifikasi manual', 'Login, OTP, CAPTCHA, atau verifikasi tambahan perlu diselesaikan di browser Gitashopcollection.'],
    ['dibatalkan_aman', 'Dibatalkan demi keamanan', 'Upload tidak dijalankan karena STB masih sinkronisasi atau statusnya tidak dapat dipastikan.'],
    ['selesai_dengan_gagal', 'Selesai dengan kegagalan', 'Satu atau lebih file gagal sehingga file berikutnya tidak diunggah.'],
    ['selesai', 'Selesai', 'Enam file Mass Update telah diproses sesuai audit job.']
  ]

  for (const [status, label, fallbackMessage] of cases) {
    const viewModel = toMassUploadViewModel(createJob({ status, message: '' }))

    assert.equal(viewModel.statusLabel, label)
    assert.equal(viewModel.message, fallbackMessage)
    assert.equal(viewModel.isTerminal, true)
    assert.equal(isMassUploadTerminal(status), true)
  }
})

test('keeps zero-row Republish audit as completed with Shopee processed zero', () => {
  const viewModel = toMassUploadViewModel(createJob())
  const republish = viewModel.files.find((file) => file.fileType === 'republish-items')

  assert.equal(republish.rowCountLabel, '0 baris')
  assert.equal(republish.shopeeProcessedLabel, 'Selesai: 0 diproses')
  assert.equal(republish.hashPrefix, '0123456789ab')
})

test('adds absent audit entries and removes unsafe browser details from messages', () => {
  const viewModel = toMassUploadViewModel(createJob({
    message: 'profile C:\\secret\\.profile cookie=abc raw response',
    files: []
  }))

  assert.equal(viewModel.files.length, 6)
  assert.equal(viewModel.files[0].statusLabel, 'Menunggu')
  assert.equal(viewModel.message, 'Detail aman tersedia pada audit job.')
  assert.equal(viewModel.canStartNewJob, false)
})

test('describes coverage mismatch as an actionable blocked upload', () => {
  const view = toMassUploadViewModel({
    id: 44,
    status: 'dibatalkan_aman',
    message: 'Template Gitashop belum mencakup 29 dari 1992 varian sumber; 3 baris target sudah tidak cocok. Periksa preflight Download Mass Update.',
    files: []
  })

  assert.match(view.message, /Periksa preflight Download Mass Update/)
  assert.equal(view.statusTone, 'warning')
})

test('warns before automatic upload when export coverage is partial', () => {
  assert.equal(
    massUploadState.massUploadPreflightWarning?.({ isPartial: true }),
    'Export Mass Update ini parsial. Fase 1 tetap fail-closed dan tidak akan membuat listing baru.'
  )
  assert.equal(massUploadState.massUploadPreflightWarning?.({ isPartial: false }), '')
  assert.equal(massUploadState.massUploadPreflightWarning?.(null), '')
})

test('invalidates the previous coverage while refresh is pending and after failure', async () => {
  let coverage = { revision: 'stale-revision' }
  let rejectRequest
  const request = new Promise((resolve, reject) => { rejectRequest = reject })
  const refresh = massUploadState.refreshCoverageSnapshot?.({
    request: () => request,
    normalize: (response) => response,
    replace: (value) => { coverage = value }
  })

  assert.equal(coverage, null)
  rejectRequest(new Error('coverage unavailable'))
  const result = await refresh

  assert.equal(result?.ok, false)
  assert.equal(result?.error.message, 'coverage unavailable')
  assert.equal(coverage, null)
})

test('enables refreshed coverage only after the request succeeds', async () => {
  let coverage = { revision: 'stale-revision' }
  let resolveRequest
  const request = new Promise((resolve) => { resolveRequest = resolve })
  const refresh = massUploadState.refreshCoverageSnapshot?.({
    request: () => request,
    normalize: (response) => ({ revision: response.revision, canDownloadMassUpdate: true }),
    replace: (value) => { coverage = value }
  })

  assert.equal(coverage, null)
  resolveRequest({ revision: 'fresh-revision' })
  const result = await refresh

  assert.equal(result?.ok, true)
  assert.deepEqual(coverage, { revision: 'fresh-revision', canDownloadMassUpdate: true })
})

test('does not request automatic upload when partial coverage is not acknowledged', async () => {
  let confirmations = 0
  let requests = 0
  const result = await massUploadState.startMassUploadAfterPreflight?.({
    coverage: { isPartial: true },
    confirmPartial: (message) => {
      confirmations += 1
      assert.match(message, /fail-closed/)
      assert.match(message, /tidak akan membuat listing baru/)
      return false
    },
    request: async () => { requests += 1 }
  })

  assert.equal(result?.started, false)
  assert.equal(confirmations, 1)
  assert.equal(requests, 0)
})

test('requests automatic upload after partial coverage is acknowledged', async () => {
  let requests = 0
  const result = await massUploadState.startMassUploadAfterPreflight?.({
    coverage: { isPartial: true },
    confirmPartial: () => true,
    request: async () => {
      requests += 1
      return { data: { data: { id: 45 } } }
    }
  })

  assert.equal(result?.started, true)
  assert.equal(result?.response.data.data.id, 45)
  assert.equal(requests, 1)
})

test('starts a complete-coverage upload without asking for acknowledgement', async () => {
  let confirmations = 0
  let requests = 0
  const result = await massUploadState.startMassUploadAfterPreflight?.({
    coverage: { isPartial: false },
    confirmPartial: () => { confirmations += 1; return false },
    request: async () => { requests += 1; return { data: {} } }
  })

  assert.equal(result?.started, true)
  assert.equal(confirmations, 0)
  assert.equal(requests, 1)
})
