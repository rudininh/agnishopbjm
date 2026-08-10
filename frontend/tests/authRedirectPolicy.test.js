import test from 'node:test'
import assert from 'node:assert/strict'
import { shouldRedirectOnUnauthorized } from '../src/services/authRedirectPolicy.js'

test('keeps the global redirect unless a request explicitly opts out', () => {
  assert.equal(shouldRedirectOnUnauthorized({}), true)
  assert.equal(shouldRedirectOnUnauthorized({ skipAuthRedirect: true }), false)
})
