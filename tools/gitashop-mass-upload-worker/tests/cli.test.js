import assert from 'node:assert/strict'
import test from 'node:test'
import { runMassUploadWorker } from '../src/cli.js'

test('stops at verification before it downloads or submits a file', async () => {
  const events = []
  const client = {
    heartbeat: async () => {},
    claim: async () => ({ claim_token: 'claim-token', job: { id: 7, expected_shop_name: 'Gitashopcollection' }, file: { id: 9, filename: 'mass_republish_items.xlsx', file_type: 'republish-items', row_count: 0 } }),
    download: async () => { throw new Error('download must not happen') },
    event: async (...args) => events.push(args)
  }
  const page = { goto: async () => {}, evaluate: async () => ({ captcha: true, uploadReady: false, shopText: '' }) }
  const browserApi = { chromium: { launchPersistentContext: async () => ({ pages: () => [page], close: async () => {} }) } }
  const result = await runMassUploadWorker({ profileDir: 'C:/profile', headless: true, timeoutMs: 10000 }, { client, browserApi })

  assert.equal(result.status, 'needs_verification')
  assert.equal(events.length, 1)
  assert.equal(events[0][2].claimToken, 'claim-token')
  assert.equal(events[0][2].status, 'menunggu_verifikasi')
})

test('resumes a processing file without selecting it again', async () => {
  const events = []
  let evaluation = 0
  const client = {
    heartbeat: async () => {},
    claim: async () => ({
      claim_token: 'claim-token',
      job: { id: 7, expected_shop_name: 'Gitashopcollection' },
      file: {
        id: 9,
        filename: 'mass_update_sales_info.xlsx',
        file_type: 'sales-info',
        row_count: 1730,
        shopee_expected_processed_count: 60,
        status: 'memproses',
        uploaded_at: '2026-08-13 20:05:35'
      }
    }),
    renew: async () => {},
    download: async () => { throw new Error('processing files must not be downloaded again') },
    event: async (...args) => events.push(args)
  }
  const page = {
    goto: async () => {},
    waitForFunction: async () => {},
    waitForTimeout: async () => {},
    evaluate: async () => {
      evaluation += 1
      if (evaluation === 1) return { uploadReady: true, shopText: 'Gitashopcollection' }
      return [
        '13/08/2026 19:40 Informasi Penjualan mass_update_sales_info.xlsx 60 Selesai',
        '13/08/2026 20:05 Informasi Penjualan mass_update_sales_info.xlsx 60 Selesai'
      ]
    }
  }
  const browserApi = { chromium: { launchPersistentContext: async () => ({ pages: () => [page], close: async () => {} }) } }

  const result = await runMassUploadWorker({ profileDir: 'C:/profile', headless: true, timeoutMs: 10000 }, { client, browserApi })

  assert.equal(result.status, 'success')
  assert.deepEqual(events.map((event) => event[2].status), ['selesai'])
  assert.equal(events[0][2].shopee_processed_count, 60)
})
