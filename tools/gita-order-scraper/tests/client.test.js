import test from 'node:test'
import assert from 'node:assert/strict'
import { claimOrderScraperLease, postOrderRun, releaseOrderScraperLease, renewOrderScraperLease } from '../src/client.js'

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

test('uses the dedicated bearer token for Gita scraper lease requests', async () => {
  const requests = []
  const config = {
    apiBaseUrl: 'http://127.0.0.1:8000/api',
    ingestToken: 'worker-token'
  }
  const fetchImpl = async (url, options) => {
    requests.push({ url, options })

    if (url.endsWith('/worker/lease')) {
      return new Response(JSON.stringify({ data: { status: 'claimed', token: 'claim-token' } }), {
        headers: { 'content-type': 'application/json' }
      })
    }

    return new Response(JSON.stringify({ data: { status: 'ok' } }), {
      headers: { 'content-type': 'application/json' }
    })
  }

  const claim = await claimOrderScraperLease(config, fetchImpl)
  await renewOrderScraperLease(config, claim.token, fetchImpl)
  await releaseOrderScraperLease(config, claim.token, fetchImpl)

  assert.equal(claim.status, 'claimed')
  assert.deepEqual(requests.map((request) => request.options.headers.Authorization), [
    'Bearer worker-token',
    'Bearer worker-token',
    'Bearer worker-token'
  ])
  assert.equal(requests[1].options.body, JSON.stringify({ lease_token: 'claim-token' }))
  assert.equal(requests[2].options.body, JSON.stringify({ lease_token: 'claim-token' }))
})
