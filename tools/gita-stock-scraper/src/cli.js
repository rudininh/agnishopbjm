import { chromium } from 'playwright'
import { parseHTML } from 'linkedom'
import { pathToFileURL } from 'node:url'
import { loadWorkerConfig } from './config.js'
import { postRun } from './client.js'
import { detectInventoryState, extractInventoryRows } from './inventory.js'

export async function runScrape(config, dependencies = {}) {
  const now = dependencies.now ?? (() => new Date())
  const launchContext = dependencies.launchContext ?? launchPersistentContext
  const sendRun = dependencies.postRun ?? postRun
  const startedAt = now().toISOString()
  let context

  try {
    context = await launchContext(config)
    const page = await context.newPage()
    await page.goto(config.inventoryUrl, {
      waitUntil: 'domcontentloaded',
      timeout: config.timeoutMs
    })

    const document = parseHTML(await page.content()).document
    if (detectInventoryState(document) === 'needs_login') {
      await sendRun(config, terminalPayload('needs_login', startedAt, now, 'Login Gita diperlukan.'))

      return { status: 'needs_login', itemCount: 0 }
    }

    const capturedAt = now().toISOString()
    const items = extractInventoryRows(document).map((row) => ({
      sku: row.sku,
      stock: row.stock,
      gita_product_id: row.gitaProductId,
      gita_variant_id: row.gitaVariantId,
      captured_at: capturedAt
    }))

    await sendRun(config, {
      status: 'success',
      started_at: startedAt,
      finished_at: now().toISOString(),
      items
    })

    return { status: 'success', itemCount: items.length }
  } catch {
    try {
      await sendRun(config, terminalPayload('failed', startedAt, now, 'Pengambilan stok Gita gagal.'))
    } catch {
      // The terminal result remains failed even when the backend is unreachable.
    }

    return { status: 'failed', itemCount: 0 }
  } finally {
    await context?.close()
  }
}

async function launchPersistentContext(config) {
  return chromium.launchPersistentContext(config.profileDir, {
    headless: config.headless
  })
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
  const result = await runScrape(loadWorkerConfig())
  console.log(`Gita stock scraper: ${result.status}; items=${result.itemCount}`)
  process.exitCode = result.status === 'success' ? 0 : 1
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  main()
}
