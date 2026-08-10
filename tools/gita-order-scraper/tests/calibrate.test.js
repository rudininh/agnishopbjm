import test from 'node:test'
import assert from 'node:assert/strict'
import { openOrderCalibration } from '../src/calibrate.js'

test('opens the configured order page in a visible persistent browser without posting data', async () => {
  const calls = []
  const page = {
    async goto(url, options) {
      calls.push({ type: 'goto', url, options })
    }
  }
  const context = {
    pages: () => [page],
    async close() {
      calls.push({ type: 'close' })
    }
  }

  await openOrderCalibration({
    orderStartUrl: 'https://seller.example/orders',
    profileDir: 'tools/gita-order-scraper/.profile',
    timeoutMs: 45000
  }, {
    launchContext: async (config) => {
      calls.push({ type: 'launch', config })
      return context
    },
    waitForStop: async () => calls.push({ type: 'wait' })
  })

  assert.deepEqual(calls, [
    {
      type: 'launch',
      config: {
        orderStartUrl: 'https://seller.example/orders',
        profileDir: 'tools/gita-order-scraper/.profile',
        timeoutMs: 45000
      }
    },
    {
      type: 'goto',
      url: 'https://seller.example/orders',
      options: {
        waitUntil: 'domcontentloaded',
        timeout: 45000
      }
    },
    { type: 'wait' },
    { type: 'close' }
  ])
})
