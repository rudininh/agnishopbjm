import test from 'node:test'
import assert from 'node:assert/strict'
import { accountPayload, normalizeMarketplaceAccount } from '../src/pages/marketplaceAccountsState.js'

test('normalizes safe account metadata without credentials', () => {
  const account = normalizeMarketplaceAccount({ key: 'x', name: 'X', channel: 'shopee', credentials: { partner_key: 'secret' } })
  assert.equal(account.account_key, 'x')
  assert.equal('credentials' in account, false)
})

test('account payload only selects channel credentials', () => {
  const payload = accountPayload({ account_key: ' Toko-Baru ', name: 'Toko Baru', channel: 'tiktok', enabled: true, credentials: { app_key: 'a', partner_key: 'should-not-send' } })
  assert.equal(payload.account_key, 'toko-baru')
  assert.deepEqual(payload.credentials, { app_key: 'a' })
  assert.equal('credentials' in accountPayload({ account_key: 'toko-baru', name: 'Toko Baru', channel: 'tiktok', enabled: true, credentials: {} }), false)
})
