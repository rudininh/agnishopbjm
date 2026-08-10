import test from 'node:test'
import assert from 'node:assert/strict'
import { postOrderRun } from '../src/client.js'

test('sends one JSON order run to the protected endpoint', async () => {
  let request
  const response = await postOrderRun({
    apiBaseUrl: 'http://127.0.0.1:8000/api',
    ingestToken: 'worker-token'
  }, { status: 'needs_login' }, async (url, options) => {
    request = { url, options }
    return new Response('{}', { headers: { 'content-type': 'application/json' } })
  })

  assert.deepEqual(response, {})
  assert.equal(request.url, 'http://127.0.0.1:8000/api/gita-order-scrapes/runs')
  assert.equal(request.options.headers.Authorization, 'Bearer worker-token')
  assert.equal(request.options.body, JSON.stringify({ status: 'needs_login' }))
})

test('returns only the HTTP status when protected ingestion is rejected', async () => {
  await assert.rejects(
    postOrderRun({
      apiBaseUrl: 'http://127.0.0.1:8000/api',
      ingestToken: 'worker-token'
    }, { status: 'failed' }, async () => new Response('token detail must not be exposed', {
      status: 401,
      headers: { 'content-type': 'text/plain' }
    })),
    /Gita order run request failed \(401\)/
  )
})
