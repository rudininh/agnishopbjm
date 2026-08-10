import test from 'node:test'
import assert from 'node:assert/strict'
import { EMPTY_ORDER_SELECTOR, ORDER_TAB_SEQUENCE, orderContentTransitioned, orderScrapeFailureReason, runOrderScrape, sellerCentreTabSelector, waitForOrderContent } from '../src/cli.js'

const config = {
  orderStartUrl: 'https://seller.example/orders',
  profileDir: 'C:/temporary/gita-order-profile',
  headless: false,
  timeoutMs: 30000
}

function orderPage(orderId, detailId = orderId, next = false) {
  return `
    <main data-testid='order-list-table-skeleton'>
      <a data-testid='order-item' href='/portal/sale/order/${detailId}'>
        <div class='order-sn'>No. Pesanan ${orderId}</div>
        <div class='item'>
          <span class='item-name'>Produk</span>
          <span class='item-description'>Variasi: Sagee</span>
          <span class='item-amount'>1</span>
        </div>
      </a>
      ${next ? '<button class=eds-pager__button-next>Berikutnya</button>' : '<button class=eds-pager__button-next disabled>Berikutnya</button>'}
    </main>
  `
}

function orderDetailPage(...skus) {
  return `<main>${skus.map((sku) => `<p>Kode Variasi: ${sku}</p>`).join('\n')}</main>`
}

function workerDependencies(pagesByTab, detailPages = {}) {
  const posted = []
  const detailUrls = []
  const detailWaits = []
  let closed = false
  let tabStatus = 'to_ship'
  let pageIndex = 0
  let newPageCount = 0
  let detailUrl = ''

  return {
    posted,
    detailUrls,
    detailWaits,
    contextClosed: () => closed,
    launchContext: async () => ({
      newPage: async () => {
        newPageCount += 1

        if (newPageCount === 1) {
          return {
            goto: async () => undefined,
            url: () => 'https://seller.example/orders',
            content: async () => pagesByTab[tabStatus][pageIndex]
          }
        }

        return {
          goto: async (url) => {
            detailUrl = url
            detailUrls.push(url)
          },
          waitForFunction: async (_predicate, options) => { detailWaits.push(options) },
          content: async () => detailPages[detailUrl] ?? '<main></main>'
        }
      },
      close: async () => { closed = true }
    }),
    openTab: async (_page, tab) => {
      tabStatus = tab.status
      pageIndex = 0
    },
    advancePage: async () => {
      if (pageIndex + 1 >= pagesByTab[tabStatus].length) return false
      pageIndex += 1
      return true
    },
    postRun: async (_config, payload) => { posted.push(payload) },
    now: () => new Date('2026-08-09T00:00:00.000Z')
  }
}

test('maps the two required statuses to calibrated Seller Centre tabs', () => {
  assert.deepEqual(ORDER_TAB_SEQUENCE, [
    { status: 'to_ship', testId: 'l1-tab-toship' },
    { status: 'shipped', testId: 'l1-tab-shipping' }
  ])
  assert.equal(sellerCentreTabSelector('l1-tab-shipping'), '[data-testid=l1-tab-shipping]')
})

test('does not accept an empty transient DOM as a completed tab transition', () => {
  assert.match(EMPTY_ORDER_SELECTOR, /empty-order-wrapper/)
  assert.equal(orderContentTransitioned('ORDER-1', '', false), false)
  assert.equal(orderContentTransitioned('ORDER-1', 'ORDER-2', false), true)
  assert.equal(orderContentTransitioned('ORDER-1', '', true), true)
})

test('waits through an empty transient before accepting newly loaded orders', async () => {
  const states = [
    { hasEmptyState: false, hasTable: true, fingerprint: '' },
    { hasEmptyState: false, hasTable: true, fingerprint: 'ORDER-2' }
  ]
  let now = 0
  let waits = 0

  await waitForOrderContent({
    now: () => now,
    readState: async () => states.shift(),
    wait: async () => {
      waits += 1
      now += 100
    }
  }, 'ORDER-1', true, 1000)

  assert.equal(waits, 1)
})

test('classifies worker failures without returning raw error details', () => {
  assert.equal(orderScrapeFailureReason(new Error('Browser user data directory is already in use')), 'profile_in_use')
  assert.equal(orderScrapeFailureReason(new Error('Gita order run request failed (401).')), 'ingest_unauthorized')
  assert.equal(orderScrapeFailureReason(new Error('Timeout 30000ms exceeded.')), 'timeout')
  assert.equal(orderScrapeFailureReason(new Error('seller SKU is ambiguous')), 'parsing_conflicting_seller_sku')
  assert.equal(orderScrapeFailureReason(new Error('Detail seller SKU is unavailable.')), 'parsing_detail_seller_sku')
  assert.equal(orderScrapeFailureReason(new Error('Duplicate Gita order item.')), 'parsing_duplicate_item')
  assert.equal(orderScrapeFailureReason(new Error('quantity must be a positive integer')), 'parsing_quantity')
  assert.equal(orderScrapeFailureReason(new Error('unclassified')), 'unexpected')
})

