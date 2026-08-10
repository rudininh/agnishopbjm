import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'

test('exposes only the Gita order collector root commands', async () => {
  const packageJson = JSON.parse(await readFile(new URL('../../../package.json', import.meta.url), 'utf8'))

  assert.equal(packageJson.scripts['gita-order-scrape'], 'node tools/gita-order-scraper/src/cli.js')
  assert.equal(packageJson.scripts['gita-order-calibrate'], 'node tools/gita-order-scraper/src/calibrate.js')
  assert.equal(packageJson.scripts['test:gita-order-scraper'], 'node --test tools/gita-order-scraper/tests/*.test.js')
  assert.equal(packageJson.scripts['gita-stock-scrape'], undefined)
  assert.equal(packageJson.scripts['test:gita-stock-scraper'], undefined)
})
