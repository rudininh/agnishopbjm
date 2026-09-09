const CHANNEL_LABELS = { shopee: 'Shopee', tiktok: 'TikTok' }

export function normalizeMarketplaceAccount(account = {}) {
  const accountKey = String(account.account_key || account.key || '').trim()
  const channel = String(account.channel || '').trim().toLowerCase()
  return {
    key: accountKey,
    account_key: accountKey,
    name: String(account.name || accountKey || 'Akun Marketplace'),
    channel,
    channelLabel: CHANNEL_LABELS[channel] || channel || 'Marketplace',
    enabled: account.enabled !== false,
    credentialsConfigured: Boolean(account.credentials_configured),
    usesPrimaryApp: Boolean(account.uses_primary_app),
    connectAction: String(account.connect_action || ''),
    requiredEnv: Array.isArray(account.required_env) ? [...account.required_env] : []
  }
}

export function normalizeMarketplaceAccounts(payload = {}) {
  const rows = Array.isArray(payload) ? payload : payload.data?.accounts || payload.accounts || []
  return rows.map(normalizeMarketplaceAccount).filter((account) => account.key)
}

export function emptyMarketplaceAccountForm() {
  return {
    account_key: '',
    name: '',
    channel: 'shopee',
    enabled: true,
    credentials: {
      partner_id: '', partner_key: '', host: '', redirect_url: '',
      app_key: '', app_secret: '', auth_host: '', api_host: '', warehouse_id: ''
    }
  }
}

export function accountPayload(form) {
  const credentials = form.channel === 'shopee'
    ? ['partner_id', 'partner_key', 'host', 'redirect_url']
    : ['app_key', 'app_secret', 'auth_host', 'api_host', 'redirect_url', 'warehouse_id']
  const payload = {
    account_key: String(form.account_key || '').trim().toLowerCase(),
    name: String(form.name || '').trim(),
    channel: form.channel,
    enabled: Boolean(form.enabled)
  }
  const credentialPayload = Object.fromEntries(credentials
      .filter((key) => String(form.credentials?.[key] ?? '').trim() !== '')
      .map((key) => [key, form.credentials[key]]))
  if (Object.keys(credentialPayload).length) payload.credentials = credentialPayload
  return payload
}

export { CHANNEL_LABELS }
