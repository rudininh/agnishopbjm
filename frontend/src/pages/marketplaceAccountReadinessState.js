const STATE_LABELS = {
  disabled: 'Dinonaktifkan',
  waiting_credentials: 'Menunggu kredensial',
  authorization_required: 'Perlu otorisasi',
  token_expired: 'Token kedaluwarsa',
  mapping_required: 'Perlu mapping SKU',
  ready: 'Siap sinkronisasi'
}

const CHANNEL_LABELS = {
  shopee: 'Shopee',
  tiktok: 'TikTok Shop'
}

const CHECK_FIELDS = [
  ['credentials', 'credentials'],
  ['active_token', 'activeToken'],
  ['shop_identity', 'shopIdentity'],
  ['token_usable', 'tokenUsable'],
  ['warehouse', 'warehouse'],
  ['mappings', 'mappings']
]

const toSafeString = (value) => typeof value === 'string' ? value : ''
const badgeClassFor = (state) => state === 'ready' ? 'success' : state === 'disabled' ? 'neutral' : 'error'

const normalizeChecks = (checks) => Object.fromEntries(
  CHECK_FIELDS.map(([source, target]) => [target, checks?.[source] === true])
)

export const normalizeMarketplaceAccounts = (rows) => {
  if (!Array.isArray(rows)) return []

  return rows.map((row) => {
    const state = toSafeString(row?.state)

    return {
      key: toSafeString(row?.key),
      name: toSafeString(row?.name),
      channel: toSafeString(row?.channel),
      channelLabel: CHANNEL_LABELS[row?.channel] || 'Marketplace',
      state,
      stateLabel: STATE_LABELS[state] || 'Status belum diketahui',
      badgeClass: badgeClassFor(state),
      message: toSafeString(row?.message),
      checks: normalizeChecks(row?.checks),
      mappedSkus: Math.max(0, Number.isFinite(Number(row?.mapped_skus)) ? Math.trunc(Number(row.mapped_skus)) : 0),
      connectAction: toSafeString(row?.connect_action),
      requiredEnv: state === 'waiting_credentials' && Array.isArray(row?.required_env)
        ? row.required_env.filter((name) => typeof name === 'string')
        : []
    }
  })
}

export const canAuthorizeAccount = (account) => (
  account?.channel === 'shopee'
  && account?.checks?.credentials === true
  && (account?.state === 'authorization_required' || account?.state === 'token_expired')
)
