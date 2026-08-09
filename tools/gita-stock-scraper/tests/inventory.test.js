import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { parseHTML } from 'linkedom'
import { detectInventoryState, extractInventoryRows } from '../src/inventory.js'

async function fixtureDocument(name) {
  const html = await readFile(new URL(`./fixtures/${name}`, import.meta.url), 'utf8')

  return parseHTML(html).document
}

test('returns needs_login for a login or verification screen', async () => {
  const document = await fixtureDocument('login.html')

  assert.equal(detectInventoryState(document), 'needs_login')
})

test('extracts trimmed SKU, integer stock, and visible IDs from inventory rows', async () => {
  const rows = extractInventoryRows(await fixtureDocument('inventory.html'))

  assert.deepEqual(rows, [{
    sku: 'GITA-RED-S',
    stock: 12,
    gitaProductId: '1001',
    gitaVariantId: '2001'
  }])
})

test('rejects blank SKUs, invalid stock, and duplicate SKUs', () => {
  assert.throws(
    () => extractInventoryRows(parseHTML(`
      <section data-gita-inventory-table>
        <article data-gita-inventory-row data-gita-product-id="1001" data-gita-variant-id="2001">
          <span data-gita-sku></span><span data-gita-stock>12</span>
        </article>
      </section>
    `).document),
    /SKU/
  )

  assert.throws(
    () => extractInventoryRows(parseHTML(`
      <section data-gita-inventory-table>
        <article data-gita-inventory-row><span data-gita-sku>GITA-RED-S</span><span data-gita-stock>-1</span></article>
      </section>
    `).document),
    /stock/
  )

  assert.throws(
    () => extractInventoryRows(parseHTML(`
      <section data-gita-inventory-table>
        <article data-gita-inventory-row><span data-gita-sku>GITA-RED-S</span><span data-gita-stock>12</span></article>
        <article data-gita-inventory-row><span data-gita-sku>GITA-RED-S</span><span data-gita-stock>9</span></article>
      </section>
    `).document),
    /duplicate/
  )
})
