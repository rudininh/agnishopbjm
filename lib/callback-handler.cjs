const { buildCallbackTarget } = require('./callback-utils.cjs')

const hasCode = (query) => {
  const value = query?.code
  return Array.isArray(value) ? value.some((item) => String(item || '').trim()) : Boolean(String(value || '').trim())
}

function createCallbackHandler(channel) {
  return async function callbackHandler(req, res) {
    try {
      if (!hasCode(req.query)) {
        return res.status(400).json({ error: 'Missing code' })
      }

      const result = buildCallbackTarget({
        channel,
        query: req.query,
        env: req.env || process.env
      })

      if (!result.ok) {
        return res.status(result.status).json({ error: result.error })
      }

      return res.redirect(302, result.url)
    } catch (error) {
      console.error(`${channel} callback bridge error:`, error.message)
      return res.status(500).json({ error: 'Callback bridge failed' })
    }
  }
}

module.exports = { createCallbackHandler }