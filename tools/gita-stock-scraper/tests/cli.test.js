import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { runScrape } from '../src/cli.js'

const config = {
  inventoryUrl: 'https://seller.example/inventory',
  profileDir: 'C:/temporary/gita-profile',
  headless: false,
  timeoutMs: 30000
}

async function fixture(name) {
  return readFile(new URL(`./fixtures/${name}`, import.meta.url), 'utf8')
}

function workerDependencies(html) {
  const posted = []
  let closed = false

  return {
    posted,
    contextClosed: () => closed,
    launchContext: async () => ({
      newPage: async () => ({
        goto: async () => undefined,
        content: async () => html
      }),
      close: async () => { closed = true }
    }),
    postRun: async (_config, payload) => { posted.push(payload) },
    now: () => new Date('2026-08-09T00:00:00.000Z')
  }
}

test('reports needs_login without posting item rows', async () => {
  const dependencies = workerDependencies(await fixture('login.html'))

  const result = await runScrape(config, dependencies)

  assert.deepEqual(result, { status: 'needs_login', itemCount: 0 })
  assert.equal(dependencies.posted[0].status, 'needs_login')
  assert.equal(dependencies.posted[0].items, undefined)
  assert.equal(dependencies.contextClosed(), true)
})

test('reports parser failures as failed without a partial payload', async () => {
  const dependencies = workerDependencies(await fixture('invalid-inventory.html'))

  const result = await runScrape(config, dependencies)

  assert.deepEqual(result, { status: 'failed', itemCount: 0 })
  assert.equal(dependencies.posted[0].status, 'failed')
  assert.equal(dependencies.posted[0].items, undefined)
})

test('posts one complete successful snapshot', async () => {
  const dependencies = workerDependencies(await fixture('inventory.html'))

  const result = await runScrape(config, dependencies)

  assert.deepEqual(result, { status: 'success', itemCount: 1 })
  assert.deepEqual(dependencies.posted[0], {
    status: 'success',
    started_at: '2026-08-09T00:00:00.000Z',
    finished_at: '2026-08-09T00:00:00.000Z',
    items: [{
      sku: 'GITA-RED-S',
      stock: 12,
      gita_product_id: '1001',
      gita_variant_id: '2001',
      captured_at: '2026-08-09T00:00:00.000Z'
    }]
  })
})
