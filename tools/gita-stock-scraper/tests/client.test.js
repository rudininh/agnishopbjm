import test from 'node:test'
import assert from 'node:assert/strict'
import { postRun } from '../src/client.js'

const config = {
  apiBaseUrl: 'https://agnishop.test/api',
  ingestToken: 'worker-token'
}

const successPayload = {
  status: 'success',
  started_at: '2026-08-09T00:00:00.000Z',
  finished_at: '2026-08-09T00:00:10.000Z',
  items: [{ sku: 'GITA-RED-S', stock: 12, captured_at: '2026-08-09T00:00:05.000Z' }]
}

test('posts a complete successful run with the dedicated bearer token', async () => {
  let request
  const result = await postRun(config, successPayload, async (url, options) => {
    request = { url, ...options }

    return new Response(JSON.stringify({ data: { run_id: 1 } }), {
      status: 201,
      headers: { 'content-type': 'application/json' }
    })
  })

  assert.equal(request.url, 'https://agnishop.test/api/gita-stock-scrapes/runs')
  assert.equal(request.method, 'POST')
  assert.equal(request.headers.Authorization, 'Bearer worker-token')
  assert.deepEqual(JSON.parse(request.body), successPayload)
  assert.deepEqual(result, { data: { run_id: 1 } })
})

test('rejects a non-success response without exposing its response body', async () => {
  await assert.rejects(
    () => postRun(config, successPayload, async () => new Response('secret body', { status: 500 })),
    /Unable to record Gita scraper run\./
  )
})
