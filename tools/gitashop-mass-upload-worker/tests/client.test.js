import assert from 'node:assert/strict'
import test from 'node:test'
import { MassUploadClient } from '../src/client.js'

test('worker client sends only its dedicated bearer token', async () => {
  let request
  const client = new MassUploadClient({ apiBaseUrl: 'http://api.test', token: 'worker-token', workerName: 'worker' }, async (url, options) => {
    request = { url, options }
    return new Response(JSON.stringify({ data: null }), { status: 200, headers: { 'Content-Type': 'application/json' } })
  })
  await client.claim()
  assert.equal(request.url, 'http://api.test/internal/shopee-gita-mass-upload/claim')
  assert.equal(request.options.headers.Authorization, 'Bearer worker-token')
})
