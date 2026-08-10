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
