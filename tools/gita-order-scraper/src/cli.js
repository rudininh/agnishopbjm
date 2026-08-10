import { chromium } from 'playwright'
import { parseHTML } from 'linkedom'
import { pathToFileURL } from 'node:url'
import { loadOrderWorkerConfig } from './config.js'
import { postOrderRun } from './client.js'
import { detectOrderPageState, extractDetailSellerSkus, extractOrderCandidates, hasNextOrderPage } from './orders.js'

const ORDER_CARD_SELECTOR = '[data-testid=order-item]'
const ORDER_NUMBER_SELECTOR = `${ORDER_CARD_SELECTOR} .order-sn`
const NEXT_PAGE_SELECTOR = '.eds-pager__button-next'
const ORDER_TABLE_SELECTOR = '[data-testid=order-list-table-skeleton]'
export const EMPTY_ORDER_SELECTOR = '[data-testid*=empty], .eds-empty, .order-list-empty, .empty-order-wrapper'

export const ORDER_TAB_SEQUENCE = [
  { status: 'to_ship', testId: 'l1-tab-toship' },
  { status: 'shipped', testId: 'l1-tab-shipping' }
]

export function sellerCentreTabSelector(testId) {
  return `[data-testid=${testId}]`
}

export function orderContentTransitioned(previousFingerprint, currentFingerprint, hasEmptyState) {
  return currentFingerprint !== previousFingerprint
    && (currentFingerprint !== '' || hasEmptyState)
}

export function orderScrapeFailureReason(error) {
  const message = error instanceof Error ? error.message : ''

  if (/user data directory|profile.*(?:in use|lock)|another browser/i.test(message)) {
    return 'profile_in_use'
  }

  if (/\b(?:401|403)\b|unauthori[sz]ed|forbidden/i.test(message)) {
    return 'ingest_unauthorized'
  }

  if (/timeout|timed out/i.test(message)) {
    return 'timeout'
  }

  if (/duplicate gita order item|duplicate order item/i.test(message)) return 'parsing_duplicate_item'
  if (/seller sku is ambiguous/i.test(message)) return 'parsing_conflicting_seller_sku'
  if (/detail seller sku is unavailable/i.test(message)) return 'parsing_detail_seller_sku'
  if (/seller sku is unavailable/i.test(message)) return 'parsing_seller_sku'
  if (/order detail url is required|order detail url is invalid/i.test(message)) return 'parsing_detail_url'
  if (/detail order item count does not match/i.test(message)) return 'parsing_detail_count'
  if (/seller order id is required/i.test(message)) return 'parsing_order_id'
  if (/product title is required/i.test(message)) return 'parsing_product_title'
  if (/variant details is required/i.test(message)) return 'parsing_variant_details'
  if (/quantity must be a positive integer/i.test(message)) return 'parsing_quantity'
  if (/order card has no items/i.test(message)) return 'parsing_empty_order'
  if (/order tab status is invalid|order list is unavailable/i.test(message)) return 'parsing_order_list'

  return 'unexpected'
}

