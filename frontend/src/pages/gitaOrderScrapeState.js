const MATCH_STATUS_LABELS = {
  matched: 'Cocok',
  unmatched: 'Tidak ditemukan di Stock Master',
  duplicate_master_sku: 'SKU Stock Master ganda'
}

const TAB_STATUS_LABELS = {
  to_ship: 'Perlu Dikirim',
  shipped: 'Dikirim'
}

export function buildGitaOrderScrapeQuery({ matchStatus, tabStatus, page } = {}) {
  const query = {}

  if (Object.hasOwn(MATCH_STATUS_LABELS, matchStatus)) {
    query.match_status = matchStatus
  }

  if (Object.hasOwn(TAB_STATUS_LABELS, tabStatus)) {
    query.tab_status = tabStatus
  }

  if (Number.isInteger(page) && page >= 1) {
    query.page = page
  }

  return query
}

export function gitaOrderMatchStatusLabel(status) {
  return MATCH_STATUS_LABELS[status] || 'Status tidak dikenal'
}

export function gitaOrderTabStatusLabel(status) {
  return TAB_STATUS_LABELS[status] || 'Status tidak dikenal'
}
