import test from 'node:test'
import assert from 'node:assert/strict'
import {
  selectedStockHubAccount,
  stockHubAccounts,
  stockHubActions,
  stockHubItemRequestParams,
  stockHubViewReset
} from '../src/pages/marketplaceStockHubState.js'

test('keeps the configured account order stable and falls back safely', () => {
  const accounts = stockHubAccounts([{ key: 'shopee-gitashop', name: 'Gita', channel: 'shopee', enabled: true }, { key: 'disabled', channel: 'tiktok', enabled: false }])
  assert.deepEqual(accounts.map((account) => account.key), ['shopee-gitashop'])
  assert.equal(selectedStockHubAccount(accounts, 'missing').key, 'shopee-gitashop')
})

test('hides channel-specific actions for no account and exposes legacy link for selected channel', () => {
  assert.deepEqual(stockHubActions(null), [])
  assert.equal(stockHubActions({ channel: 'tiktok' }).at(-1).label, 'Buka Stok TikTok')
})

test('scopes product requests and resets the full view when the selected store changes', () => {
  assert.deepEqual(stockHubItemRequestParams({ key: 'shopee-gitacollectionbjm' }), {
    account_key: 'shopee-gitacollectionbjm'
  })
  assert.deepEqual(stockHubItemRequestParams(null), {})
  assert.deepEqual(stockHubViewReset(), {
    activeTab: 'live',
    page: 1,
    search: '',
    status: 'all',
    minimumStock: null,
    price: 'all',
    sort: 'updated_desc'
  })
})
