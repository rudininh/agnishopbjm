import test from 'node:test'
import assert from 'node:assert/strict'
import {
  createDefaultProductForm,
  fillRandomProduct,
  validateProductForm,
  normalizeProductPayload,
  normalizePublicationPayload
} from '../src/pages/omnichannelProductFormState.js'

test('default form targets both primary marketplaces and starts with one variant', () => {
  const form = createDefaultProductForm()

  assert.deepEqual(form.accounts, ['shopee-agnishopbjm', 'tiktok-agnishopbjm'])
  assert.equal(form.variants.length, 1)
  assert.equal(form.variants[0].variant_name, '')
})

test('random product fixture has valid unique variant SKUs', () => {
  const form = fillRandomProduct(createDefaultProductForm())
  const result = validateProductForm(form)

  assert.equal(result.valid, true)
  assert.match(form.name, /Flanel|Kemeja/i)
  assert.equal(new Set(form.variants.map((variant) => variant.sku)).size, form.variants.length)
})

test('validation rejects duplicate variant SKUs before submit', () => {
  const form = fillRandomProduct(createDefaultProductForm())
  form.variants[1].sku = form.variants[0].sku

  const result = validateProductForm(form)

  assert.equal(result.valid, false)
  assert.equal(result.errors.some((error) => error.code === 'duplicate_sku'), true)
})

test('payload normalization excludes UI-only fields and preserves variants', () => {
  const form = fillRandomProduct(createDefaultProductForm())
  const payload = normalizeProductPayload(form)

  assert.equal(payload.accounts, undefined)
  assert.equal(payload.marketplace, undefined)
  assert.equal(payload.variants.length, 2)
  assert.equal(payload.variants[0].position, 0)
})

test('publication payload includes selected marketplace contexts only', () => {
  const form = fillRandomProduct(createDefaultProductForm())
  form.accounts = ['shopee-agnishopbjm']
  form.marketplace.shopee = { category_id: '123', weight: '0.5' }
  form.marketplace.tiktok = { category_id: '999', warehouse_id: 'WH-X' }

  const payload = normalizePublicationPayload(form)

  assert.deepEqual(payload.accounts, [
    { account_key: 'shopee-agnishopbjm', context: { category_id: '123', weight: 0.5 } }
  ])
})