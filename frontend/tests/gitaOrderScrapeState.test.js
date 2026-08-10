import assert from 'node:assert/strict'
import test from 'node:test'
import {
  canSyncGitaOrder,
  dailyGitaCollectorCommand,
  gitaOrderSyncActionLabel,
  gitaOrderSyncStatusLabel
} from '../src/pages/gitaOrderScrapeState.js'

test('labels and enables Gita order sync state safely', () => {
  assert.equal(gitaOrderSyncStatusLabel('pending'), 'Belum Disinkronkan')
  assert.equal(gitaOrderSyncStatusLabel('synced'), 'Sudah Disinkronkan')
  assert.equal(gitaOrderSyncActionLabel({ sync_status: 'failed' }), 'Coba Lagi')
  assert.equal(canSyncGitaOrder({ match_status: 'matched', sync_status: 'failed' }), true)
  assert.equal(canSyncGitaOrder({ match_status: 'unmatched', sync_status: 'blocked' }), false)
})

test('daily collector command documents local settings without a token', () => {
  assert.match(dailyGitaCollectorCommand, /GITA_ORDER_SCRAPER_API_BASE_URL/)
  assert.match(dailyGitaCollectorCommand, /GITA_ORDER_SCRAPER_PROFILE_DIR/)
  assert.match(dailyGitaCollectorCommand, /GITA_ORDER_SCRAPER_HEADLESS/)
  assert.match(dailyGitaCollectorCommand, /npm run gita-order-scrape/)
  assert.doesNotMatch(dailyGitaCollectorCommand, /INGEST_TOKEN|ee88/i)
})
