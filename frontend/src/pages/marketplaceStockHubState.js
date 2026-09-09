import { normalizeMarketplaceAccount } from './marketplaceAccountsState.js'

const FALLBACK_ACCOUNTS = [
  { key: 'shopee-agnishopbjm', name: 'Shopee AgniShopBJM', channel: 'shopee', enabled: true },
  { key: 'tiktok-agnishopbjm', name: 'TikTok AgniShopBJM', channel: 'tiktok', enabled: true },
  { key: 'shopee-gitacollectionbjm', name: 'Shopee GitaCollectionBJM', channel: 'shopee', enabled: true }
]

export function stockHubAccounts(accounts = []) {
  const rows = (accounts.length ? accounts : FALLBACK_ACCOUNTS)
    .map(normalizeMarketplaceAccount)
    .filter((account) => account.enabled)
  return rows.sort((left, right) => left.key.localeCompare(right.key))
}

export function selectedStockHubAccount(accounts, selectedKey = '') {
  const options = stockHubAccounts(accounts)
  return options.find((account) => account.key === selectedKey) || options[0] || null
}

export function stockHubItemRequestParams(account) {
  const accountKey = String(account?.key || '').trim()
  return accountKey ? { account_key: accountKey } : {}
}

export function stockHubViewReset() {
  return {
    activeTab: 'live',
    page: 1,
    search: '',
    status: 'all',
    minimumStock: null,
    price: 'all',
    sort: 'updated_desc'
  }
}

export function stockHubActions(account) {
  if (!account) return []
  const actions = [{ key: 'load', label: 'Ambil Produk' }, { key: 'refresh', label: 'Refresh Cache' }]
  if (account.channel === 'shopee') actions.push({ key: 'legacy', label: 'Buka Stok Shopee' })
  if (account.channel === 'tiktok') actions.push({ key: 'legacy', label: 'Buka Stok TikTok' })
  return actions
}

export function stockHubChannelLabel(account) {
  return account?.channel === 'tiktok' ? 'TikTok' : 'Shopee'
}
