import { readFileSync } from 'node:fs'
import { dirname, isAbsolute, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const PROJECT_ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../../..')

const DEFAULT_API_BASE_URL = 'http://agnishopbjm-laravel.test/api'
const DEFAULT_ORDER_START_URL = 'https://seller.shopee.co.id/portal/sale/order?type=toship&source=to_process&sort_by=confirmed_date_asc'
const DEFAULT_PROFILE_DIR = 'tools/gita-order-scraper/.profile'
const DEFAULT_WORKER_LEASE_SECONDS = 900
const DEFAULT_TIMEOUT_SECONDS = 90

export function loadOrderWorkerConfig(env = process.env, dependencies = {}) {
  const readBackendEnvToken = dependencies.readBackendEnvToken ?? backendEnvToken
  const calibration = loadOrderCalibrationConfig(env)
  const ingestToken = String(readBackendEnvToken()).trim()

  if (ingestToken === '') {
    throw new Error('Gita order ingestion token is required in backend/.env.')
  }

  return {
    apiBaseUrl: configuredValue(env, 'GITA_ORDER_SCRAPER_API_BASE_URL', DEFAULT_API_BASE_URL).replace(/\/+$/, ''),
    ingestToken,
    ...calibration,
    headless: booleanValue(env.GITA_ORDER_SCRAPER_HEADLESS, 'GITA_ORDER_SCRAPER_HEADLESS', false),
    operationLeaseToken: optionalValue(env, 'GITA_ORDER_SCRAPER_OPERATION_LEASE_TOKEN'),
    leaseSeconds: workerLeaseSeconds(env.GITA_ORDER_SCRAPER_LOCAL_WORKER_LEASE_SECONDS),
    leaseRenewMs: workerLeaseRenewMs(workerLeaseSeconds(env.GITA_ORDER_SCRAPER_LOCAL_WORKER_LEASE_SECONDS)),
  }
}

export function loadOrderCalibrationConfig(env = process.env) {
  return {
    orderStartUrl: configuredValue(env, 'GITA_ORDER_SCRAPER_START_URL', DEFAULT_ORDER_START_URL),
    profileDir: resolveProfileDir(configuredValue(env, 'GITA_ORDER_SCRAPER_PROFILE_DIR', DEFAULT_PROFILE_DIR)),
    timeoutMs: timeoutMs(env.GITA_ORDER_SCRAPER_TIMEOUT_SECONDS)
  }
}

function resolveProfileDir(value) {
  return isAbsolute(value) ? value : resolve(PROJECT_ROOT, value)
}

function configuredValue(env, key, fallback) {
  return optionalValue(env, key) || fallback
}

function optionalValue(env, key) {
  return String(env[key] ?? '').trim()
}

function backendEnvToken() {
  let contents

  try {
    contents = readFileSync(resolve(PROJECT_ROOT, 'backend/.env'), 'utf8')
  } catch {
    return ''
  }

  return backendEnvTokenFromContents(contents)
}

export function backendEnvTokenFromContents(contents) {
  for (const line of contents.split(/\r?\n/)) {
    const match = line.match(/^\s*GITA_ORDER_SCRAPER_INGEST_TOKEN\s*=\s*(.*?)\s*$/)
    if (match) return unquoteEnvValue(match[1].trim())
  }

  return ''
}

function unquoteEnvValue(value) {
  const quote = value.at(0)

  return (quote === String.fromCharCode(34) || quote === '\'') && value.endsWith(quote)
    ? value.slice(1, -1)
    : value
}

function booleanValue(value, key, defaultValue) {
  if (value === undefined || String(value).trim() === '') return defaultValue
  if (value === 'true') return true
  if (value === 'false') return false

  throw new Error(`${key} must be true or false.`)
}

function timeoutMs(value) {
  const parsed = Number.parseInt(String(value ?? DEFAULT_TIMEOUT_SECONDS), 10)
  const seconds = Number.isFinite(parsed) ? parsed : DEFAULT_TIMEOUT_SECONDS

  return Math.min(Math.max(seconds, 10), 180) * 1000
}

function workerLeaseSeconds(value) {
  const parsed = Number.parseInt(String(value ?? DEFAULT_WORKER_LEASE_SECONDS), 10)
  const seconds = Number.isFinite(parsed) ? parsed : DEFAULT_WORKER_LEASE_SECONDS

  return Math.min(Math.max(seconds, 60), 3600)
}

function workerLeaseRenewMs(seconds) {
  return Math.max(15000, Math.min(seconds * 500, 300000))
}
