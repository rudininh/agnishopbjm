import test from 'node:test'
import assert from 'node:assert/strict'
import { buildGitaOrderScrapeQuery, gitaOrderMatchStatusLabel, gitaOrderTabStatusLabel } from '../src/pages/gitaOrderScrapeState.js'

test('builds only read-only order report filters', () => {
  assert.deepEqual(buildGitaOrderScrapeQuery({
    matchStatus: 'matched',
    tabStatus: 'to_ship',
    page: 2
  }), {
    match_status: 'matched',
    tab_status: 'to_ship',
    page: 2
  })
})

test('uses visible labels for persisted order statuses', () => {
  assert.equal(gitaOrderMatchStatusLabel('matched'), 'Cocok')
  assert.equal(gitaOrderMatchStatusLabel('unmatched'), 'Tidak ditemukan di Stock Master')
  assert.equal(gitaOrderTabStatusLabel('to_ship'), 'Perlu Dikirim')
  assert.equal(gitaOrderTabStatusLabel('completed'), 'Status tidak dikenal')
})

test('excludes the completed tab from report filters', () => {
  assert.deepEqual(buildGitaOrderScrapeQuery({ tabStatus: 'completed' }), {})
})
