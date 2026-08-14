import assert from 'node:assert/strict'
import test from 'node:test'
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
