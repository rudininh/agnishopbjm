import test from 'node:test'
import assert from 'node:assert/strict'
import { buildCallbackTarget } from '../../lib/callback-utils.cjs'

test('builds a Shopee target and preserves scalar and repeated callback query values', () => {
  const result = buildCallbackTarget({
    channel: 'shopee',
    query: { code: 'abc', shop_id: '123', state: ['one', 'two'] },
    env: { GITASHOP_BACKEND_URL: 'https://api.gitashop.example/' }
  })

  assert.deepEqual(result, {
    ok: true,
    url: 'https://api.gitashop.example/api/shopee/callback?code=abc&shop_id=123&state=one&state=two'
  })
})

test('uses the TikTok callback override when configured', () => {
  const result = buildCallbackTarget({
    channel: 'tiktok',
    query: { code: 'tiktok-code' },
    env: {
      GITASHOP_BACKEND_URL: 'https://api.gitashop.example',
      GITASHOP_TIKTOK_CALLBACK_URL: 'https://callback.gitashop.example/tiktok'
    }
  })

  assert.deepEqual(result, {
    ok: true,
    url: 'https://callback.gitashop.example/tiktok?code=tiktok-code'
  })
})

test('rejects an unconfigured backend without a redirect target', () => {
  assert.deepEqual(buildCallbackTarget({ channel: 'shopee', query: { code: 'abc' }, env: {} }), {
    ok: false,
    status: 503,
    error: 'Gitashop backend callback is not configured'
  })
})

test('rejects an invalid configured callback URL without exposing its value', () => {
  const result = buildCallbackTarget({
    channel: 'shopee',
    query: { code: 'abc' },
    env: { GITASHOP_BACKEND_URL: 'not a url' }
  })

  assert.equal(result.ok, false)
  assert.equal(result.status, 500)
  assert.equal(result.error, 'Gitashop backend callback configuration is invalid')
  assert.doesNotMatch(JSON.stringify(result), /not a url/)
})