import assert from 'node:assert/strict'
import test from 'node:test'
import {
  canSyncGitaOrder,
  dailyGitaCollectorCommand,
  formatGitaOrderDate,
  gitaOrderScraperLauncherMessage,
  gitaOrderScraperManualCommand,
  gitaShopMassUploadRoute,
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

test('formats UTC collector timestamps in Banjarmasin time', () => {
  assert.equal(formatGitaOrderDate('2026-08-12 04:49:04'), '12 Agu 2026, 12.49 WITA')
  assert.equal(formatGitaOrderDate('2026-08-12T04:49:04.000Z'), '12 Agu 2026, 12.49 WITA')
})

test('Gitashop upload navigation opens the marketplace import panel', () => {
  assert.equal(gitaShopMassUploadRoute, '/marketplace/import')
})

test('maps Gita scraper launcher states to safe visible copy', () => {
  assert.deepEqual(gitaOrderScraperLauncherMessage({ status: 'started' }), {
    type: 'success',
    text: 'Scraper Gita sedang dijalankan di PC ini.'
  })
  assert.deepEqual(gitaOrderScraperLauncherMessage({ status: 'already_running' }), {
    type: 'warning',
    text: 'Scraper Gita sudah berjalan; laporan akan diperbarui otomatis.'
  })
  assert.deepEqual(gitaOrderScraperLauncherMessage({ status: 'marketplace_busy' }), {
    type: 'warning',
    text: 'Scraper belum dijalankan karena operasi marketplace lain masih aktif.'
  })
  assert.doesNotMatch(gitaOrderScraperLauncherMessage({ status: 'unknown', token: 'secret' }).text, /secret|token/i)
  assert.match(gitaOrderScraperManualCommand, /npm run gita-order-scrape/)
})
