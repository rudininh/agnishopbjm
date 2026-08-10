import test from 'node:test'
import assert from 'node:assert/strict'
import { backendEnvTokenFromContents, loadOrderCalibrationConfig, loadOrderWorkerConfig } from '../src/config.js'

test('loads a visible local Gita order worker configuration', () => {
  const config = loadOrderWorkerConfig({
    GITA_ORDER_SCRAPER_API_BASE_URL: 'http://127.0.0.1:8000/api/',
    GITA_ORDER_SCRAPER_INGEST_TOKEN: 'worker-token',
    GITA_ORDER_SCRAPER_START_URL: 'https://seller.example/orders',
    GITA_ORDER_SCRAPER_PROFILE_DIR: 'tools/gita-order-scraper/.profile',
    GITA_ORDER_SCRAPER_HEADLESS: 'false',
    GITA_ORDER_SCRAPER_TIMEOUT_SECONDS: '45'
  }, {
    readBackendEnvToken: () => 'backend-token'
  })

  assert.equal(config.apiBaseUrl, 'http://127.0.0.1:8000/api')
  assert.equal(config.ingestToken, 'backend-token')
  assert.equal(config.orderStartUrl, 'https://seller.example/orders')
  assert.equal(config.headless, false)
  assert.equal(config.timeoutMs, 45000)
})

test('loads the local defaults and backend token without worker environment values', () => {
  const config = loadOrderWorkerConfig({}, {
    readBackendEnvToken: () => 'backend-token'
  })

  assert.deepEqual(config, {
    apiBaseUrl: 'http://agnishopbjm-laravel.test/api',
    ingestToken: 'backend-token',
    orderStartUrl: 'https://seller.shopee.co.id/portal/sale/order?type=toship&source=processed&sort_by=confirmed_date_asc',
    profileDir: config.profileDir,
    headless: false,
    timeoutMs: 30000
  })
})

test('ignores an explicit worker token and uses the backend environment token', () => {
  const config = loadOrderWorkerConfig({
    GITA_ORDER_SCRAPER_INGEST_TOKEN: 'worker-token'
  }, {
    readBackendEnvToken: () => 'backend-token'
  })

  assert.equal(config.ingestToken, 'backend-token')
})

test('reads a Laravel backend token without surrounding .env quotes', () => {
  assert.equal(
    backendEnvTokenFromContents('GITA_ORDER_SCRAPER_INGEST_TOKEN=quoted-token\n'),
    'quoted-token'
  )
  assert.equal(
    backendEnvTokenFromContents(`GITA_ORDER_SCRAPER_INGEST_TOKEN='single-quoted-token'\n`),
    'single-quoted-token'
  )
})

test('rejects a missing ingestion token from both local sources', () => {
  assert.throws(
    () => loadOrderWorkerConfig({}, { readBackendEnvToken: () => '' }),
    /Gita order ingestion token is required/
  )
})

test('loads calibration configuration without an API endpoint or ingestion token', () => {
  const config = loadOrderCalibrationConfig({
    GITA_ORDER_SCRAPER_START_URL: 'https://seller.example/orders',
    GITA_ORDER_SCRAPER_PROFILE_DIR: 'tools/gita-order-scraper/.profile',
    GITA_ORDER_SCRAPER_TIMEOUT_SECONDS: '45'
  })

  assert.deepEqual(config, {
    orderStartUrl: 'https://seller.example/orders',
    profileDir: config.profileDir,
    timeoutMs: 45000
  })
})

test('resolves a relative browser profile from the project root even when invoked in backend', () => {
  const projectRoot = process.cwd()
  const originalCwd = process.cwd()

  process.chdir(`${projectRoot}/backend`)
  try {
    const config = loadOrderCalibrationConfig({
      GITA_ORDER_SCRAPER_START_URL: 'https://seller.example/orders',
      GITA_ORDER_SCRAPER_PROFILE_DIR: 'tools/gita-order-scraper/.profile'
    })

    assert.equal(config.profileDir, `${projectRoot}\\tools\\gita-order-scraper\\.profile`)
  } finally {
    process.chdir(originalCwd)
  }
})
