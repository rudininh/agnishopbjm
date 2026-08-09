import test from 'node:test'
import assert from 'node:assert/strict'
import { resolve } from 'node:path'
import { loadWorkerConfig } from '../src/config.js'

const validEnvironment = () => ({
  GITA_SCRAPER_API_BASE_URL: 'http://127.0.0.1:8000/api/',
  GITA_SCRAPER_INGEST_TOKEN: 'test-token',
  GITA_SCRAPER_INVENTORY_URL: 'https://seller.example/inventory',
  GITA_SCRAPER_PROFILE_DIR: '.profile',
  GITA_SCRAPER_HEADLESS: 'false',
  GITA_SCRAPER_TIMEOUT_SECONDS: '200'
})

test('requires an ingestion URL, token, inventory URL, and profile directory', () => {
  const env = validEnvironment()
  delete env.GITA_SCRAPER_API_BASE_URL

  assert.throws(() => loadWorkerConfig(env), /GITA_SCRAPER_API_BASE_URL/)
})

test('returns a normalized local profile configuration with bounded timeout', () => {
  const config = loadWorkerConfig(validEnvironment())

  assert.deepEqual(config, {
    apiBaseUrl: 'http://127.0.0.1:8000/api',
    ingestToken: 'test-token',
    inventoryUrl: 'https://seller.example/inventory',
    profileDir: resolve('.profile'),
    headless: false,
    timeoutMs: 120000
  })
})

test('rejects an invalid headless setting', () => {
  const env = validEnvironment()
  env.GITA_SCRAPER_HEADLESS = 'sometimes'

  assert.throws(() => loadWorkerConfig(env), /GITA_SCRAPER_HEADLESS/)
})
