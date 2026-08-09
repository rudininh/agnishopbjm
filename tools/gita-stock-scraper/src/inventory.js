const LOGIN_SELECTOR = '[data-gita-login], [data-gita-verification]'
const INVENTORY_SELECTOR = '[data-gita-inventory-table]'
const INVENTORY_ROW_SELECTOR = '[data-gita-inventory-row]'

export function detectInventoryState(document) {
  if (document.querySelector(LOGIN_SELECTOR)) {
    return 'needs_login'
  }

  return document.querySelector(INVENTORY_SELECTOR) ? 'inventory' : 'invalid'
}

export function extractInventoryRows(document) {
  if (detectInventoryState(document) !== 'inventory') {
    throw new Error('Inventory table is unavailable.')
  }

  const rows = [...document.querySelectorAll(INVENTORY_ROW_SELECTOR)]
  if (rows.length === 0) {
    throw new Error('Inventory table has no rows.')
  }

  const seenSkus = new Set()

  return rows.map((row) => {
    const sku = textValue(row, '[data-gita-sku]')
    if (sku === '') {
      throw new Error('SKU is required for every inventory row.')
    }

    const stock = nonNegativeInteger(textValue(row, '[data-gita-stock]'))
    if (seenSkus.has(sku)) {
      throw new Error(`duplicate SKU: ${sku}`)
    }

    seenSkus.add(sku)

    return {
      sku,
      stock,
      gitaProductId: optionalAttribute(row, 'data-gita-product-id'),
      gitaVariantId: optionalAttribute(row, 'data-gita-variant-id')
    }
  })
}

function textValue(row, selector) {
  return String(row.querySelector(selector)?.textContent ?? '').trim()
}

function nonNegativeInteger(value) {
  if (!/^(0|[1-9]\d*)$/.test(value)) {
    throw new Error('stock must be a non-negative integer.')
  }

  return Number(value)
}

function optionalAttribute(row, name) {
  const value = String(row.getAttribute(name) ?? '').trim()

  return value === '' ? null : value
}
