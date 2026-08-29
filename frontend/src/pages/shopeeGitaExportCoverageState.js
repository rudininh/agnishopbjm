const numberFormatter = new Intl.NumberFormat('id-ID')

const count = (value) => {
  const normalized = Number(value)

  return Number.isFinite(normalized) && normalized > 0 ? normalized : 0
}

const text = (value) => typeof value === 'string' ? value : ''

const normalizedExceptionItem = (item) => ({
  status: text(item?.status),
  reason: text(item?.reason),
  product_name: text(item?.product_name),
  variant_name: text(item?.variant_name),
  source_seller_sku: text(item?.source_seller_sku)
})

const filenameFromContentDisposition = (headers) => {
  const contentDisposition = headers?.['content-disposition'] || headers?.['Content-Disposition']

  if (typeof contentDisposition !== 'string') return ''

  const extended = contentDisposition.match(/(?:^|;)\s*filename\*\s*=\s*(?:\"([^\"]*)\"|([^;]*))/i)
  if (extended) {
    const encoded = (extended[1] ?? extended[2] ?? '').trim().replace(/^[^']*'[^']*'/, '')
    try {
      const filename = decodeURIComponent(encoded)
      if (filename) return filename
    } catch {
      // A malformed optional RFC 5987 value must not prevent the normal filename fallback.
    }
  }

  const standard = contentDisposition.match(/(?:^|;)\s*filename\s*=\s*(?:\"([^\"]*)\"|([^;]*))/i)

  return (standard?.[1] ?? standard?.[2] ?? '').trim()
}

const fallbackFilename = (kind) => {
  const normalizedKind = typeof kind === 'string' && kind.trim() ? kind.trim() : 'download'
  const extension = normalizedKind === 'mass-update'
    ? 'zip'
    : normalizedKind === 'exceptions'
      ? 'csv'
      : 'xlsx'

  return `shopee_gita_${normalizedKind}.${extension}`
}

export const toShopeeGitaCoverageViewModel = (payload) => {
  const snapshot = payload && typeof payload === 'object' ? payload : {}
  const summary = snapshot.summary && typeof snapshot.summary === 'object' ? snapshot.summary : {}
  const revision = text(snapshot.revision).trim()
  const readyProducts = count(summary.ready_products)
  const readyVariants = count(summary.ready_variants)
  const exceptionVariants = count(summary.exception_variants)
  const templateLastModifiedAt = text(snapshot.template?.sales_last_modified_at)
  const exceptionItems = (Array.isArray(snapshot.items) ? snapshot.items : [])
    .filter((item) => item?.status !== 'mass_update_ready')
    .map(normalizedExceptionItem)

  return {
    revision,
    sourceProducts: count(summary.source_products),
    sourceVariants: count(summary.source_variants),
    readyProducts,
    readyVariants,
    exceptionVariants,
    variantsByStatus: summary.variants_by_status && typeof summary.variants_by_status === 'object'
      ? summary.variants_by_status
      : {},
    isPartial: exceptionVariants > 0,
    canDownloadMassUpdate: Boolean(revision),
    canDownloadExceptions: Boolean(revision) && exceptionVariants > 0,
    readyLabel: `${numberFormatter.format(readyProducts)} produk / ${numberFormatter.format(readyVariants)} varian`,
    exceptionLabel: `${numberFormatter.format(exceptionVariants)} varian perlu tindakan`,
    templateLastModifiedAt,
    templateAgeLabel: templateLastModifiedAt || 'Waktu template tidak tersedia',
    items: exceptionItems
  }
}

export const filterShopeeGitaExceptions = (items, keyword) => {
  const search = text(keyword).trim().toLocaleLowerCase('id-ID')
  const exceptions = Array.isArray(items) ? items : []

  if (!search) return exceptions

  return exceptions.filter((item) => [
    item?.product_name,
    item?.variant_name,
    item?.source_seller_sku,
    item?.status,
    item?.reason
  ].map(text).join(' ').toLocaleLowerCase('id-ID').includes(search))
}

export const coverageDownloadFilename = (kind, headers = {}) => (
  filenameFromContentDisposition(headers) || fallbackFilename(kind)
)