test('visits every active tab and its order details before posting one complete run', async () => {
  const dependencies = workerDependencies({
    to_ship: [orderPage('ORDER-1', 'DETAIL-1', true), orderPage('ORDER-2', 'DETAIL-2')],
    shipped: [orderPage('ORDER-3', 'DETAIL-3')],
    completed: [orderPage('ORDER-4', 'DETAIL-4')]
  }, {
    'https://seller.example/portal/sale/order/DETAIL-1': orderDetailPage('INT-ORDER-1'),
    'https://seller.example/portal/sale/order/DETAIL-2': orderDetailPage('INT-ORDER-2'),
    'https://seller.example/portal/sale/order/DETAIL-3': orderDetailPage('INT-ORDER-3')
  })

  const result = await runOrderScrape(config, dependencies)

  assert.deepEqual(result, { status: 'success', itemCount: 3 })
  assert.equal(dependencies.posted.length, 1)
  assert.equal(dependencies.posted[0].items.length, 3)
  assert.deepEqual(dependencies.posted[0].items.map((item) => item.tab_status), [
    'to_ship', 'to_ship', 'shipped'
  ])
  assert.deepEqual(dependencies.detailUrls, [
    'https://seller.example/portal/sale/order/DETAIL-1',
    'https://seller.example/portal/sale/order/DETAIL-2',
    'https://seller.example/portal/sale/order/DETAIL-3'
  ])
  assert.deepEqual(dependencies.detailWaits, [
    { timeout: 30000 },
    { timeout: 30000 },
    { timeout: 30000 }
  ])
  assert.equal(dependencies.contextClosed(), true)
})

test('reports needs_login without posting item rows', async () => {
  const dependencies = workerDependencies({
    to_ship: ['<form><input type=password /></form>'],
    shipped: [orderPage('ORDER-3', 'DETAIL-3')]
  })

  const result = await runOrderScrape(config, dependencies)

  assert.deepEqual(result, { status: 'needs_login', itemCount: 0 })
  assert.equal(dependencies.posted[0].status, 'needs_login')
  assert.equal(dependencies.posted[0].items, undefined)
})

test('fails closed when an order line appears twice across tabs', async () => {
  const duplicate = orderPage('ORDER-1', 'DETAIL-1')
  const dependencies = workerDependencies({
    to_ship: [duplicate],
    shipped: [duplicate],
  }, {
    'https://seller.example/portal/sale/order/DETAIL-1': orderDetailPage('INT-ORDER-1')
  })

  const result = await runOrderScrape(config, dependencies)

  assert.deepEqual(result, { status: 'failed', itemCount: 0, reason: 'parsing_duplicate_item' })
  assert.equal(dependencies.posted[0].items, undefined)
})

test('reports needs_login when an order detail requires verification', async () => {
  const dependencies = workerDependencies({
    to_ship: [orderPage('ORDER-1', 'DETAIL-1')],
    shipped: [orderPage('ORDER-2', 'DETAIL-2')]
  }, {
    'https://seller.example/portal/sale/order/DETAIL-1': '<form><input type=password /></form>'
  })

  const result = await runOrderScrape(config, dependencies)

  assert.deepEqual(result, { status: 'needs_login', itemCount: 0 })
  assert.equal(dependencies.posted[0].status, 'needs_login')
  assert.equal(dependencies.posted[0].items, undefined)
})

test('fails closed when detail and list product counts differ', async () => {
  const dependencies = workerDependencies({
    to_ship: [orderPage('ORDER-1', 'DETAIL-1')],
    shipped: [orderPage('ORDER-2', 'DETAIL-2')]
  }, {
    'https://seller.example/portal/sale/order/DETAIL-1': orderDetailPage('INT-ORDER-1', 'INT-ORDER-EXTRA')
  })

  const result = await runOrderScrape(config, dependencies)

  assert.deepEqual(result, { status: 'failed', itemCount: 0, reason: 'parsing_detail_count' })
  assert.equal(dependencies.posted[0].items, undefined)
})

test('reports a sanitized workflow stage for an otherwise unclassified browser error', async () => {
  const posted = []
  const dependencies = {
    launchContext: async () => ({
      newPage: async () => ({
        goto: async () => { throw new Error('unclassified browser failure') }
      }),
      close: async () => undefined
    }),
    postRun: async (_config, payload) => { posted.push(payload) },
    now: () => new Date('2026-08-09T00:00:00.000Z')
  }

  const result = await runOrderScrape(config, dependencies)

  assert.deepEqual(result, { status: 'failed', itemCount: 0, reason: 'unexpected_open_order_list' })
  assert.equal(posted[0].message, 'Pengambilan pesanan Gita gagal.')
  assert.equal(JSON.stringify(posted).includes('unclassified browser failure'), false)
})
