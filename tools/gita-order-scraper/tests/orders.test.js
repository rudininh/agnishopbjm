import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { parseHTML } from 'linkedom'
import { detectOrderPageState, extractDetailSellerSkus, extractOrderCandidates, extractOrderItems, extractSellerSku, hasNextOrderPage } from '../src/orders.js'

async function fixtureDocument(name) {
  const html = await readFile(new URL(`./fixtures/${name}`, import.meta.url), 'utf8')

  return parseHTML(html).document
}

test('returns needs_login for a login or verification screen', async () => {
  assert.equal(detectOrderPageState(await fixtureDocument('login.html')), 'needs_login')
})

test('extracts exact seller SKU order lines without buyer data', async () => {
  const document = await fixtureDocument('orders-page-1.html')

  assert.deepEqual(extractOrderItems(document, 'to_ship'), [
    {
      sellerOrderId: '260808T15MHC24',
      tabStatus: 'to_ship',
      sellerSku: 'INT-40908729245-SAGEE',
      productTitle: 'PARIS LEGEND HIJABERIES Segiempat',
      variantLabel: 'Sagee',
      quantity: 1
    },
    {
      sellerOrderId: '260808T15MHC24',
      tabStatus: 'to_ship',
      sellerSku: 'INT-40908729246-OLIVE.1',
      productTitle: 'PARIS LEGEND HIJABERIES Pashmina',
      variantLabel: 'Olive',
      quantity: 2
    }
  ])
  assert.equal(hasNextOrderPage(document), true)
})

test('extracts every list line and its shared detail URL before reading SKU details', () => {
  const document = parseHTML(`
    <main data-testid='order-list-table-skeleton'>
      <article data-testid='order-item'>
        <div class='order-sn'>No. Pesanan ORDER-DETAIL-1</div>
        <a href='/portal/sale/order/239965996272701'>Rincian Pesanan</a>
        <div class='item'><span class='item-name'>Produk A</span><span class='item-description'>Variasi: Sagee</span><span class='item-amount'>4</span></div>
        <div class='item'><span class='item-name'>Produk B</span><span class='item-description'>Variasi: Olive</span><span class='item-amount'>2</span></div>
      </article>
    </main>
  `).document

  assert.deepEqual(extractOrderCandidates(document, 'to_ship'), [
    {
      sellerOrderId: 'ORDER-DETAIL-1',
      tabStatus: 'to_ship',
      detailUrl: '/portal/sale/order/239965996272701',
      productTitle: 'Produk A',
      variantLabel: 'Sagee',
      quantity: 4
    },
    {
      sellerOrderId: 'ORDER-DETAIL-1',
      tabStatus: 'to_ship',
      detailUrl: '/portal/sale/order/239965996272701',
      productTitle: 'Produk B',
      variantLabel: 'Olive',
      quantity: 2
    }
  ])
})

test('extracts ordered seller SKUs from sanitized order detail labels', async () => {
  assert.deepEqual(extractDetailSellerSkus(await fixtureDocument('order-detail.html')), [
    'INT-40908729245-SAGEE',
    'INT-40908729246-OLIVE.1'
  ])
})

test('preserves the complete seller SKU token from the Seller Centre variation field', () => {
  assert.equal(
    extractSellerSku('Variasi: Olive [P40908729246 INT-40908729246-OLIVE.1]'),
    'INT-40908729246-OLIVE.1'
  )
})

test('extracts an exact seller SKU when Seller Centre renders it outside brackets', () => {
  assert.equal(
    extractSellerSku('Variasi: Olive SKU: INT-40908729246-OLIVE.1'),
    'INT-40908729246-OLIVE.1'
  )
})

test('preserves a seller SKU ending in a digit when a rendered price is adjacent', () => {
  const detail = parseHTML('<main>Kode Variasi: INT-40908729245-SAGEE27.900</main>').document

  assert.deepEqual(extractDetailSellerSkus(detail), ['INT-40908729245-SAGEE27.900'])
  assert.equal(
    extractSellerSku('Variasi: Sagee SKU: INT-24340156931-AZR-HJP-BRKWHT23.400'),
    'INT-24340156931-AZR-HJP-BRKWHT23.400'
  )
  assert.equal(
    extractSellerSku('Variasi: Blush 1 SKU: INT-40908729245-BLUSH-127.900'),
    'INT-40908729245-BLUSH-127.900'
  )
})

test('does not append a sibling rendered price to a detail seller SKU', () => {
  const detail = parseHTML('<main><div>Kode Variasi: INT-28383781340-BOX-14</div><div>41.000</div><div>Kode Variasi: INT-28383781340-BOX-18</div><div>41.000</div></main>').document

  assert.deepEqual(extractDetailSellerSkus(detail), [
    'INT-28383781340-BOX-14',
    'INT-28383781340-BOX-18'
  ])
})

test('ignores order items that do not have an AgniShop internal SKU', () => {
  const invalid = parseHTML(`
    <main data-testid='order-list-table-skeleton'>
      <a data-testid='order-item'><div class='item'><span class='item-name'>Item</span><span class='item-description'>Variasi: Sagee [P40908729245]</span><span class='item-amount'>1</span></div></a>
    </main>
  `).document

  assert.equal(extractSellerSku('Variasi: Sagee [P40908729245]'), null)
  assert.deepEqual(extractOrderItems(invalid, 'to_ship'), [])
})

test('rejects ambiguous or duplicate order items', () => {
  assert.throws(
    () => extractSellerSku('Variasi: Sagee [INT-40908729245-SAGEE INT-40908729246-OLIVE]'),
    /seller SKU is ambiguous/
  )

  const duplicate = parseHTML(`
    <main data-testid='order-list-table-skeleton'>
      <a data-testid='order-item'><div class='order-sn'>No. Pesanan 260808T15MHC24</div><div class='item'><span class='item-name'>Item</span><span class='item-description'>Variasi: Sagee [P40908729245 INT-40908729245-SAGEE]</span><span class='item-amount'>1</span></div></a>
      <a data-testid='order-item'><div class='order-sn'>No. Pesanan 260808T15MHC24</div><div class='item'><span class='item-name'>Item</span><span class='item-description'>Variasi: Sagee [P40908729245 INT-40908729245-SAGEE]</span><span class='item-amount'>1</span></div></a>
    </main>
  `).document

  assert.throws(() => extractOrderItems(duplicate, 'to_ship'), /duplicate/)

  const withoutDetailUrl = parseHTML(`
    <main data-testid='order-list-table-skeleton'>
      <article data-testid='order-item'><div class='order-sn'>No. Pesanan ORDER-DETAIL-2</div><div class='item'><span class='item-name'>Produk</span><span class='item-description'>Variasi: Sagee</span><span class='item-amount'>1</span></div></article>
    </main>
  `).document

  assert.throws(() => extractOrderCandidates(withoutDetailUrl, 'to_ship'), /detail URL/)
  assert.throws(() => extractDetailSellerSkus(parseHTML('<main>Kode Variasi: -</main>').document), /Detail seller SKU/)
})
