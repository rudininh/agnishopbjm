import test from 'node:test'
import assert from 'node:assert/strict'
import {
  coverageDownloadFilename,
  filterShopeeGitaExceptions,
  toShopeeGitaCoverageViewModel
} from '../src/pages/shopeeGitaExportCoverageState.js'

test('maps coverage summary and marks partial exports', () => {
  const view = toShopeeGitaCoverageViewModel({
    revision: 'abc',
    summary: {
      source_products: 65,
      source_variants: 1992,
      ready_products: 60,
      ready_variants: 1693,
      exception_variants: 299,
      variants_by_status: { new_product: 82, new_variant: 217, sku_changed: 0, blocked: 0 }
    },
    items: []
  })

  assert.equal(view.isPartial, true)
  assert.equal(view.canDownloadMassUpdate, true)
  assert.equal(view.canDownloadExceptions, true)
  assert.equal(view.readyLabel, '60 produk / 1.693 varian')
  assert.equal(view.exceptionLabel, '299 varian perlu tindakan')
})

test('normalizes only exception items and missing coverage fields safely', () => {
  const view = toShopeeGitaCoverageViewModel({
    revision: null,
    items: [
      { status: 'mass_update_ready', product_name: 'Sudah siap' },
      { status: 'blocked' }
    ]
  })

  assert.equal(view.isPartial, false)
  assert.equal(view.canDownloadMassUpdate, false)
  assert.equal(view.canDownloadExceptions, false)
  assert.equal(view.readyLabel, '0 produk / 0 varian')
  assert.equal(view.exceptionLabel, '0 varian perlu tindakan')
  assert.deepEqual(view.items, [{
    status: 'blocked',
    reason: '',
    product_name: '',
    variant_name: '',
    source_seller_sku: ''
  }])
})

test('filters exceptions by product variant seller SKU status and reason without case sensitivity', () => {
  const items = [
    { product_name: 'Khiban series', variant_name: 'Baby pink', source_seller_sku: 'INT-478-BABY-PINK', status: 'new_product', reason: 'missing_target_product' },
    { product_name: 'Ninja non resleting', variant_name: 'Hitam', source_seller_sku: 'INT-526-HITAM', status: 'sku_changed', reason: 'target_sku_changed' }
  ]

  assert.equal(filterShopeeGitaExceptions(items, 'khiban').length, 1)
  assert.equal(filterShopeeGitaExceptions(items, 'hitam')[0].product_name, 'Ninja non resleting')
  assert.equal(filterShopeeGitaExceptions(items, 'INT-478')[0].variant_name, 'Baby pink')
  assert.equal(filterShopeeGitaExceptions(items, 'SKU_CHANGED')[0].product_name, 'Ninja non resleting')
  assert.equal(filterShopeeGitaExceptions(items, 'MISSING_TARGET_PRODUCT')[0].product_name, 'Khiban series')
})

test('prefers RFC 5987 filenames and falls back to the correct extension', () => {
  assert.equal(
    coverageDownloadFilename('mass-update', { 'content-disposition': "attachment; filename=old.zip; filename*=UTF-8''shopee_gita_mass_update_partial_%C3%A4.zip" }),
    'shopee_gita_mass_update_partial_ä.zip'
  )
  assert.equal(coverageDownloadFilename('exceptions', {}), 'shopee_gita_exceptions.csv')
  assert.equal(coverageDownloadFilename('sales-info'), 'shopee_gita_sales-info.xlsx')
})

test('uses the partial filename returned by content disposition', () => {
  assert.equal(
    coverageDownloadFilename('mass-update', { 'content-disposition': 'attachment; filename="shopee_gita_mass_update_partial_20260829.zip"' }),
    'shopee_gita_mass_update_partial_20260829.zip'
  )
})
