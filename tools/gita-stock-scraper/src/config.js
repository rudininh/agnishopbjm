import { resolve } from 'node:path'

const REQUIRED_KEYS = [
  'GITA_SCRAPER_API_BASE_URL',
  'GITA_SCRAPER_INGEST_TOKEN',
  'GITA_SCRAPER_INVENTORY_URL',
  'GITA_SCRAPER_PROFILE_DIR'
]

const MIN_TIMEOUT_SECONDS = 10
const MAX_TIMEOUT_SECONDS = 120

export function loadWorkerConfig(env = process.env) {
  const values = Object.fromEntries(
    REQUIRED_KEYS.map((key) => [key, requiredValue(env, key)])
  )

  return {
    apiBaseUrl: values.GITA_SCRAPER_API_BASE_URL.replace(/\/+$/, ''),
    ingestToken: values.GITA_SCRAPER_INGEST_TOKEN,
    inventoryUrl: values.GITA_SCRAPER_INVENTORY_URL,
    profileDir: resolve(values.GITA_SCRAPER_PROFILE_DIR),
    headless: booleanValue(env.GITA_SCRAPER_HEADLESS, 'GITA_SCRAPER_HEADLESS', false),
    timeoutMs: boundedTimeoutMs(env.GITA_SCRAPER_TIMEOUT_SECONDS)
  }
}

function requiredValue(env, key) {
  const value = String(env[key] ?? '').trim()

  if (value === '') {
    throw new Error(`${key} is required.`)
  }

  return value
}

function booleanValue(value, key, defaultValue) {
  if (value === undefined || String(value).trim() === '') {
    return defaultValue
  }

  if (value === 'true') {
    return true
  }

  if (value === 'false') {
    return false
  }

  throw new Error(`${key} must be true or false.`)
}

function boundedTimeoutMs(value) {
  const parsed = Number.parseInt(String(value ?? MIN_TIMEOUT_SECONDS), 10)
  const seconds = Number.isFinite(parsed) ? parsed : MIN_TIMEOUT_SECONDS

  return Math.min(Math.max(seconds, MIN_TIMEOUT_SECONDS), MAX_TIMEOUT_SECONDS) * 1000
}