export async function runOrderScrape(config, dependencies = {}) {
  const now = dependencies.now ?? (() => new Date())
  const launchContext = dependencies.launchContext ?? launchPersistentContext
  const sendRun = dependencies.postRun ?? postOrderRun
  const openTab = dependencies.openTab ?? openOrderTab
  const advancePage = dependencies.advancePage ?? advanceOrderPage
  const startedAt = now().toISOString()
  let context
  let stage = 'launch_browser'

  try {
    context = await launchContext(config)
    stage = 'open_browser_pages'
    const page = await context.newPage()
    const detailPage = await context.newPage()
    stage = 'open_order_list'
    await page.goto(config.orderStartUrl, {
      waitUntil: 'domcontentloaded',
      timeout: config.timeoutMs
    })

    const items = []
    const seen = new Set()

    for (const tab of ORDER_TAB_SEQUENCE) {
      stage = 'open_order_tab'
      await openTab(page, tab, config)

      do {
        stage = 'read_order_list'
        const document = parseHTML(await page.content()).document
        if (detectOrderPageState(document) === 'needs_login') {
          stage = 'record_result'
          await sendRun(config, terminalPayload('needs_login', startedAt, now, 'Login Gita diperlukan.'))

          return { status: 'needs_login', itemCount: 0 }
        }

        stage = 'parse_order_list'
        const candidates = extractOrderCandidates(document, tab.status)
        stage = 'open_order_detail'
        const detailRows = await resolveDetailRows(detailPage, page, candidates, config)
        if (detailRows === 'needs_login') {
          stage = 'record_result'
          await sendRun(config, terminalPayload('needs_login', startedAt, now, 'Login Gita diperlukan.'))

          return { status: 'needs_login', itemCount: 0 }
        }

        const rows = detailRows
        for (const row of rows) {
          const key = [row.sellerOrderId, row.sellerSku, row.variantLabel].join('\u0000')
          if (seen.has(key)) throw new Error('Duplicate Gita order item.')

          seen.add(key)
          items.push({
            seller_order_id: row.sellerOrderId,
            tab_status: row.tabStatus,
            seller_sku: row.sellerSku,
            product_title: row.productTitle,
            variant_label: row.variantLabel,
            quantity: row.quantity,
            captured_at: now().toISOString()
          })
        }
        stage = 'advance_order_page'
      } while (await advancePage(page, config))
    }

    stage = 'record_result'
    await sendRun(config, {
      status: 'success',
      started_at: startedAt,
      finished_at: now().toISOString(),
      items
    })

    return { status: 'success', itemCount: items.length }
  } catch (error) {
    const classifiedReason = orderScrapeFailureReason(error)
    const reason = classifiedReason === 'unexpected' ? `unexpected_${stage}` : classifiedReason

    try {
      await sendRun(config, terminalPayload('failed', startedAt, now, 'Pengambilan pesanan Gita gagal.'))
    } catch {
      // The terminal result remains failed when local delivery is unavailable.
    }

    return { status: 'failed', itemCount: 0, reason }
  } finally {
    await context?.close()
  }
}

async function resolveDetailRows(detailPage, listPage, candidates, config) {
  const candidatesByDetailUrl = new Map()

  for (const candidate of candidates) {
    const group = candidatesByDetailUrl.get(candidate.detailUrl) ?? []
    group.push(candidate)
    candidatesByDetailUrl.set(candidate.detailUrl, group)
  }

  const rows = []
  for (const [detailUrl, detailCandidates] of candidatesByDetailUrl) {
    const detailDocument = await loadOrderDetail(detailPage, listPage, detailUrl, config)
    if (detectOrderPageState(detailDocument) === 'needs_login') return 'needs_login'

    const sellerSkus = extractDetailSellerSkus(detailDocument)
    if (sellerSkus.length !== detailCandidates.length) {
      throw new Error('Detail order item count does not match the order list.')
    }

    rows.push(...detailCandidates.map((candidate, index) => ({
      sellerOrderId: candidate.sellerOrderId,
      tabStatus: candidate.tabStatus,
      sellerSku: sellerSkus[index],
      productTitle: candidate.productTitle,
      variantLabel: candidate.variantLabel,
      quantity: candidate.quantity
    })))
  }

  return rows
}

async function loadOrderDetail(detailPage, listPage, detailUrl, config) {
  const listUrl = new URL(listPage.url())
  const url = new URL(detailUrl, listUrl)
  if (url.origin !== listUrl.origin || !/^\/portal\/sale\/order\/[^/]+$/.test(url.pathname)) {
    throw new Error('Order detail URL is invalid.')
  }

  await detailPage.goto(url.href, {
    waitUntil: 'domcontentloaded',
    timeout: config.timeoutMs
  })
  await waitForOrderDetailContent(detailPage, config.timeoutMs)

  return parseHTML(await detailPage.content()).document
}

