import test from 'node:test'
import assert from 'node:assert/strict'
import {
  createAdjustmentPayload,
  formatDeliveryState
} from '../src/pages/mobileStockMasterState.js'

test('builds a signed Stock Master adjustment payload from a mobile form', () => {
  assert.deepEqual(createAdjustmentPayload({
    stockMasterId: 42,
    direction: 'decrease',
    quantity: '3',
    reason: 'sale',
    note: 'Pesanan offline'
  }), {
    stock_master_id: 42,
    delta: -3,
    reason: 'sale',
    note: 'Pesanan offline'
  })
})

test('rejects zero, invalid, or missing Stock Master adjustment inputs', () => {
  assert.throws(() => createAdjustmentPayload({
    stockMasterId: 42,
    direction: 'increase',
    quantity: 0,
    reason: 'receiving'
  }), /lebih dari nol/)

  assert.throws(() => createAdjustmentPayload({
    stockMasterId: 0,
    direction: 'increase',
    quantity: 1,
    reason: 'receiving'
  }), /pilih varian/)
})

test('formats delivery states without claiming that marketplace stock was pushed', () => {
  assert.equal(formatDeliveryState('mapped_pending'), 'Siap dikirim saat live push diaktifkan')
  assert.equal(formatDeliveryState('mapping_required'), 'Perlu mapping varian')
  assert.equal(formatDeliveryState('disabled'), 'Akun tidak aktif')
})
