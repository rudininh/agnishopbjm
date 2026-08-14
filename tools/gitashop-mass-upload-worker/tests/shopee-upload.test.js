import assert from 'node:assert/strict'
import test from 'node:test'
import { assertExpectedShop, classifySellerCentreState, findNewCompletedMassUpdateDocument, normalizeShopName, waitForMassUpdateUploadReady } from '../src/shopee-upload.js'

test('normalizes and validates the fixed Gitashopcollection identity', () => {
  assert.equal(normalizeShopName('Gita Shop Collection'), 'gitashopcollection')
  assert.doesNotThrow(() => assertExpectedShop('gitashopcollection', 'Gitashopcollection'))
  assert.throws(() => assertExpectedShop('gitashopcollectioncadangan', 'Gitashopcollection'), /expected shop/i)
  assert.throws(() => assertExpectedShop('Akun Lain', 'Gitashopcollection'), /expected shop/i)
})

test('classifies login and verification as safe stops', () => {
  assert.equal(classifySellerCentreState({ login: true }), 'needs_login')
  assert.equal(classifySellerCentreState({ captcha: true }), 'needs_verification')
  assert.equal(classifySellerCentreState({ uploadReady: false }), 'unexpected_page')
})

test('waits for the Mass Update upload UI to finish rendering', async () => {
  let waited = false
  const page = {
    waitForFunction: async (predicate, _arg, options) => {
      waited = true
      assert.equal(typeof predicate, 'function')
      assert.equal(options.timeout, 45000)
    }
  }

  await waitForMassUpdateUploadReady(page, 45000)

  assert.equal(waited, true)
})

test('detects a newly completed document even when Shopee adds a filename suffix', () => {
  const baseline = ['13/08/2026 07:14 Informasi Dasar mass_update_basic_info.xlsx 60 Selesai']
  const rows = [
    '13/08/2026 19:30 Informasi Dasar mass_update_basic_info(1).xlsx 60 Selesai',
    ...baseline
  ]

  assert.deepEqual(
    findNewCompletedMassUpdateDocument(rows, baseline, 'mass_update_basic_info.xlsx', 60),
    { shopeeStatus: 'Selesai', processedCount: 60 }
  )
})

test('ignores a completed document from before the current upload minute', () => {
  const oldRows = ['13/08/2026 19:40 Informasi Penjualan mass_update_sales_info.xlsx 60 Selesai']
  const currentRows = ['13/08/2026 20:05 Informasi Penjualan mass_update_sales_info.xlsx 60 Selesai']

  assert.equal(
    findNewCompletedMassUpdateDocument(oldRows, [], 'mass_update_sales_info.xlsx', 60, '2026-08-13 20:05:35'),
    null
  )
  assert.deepEqual(
    findNewCompletedMassUpdateDocument(currentRows, [], 'mass_update_sales_info.xlsx', 60, '2026-08-13 20:05:35'),
    { shopeeStatus: 'Selesai', processedCount: 60 }
  )
})