export async function waitForOrderDetailContent(page, timeoutMs) {
  return page.waitForFunction(() => {
    const text = document.body?.innerText
      || document.body?.textContent
      || document.documentElement?.innerText
      || document.documentElement?.textContent
      || ''

    return /Kode\s+Variasi\s*:/i.test(text)
      || document.querySelector('input[type=password], [data-testid*=login], [data-testid*=verification]') !== null
  }, { timeout: timeoutMs })
}

async function launchPersistentContext(config) {
  return chromium.launchPersistentContext(config.profileDir, {
    headless: config.headless
  })
}

export async function openOrderTab(page, tab, config) {
  const selector = sellerCentreTabSelector(tab.testId)
  const label = page.locator(selector)
  const wasActive = await label.locator('..').evaluate((element) => element.classList.contains('active'))
  const previousFingerprint = await orderContentFingerprint(page)

  if (!wasActive) {
    await label.click({ timeout: config.timeoutMs })
  }

  await page.waitForFunction((tabSelector) => {
    const labelElement = document.querySelector(tabSelector)
    return labelElement?.parentElement?.classList.contains('active') === true
  }, selector, { timeout: config.timeoutMs })

  await waitForPageOrderContent(page, previousFingerprint, !wasActive, config)
}

export async function advanceOrderPage(page, config) {
  const next = page.locator(NEXT_PAGE_SELECTOR)
  const count = await next.count()

  if (count === 0) return false
  if (await next.isDisabled()) return false

  const previousFingerprint = await orderContentFingerprint(page)
  await next.click({ timeout: config.timeoutMs })
  await waitForPageOrderContent(page, previousFingerprint, true, config)
  return true
}

async function orderContentFingerprint(page) {
  return (await page.locator(ORDER_NUMBER_SELECTOR).allTextContents())
    .map((value) => value.trim())
    .join('\u0000')
}

async function waitForPageOrderContent(page, previousFingerprint, requireTransition, config) {
  return waitForOrderContent({
    readState: () => page.evaluate(({ tableSelector, orderNumberSelector, emptySelector }) => {
      const table = document.querySelector(tableSelector)
      if (!table) return { hasEmptyState: false, hasTable: false, fingerprint: '' }

      return {
        hasEmptyState: table.querySelector(emptySelector) !== null,
        hasTable: true,
        fingerprint: Array.from(table.querySelectorAll(orderNumberSelector))
          .map((element) => element.textContent?.trim() ?? '')
          .join('\u0000')
      }
    }, {
      tableSelector: ORDER_TABLE_SELECTOR,
      orderNumberSelector: '.order-sn',
      emptySelector: EMPTY_ORDER_SELECTOR
    }),
    wait: () => page.waitForTimeout(100)
  }, previousFingerprint, requireTransition, config.timeoutMs)
}

export async function waitForOrderContent(dependencies, previousFingerprint, requireTransition, timeoutMs) {
  const now = dependencies.now ?? Date.now
  const deadline = now() + timeoutMs

  while (now() <= deadline) {
    const state = await dependencies.readState()
    const ready = state.hasTable && (requireTransition
      ? orderContentTransitioned(previousFingerprint, state.fingerprint, state.hasEmptyState)
      : state.fingerprint !== '' || state.hasEmptyState)

    if (ready) return

    await dependencies.wait()
  }

  throw new Error('Order content did not become ready before timeout.')
}

function terminalPayload(status, startedAt, now, message) {
  return {
    status,
    started_at: startedAt,
    finished_at: now().toISOString(),
    message
  }
}

async function main() {
  const result = await runOrderScrape(loadOrderWorkerConfig())
  const reason = result.reason ? `; reason=${result.reason}` : ''
  console.log(`Gita order scraper: ${result.status}; items=${result.itemCount}${reason}`)
  process.exitCode = result.status === 'success' ? 0 : 1
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  main()
}
