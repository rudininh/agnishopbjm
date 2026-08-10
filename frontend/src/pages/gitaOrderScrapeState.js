const MATCH_STATUS_LABELS = {
  matched: 'Cocok',
  unmatched: 'Tidak ditemukan di Stock Master',
  duplicate_master_sku: 'SKU Stock Master ganda'
}

const TAB_STATUS_LABELS = {
  to_ship: 'Perlu Dikirim',
  shipped: 'Dikirim'
}

const SYNC_STATUS_LABELS = {
  pending: 'Belum Disinkronkan',
  processing: 'Sedang Diproses',
  synced: 'Sudah Disinkronkan',
  failed: 'Gagal Disinkronkan',
  blocked: 'Tidak Dapat Disinkronkan'
}

export const dailyGitaCollectorCommand = `Set-Location C:\\laragon\\www\\agnishopbjm-laravel
$env:GITA_ORDER_SCRAPER_API_BASE_URL='http://agnishopbjm-laravel.test/api'
$env:GITA_ORDER_SCRAPER_PROFILE_DIR='tools/gita-order-scraper/.profile'
$env:GITA_ORDER_SCRAPER_HEADLESS='false'
npm run gita-order-scrape`

export const gitaCollectorCalibrationCommand = `Set-Location C:\\laragon\\www\\agnishopbjm-laravel
npm run gita-order-calibrate`

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

export function gitaOrderSyncStatusLabel(status) {
  return SYNC_STATUS_LABELS[status] || 'Status tidak dikenal'
}

export function canSyncGitaOrder(item) {
  return item?.match_status === 'matched' && ['pending', 'failed'].includes(item?.sync_status)
}

export function gitaOrderSyncActionLabel(item) {
  return item?.sync_status === 'failed' ? 'Coba Lagi' : 'Sinkronkan'
}
