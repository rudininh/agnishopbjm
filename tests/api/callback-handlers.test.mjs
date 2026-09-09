import test from 'node:test'
import assert from 'node:assert/strict'
import { createCallbackHandler } from '../../lib/callback-handler.cjs'

const createResponse = () => {
  const response = {
    statusCode: null,
    body: null,
    redirectCode: null,
    redirectLocation: null,
    status(code) {
      response.statusCode = code
      return response
    },
    json(body) {
      response.body = body
      return response
    },
    redirect(code, location) {
      response.redirectCode = code
      response.redirectLocation = location
      return response
    }
  }
  return response
}

test('returns 400 when Shopee callback code is missing', async () => {
  const response = createResponse()
  await createCallbackHandler('shopee')({ query: {} }, response)

  assert.equal(response.statusCode, 400)
  assert.deepEqual(response.body, { error: 'Missing code' })
})

test('returns 503 when TikTok backend callback is not configured', async () => {
  const response = createResponse()
  await createCallbackHandler('tiktok')({ query: { code: 'abc' }, env: {} }, response)

  assert.equal(response.statusCode, 503)
  assert.deepEqual(response.body, { error: 'Gitashop backend callback is not configured' })
})

test('redirects a valid callback to the configured Gitashop backend', async () => {
  const response = createResponse()
  await createCallbackHandler('shopee')({
    query: { code: 'abc', shop_id: '123' },
    env: { GITASHOP_BACKEND_URL: 'https://api.gitashop.example' }
  }, response)

  assert.equal(response.redirectCode, 302)
  assert.equal(response.redirectLocation, 'https://api.gitashop.example/api/shopee/callback?code=abc&shop_id=123')
})