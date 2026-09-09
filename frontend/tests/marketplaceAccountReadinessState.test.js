import test from 'node:test'
import assert from 'node:assert/strict'
import {
  canAuthorizeAccount,
  normalizeMarketplaceAccounts
} from '../src/pages/marketplaceAccountReadinessState.js'

test('normalizes all three account readiness cards in server order', () => {
  const cards = normalizeMarketplaceAccounts([
    { key: 'shopee-agnishopbjm', name: 'Shopee AgniShopBJM', channel: 'shopee', state: 'ready', checks: {}, mapped_skus: 10 },
    { key: 'tiktok-agnishopbjm', name: 'TikTok AgniShopBJM', channel: 'tiktok', state: 'ready', checks: {}, mapped_skus: 9 },
    { key: 'shopee-gitacollectionbjm', name: 'Shopee GitaCollectionBJM', channel: 'shopee', state: 'waiting_credentials', checks: {}, mapped_skus: 0 }
  ])

  assert.deepEqual(cards.map((card) => card.key), [
    'shopee-agnishopbjm',
    'tiktok-agnishopbjm',
    'shopee-gitacollectionbjm'
  ])
  assert.equal(cards[2].stateLabel, 'Menunggu kredensial')
})

test('keeps only readiness display fields and required environment variable names', () => {
  const [card] = normalizeMarketplaceAccounts([{
    key: 'shopee-agnishopbjm',
    name: 'Shopee AgniShopBJM',
    channel: 'shopee',
    state: 'waiting_credentials',
    message: 'Hubungkan akun marketplace.',
    checks: { credentials: true, active_token: false, ignored_secret: 'never display' },
    mapped_skus: 3,
    connect_action: 'connect-shopee-agnishopbjm',
    required_env: ['SHOPEE_PARTNER_ID'],
    access_token: 'secret',
    cipher: 'secret'
  }])

  assert.deepEqual(Object.keys(card).sort(), [
    'badgeClass', 'channel', 'channelLabel', 'checks', 'connectAction', 'key', 'mappedSkus', 'message', 'name', 'requiredEnv', 'state', 'stateLabel'
  ])
  assert.deepEqual(card.requiredEnv, ['SHOPEE_PARTNER_ID'])
  assert.deepEqual(card.checks, { credentials: true, activeToken: false, shopIdentity: false, tokenUsable: false, warehouse: false, mappings: false })
})

test('authorization is allowed only for credentialed Shopee accounts that require it', () => {
  assert.equal(canAuthorizeAccount({ channel: 'shopee', state: 'waiting_credentials', checks: { credentials: false } }), false)
  assert.equal(canAuthorizeAccount({ channel: 'shopee', state: 'authorization_required', checks: { credentials: true } }), true)
  assert.equal(canAuthorizeAccount({ channel: 'shopee', state: 'token_expired', checks: { credentials: true } }), true)
  assert.equal(canAuthorizeAccount({ channel: 'shopee', state: 'mapping_required', checks: { credentials: true } }), false)
  assert.equal(canAuthorizeAccount({ channel: 'shopee', state: 'ready', checks: { credentials: true } }), false)
  assert.equal(canAuthorizeAccount({ channel: 'shopee', state: 'disabled', checks: { credentials: true } }), false)
  assert.equal(canAuthorizeAccount({ channel: 'tiktok', state: 'authorization_required', checks: { credentials: true } }), false)
})
