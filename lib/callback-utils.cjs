const callbackPaths = {
  shopee: '/api/shopee/callback',
  tiktok: '/api/tiktok/callback'
}

const callbackOverrides = {
  shopee: 'GITASHOP_SHOPEE_CALLBACK_URL',
  tiktok: 'GITASHOP_TIKTOK_CALLBACK_URL'
}

const failure = (status, error) => ({ ok: false, status, error })

function buildCallbackTarget({ channel, query = {}, env = {} }) {
  const callbackPath = callbackPaths[channel]
  const overrideKey = callbackOverrides[channel]
  const configuredTarget = overrideKey ? env[overrideKey] || env.GITASHOP_BACKEND_URL : ''

  if (!configuredTarget) {
    return failure(503, 'Gitashop backend callback is not configured')
  }

  let url
  try {
    url = new URL(configuredTarget)
    if (!overrideKey || !env[overrideKey]) {
      url.pathname = `${url.pathname.replace(/\/$/, '')}${callbackPath}`
    }
  } catch {
    return failure(500, 'Gitashop backend callback configuration is invalid')
  }

  for (const [key, value] of Object.entries(query)) {
    if (Array.isArray(value)) {
      value.forEach((item) => {
        if (item !== undefined && item !== null) {
          url.searchParams.append(key, String(item))
        }
      })
    } else if (value !== undefined && value !== null) {
      url.searchParams.append(key, String(value))
    }
  }

  return { ok: true, url: url.toString() }
}

module.exports = { buildCallbackTarget }