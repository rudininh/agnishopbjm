const REQUEST_TIMEOUT_MS = 30000

export async function postOrderRun(config, payload, fetchImpl = fetch) {
  let response

  try {
    response = await fetchImpl(`${config.apiBaseUrl}/gita-order-scrapes/runs`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${config.ingestToken}`,
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      body: JSON.stringify(payload),
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS)
    })
  } catch {
    throw new Error('Unable to record Gita order scraper run.')
  }

  if (!response.ok) {
    throw new Error(`Gita order run request failed (${response.status}).`)
  }

  if (!response.headers.get('content-type')?.includes('application/json')) {
    return {}
  }

  try {
    return await response.json()
  } catch {
    throw new Error('Unable to record Gita order scraper run.')
  }
}

export async function claimOrderScraperLease(config, fetchImpl = fetch) {
  return workerLeaseRequest(config, '/gita-order-scrapes/worker/lease', undefined, fetchImpl)
}

export async function renewOrderScraperLease(config, leaseToken, fetchImpl = fetch) {
  return workerLeaseRequest(config, '/gita-order-scrapes/worker/lease/renew', leaseToken, fetchImpl)
}

export async function releaseOrderScraperLease(config, leaseToken, fetchImpl = fetch) {
  return workerLeaseRequest(config, '/gita-order-scrapes/worker/lease/release', leaseToken, fetchImpl)
}

async function workerLeaseRequest(config, pathname, leaseToken, fetchImpl) {
  let response

  try {
    response = await fetchImpl(`${config.apiBaseUrl}${pathname}`, {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${config.ingestToken}`,
        'Content-Type': 'application/json',
        Accept: 'application/json'
      },
      ...(leaseToken ? { body: JSON.stringify({ lease_token: leaseToken }) } : {}),
      signal: AbortSignal.timeout(REQUEST_TIMEOUT_MS)
    })
  } catch {
    throw new Error('Unable to manage Gita order scraper lease.')
  }

  const payload = await workerLeasePayload(response)
  if (response.ok) return payload

  if ((response.status === 409 || response.status === 423) && ['already_running', 'marketplace_busy'].includes(payload?.status)) {
    return payload
  }

  throw new Error(`Gita order scraper lease request failed (${response.status}).`)
}

async function workerLeasePayload(response) {
  if (!response.headers.get('content-type')?.includes('application/json')) return {}

  try {
    return (await response.json()).data ?? {}
  } catch {
    throw new Error('Unable to manage Gita order scraper lease.')
  }
}
