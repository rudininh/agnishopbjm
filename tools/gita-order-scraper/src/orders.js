const LOGIN_SELECTOR = 'input[type=password], [data-testid*=login], [data-testid*=verification]'
const ORDER_PAGE_SELECTOR = '[data-testid=order-list-table-skeleton], .order-list-table-shipment'
const ORDER_CARD_SELECTOR = '[data-testid=order-item]'
const ORDER_ITEM_SELECTOR = '.item'
const NEXT_PAGE_SELECTOR = '.eds-pager__button-next'
const TAB_STATUSES = new Set(['to_ship', 'shipped', 'completed'])

export function detectOrderPageState(document) {
  if (document.querySelector(LOGIN_SELECTOR)) {
    return 'needs_login'
  }

  return document.querySelector(ORDER_PAGE_SELECTOR) ? 'orders' : 'invalid'
}

export function extractOrderItems(document, tabStatus) {
  if (!TAB_STATUSES.has(tabStatus)) {
    throw new Error('Order tab status is invalid.')
  }

  if (detectOrderPageState(document) !== 'orders') {
    throw new Error('Order list is unavailable.')
  }

  const seen = new Set()

  return [...document.querySelectorAll(ORDER_CARD_SELECTOR)].flatMap((card) => {
    const items = [...card.querySelectorAll(ORDER_ITEM_SELECTOR)]

    if (items.length === 0) {
      throw new Error('Order card has no items.')
    }

    return items.flatMap((item) => {
      const variantDetails = requiredText(item, '.item-description', 'variant details')
      const sellerSku = extractSellerSku(variantDetails)

      if (sellerSku === null) return []

      const sellerOrderId = sellerOrderIdFrom(requiredText(card, '.order-sn', 'seller order ID'))
      const productTitle = requiredText(item, '.item-name', 'product title')
      const variantLabel = extractVariantLabel(variantDetails)
      const quantity = positiveInteger(requiredText(item, '.item-amount', 'quantity'))
      const key = [sellerOrderId, sellerSku, variantLabel].join('\u0000')

      if (seen.has(key)) {
        throw new Error('duplicate order item')
      }

      seen.add(key)

      return [{
        sellerOrderId,
        tabStatus,
        sellerSku,
        productTitle,
        variantLabel,
        quantity
      }]
    })
  })
}

export function extractOrderCandidates(document, tabStatus) {
  if (!TAB_STATUSES.has(tabStatus)) {
    throw new Error('Order tab status is invalid.')
  }

  if (detectOrderPageState(document) !== 'orders') {
    throw new Error('Order list is unavailable.')
  }

  return [...document.querySelectorAll(ORDER_CARD_SELECTOR)].flatMap((card) => {
    const sellerOrderId = sellerOrderIdFrom(requiredText(card, '.order-sn', 'seller order ID'))
    const detailUrl = orderDetailUrlFrom(card)
    const items = [...card.querySelectorAll(ORDER_ITEM_SELECTOR)]

    if (items.length === 0) {
      throw new Error('Order card has no items.')
    }

    return items.map((item) => {
      const variantDetails = requiredText(item, '.item-description', 'variant details')

      return {
        sellerOrderId,
        tabStatus,
        detailUrl,
        productTitle: requiredText(item, '.item-name', 'product title'),
        variantLabel: extractVariantLabel(variantDetails),
        quantity: positiveInteger(requiredText(item, '.item-amount', 'quantity'))
      }
    })
  })
}

export function extractDetailSellerSkus(document) {
  const text = String(document.body?.textContent || document.documentElement?.textContent || '')
  const skus = [...text.matchAll(/Kode\s+Variasi\s*:\s*(INT-[A-Za-z0-9][A-Za-z0-9._-]*)/gi)]
    .map((match) => withoutRenderedPrice(match[1]))

  if (skus.length === 0) {
    throw new Error('Detail seller SKU is unavailable.')
  }

  return skus
}

export function hasNextOrderPage(document) {
  const next = document.querySelector(NEXT_PAGE_SELECTOR)

  return next !== null
    && !next.hasAttribute('disabled')
    && next.getAttribute('aria-disabled') !== 'true'
    && ![...next.classList].some((className) => className.includes('disabled'))
}

export function extractSellerSku(value) {
  const matches = [...new Set(String(value).match(/\bINT-[A-Za-z0-9][A-Za-z0-9._-]*/g) ?? [])]

  if (matches.length === 0) return null
  if (matches.length > 1) throw new Error('seller SKU is ambiguous')

  return withoutRenderedPrice(matches[0])
}

function withoutRenderedPrice(value) {
  return String(value).replace(/\d{1,3}\.\d{3}(?:,\d{2})?$/, '')
}

function sellerOrderIdFrom(value) {
  const match = String(value).match(/^No\.?\s*Pesanan\s*:?[\s]+([^\s]+)\s*$/i)

  if (!match) {
    throw new Error('seller order ID is required')
  }

  return match[1]
}

function orderDetailUrlFrom(card) {
  const links = [
    ...(card.matches('a[href]') ? [card] : []),
    ...card.querySelectorAll('a[href]')
  ]
  const urls = [...new Set(links
    .map((link) => link.getAttribute('href')?.trim() ?? '')
    .filter((href) => /\/portal\/sale\/order\/[^/?#]+/.test(href)))]

  if (urls.length !== 1) {
    throw new Error('Order detail URL is required.')
  }

  return urls[0]
}

function extractVariantLabel(value) {
  const match = String(value).match(/^\s*Variasi:\s*(.*?)(?:\s*\[|$)/i)

  return match?.[1]?.trim() || ''
}

function requiredText(row, selector, field) {
  const value = String(row.querySelector(selector)?.textContent ?? '').trim()

  if (value === '') {
    throw new Error(`${field} is required`)
  }

  return value
}

function positiveInteger(value) {
  const match = String(value).trim().match(/^(?:x\s*)?([1-9]\d*)$/i)

  if (!match) {
    throw new Error('quantity must be a positive integer')
  }

  return Number(match[1])
}
