import { chromium } from 'playwright'
import { pathToFileURL } from 'node:url'
import { loadOrderCalibrationConfig } from './config.js'

export async function openOrderCalibration(config, dependencies = {}) {
  const launchContext = dependencies.launchContext ?? launchCalibrationContext
  const waitForStop = dependencies.waitForStop ?? waitForStopSignal
  let context

  try {
    context = await launchContext(config)
    const page = context.pages()[0] ?? await context.newPage()
    await page.goto(config.orderStartUrl, {
      waitUntil: 'domcontentloaded',
      timeout: config.timeoutMs
    })
    await waitForStop()
  } finally {
    await context?.close()
  }
}

async function launchCalibrationContext(config) {
  return chromium.launchPersistentContext(config.profileDir, {
    headless: false,
    args: ['--remote-debugging-address=127.0.0.1', '--remote-debugging-port=9222']
  })
}

function waitForStopSignal() {
  return new Promise((resolve) => {
    const stop = () => {
      process.off('SIGINT', stop)
      process.off('SIGTERM', stop)
      resolve()
    }

    process.once('SIGINT', stop)
    process.once('SIGTERM', stop)
  })
}

async function main() {
  console.log('Browser kalibrasi Gita dibuka. Login sendiri lalu biarkan browser terbuka; tekan Ctrl+C setelah kalibrasi selesai.')
  await openOrderCalibration(loadOrderCalibrationConfig())
}

if (process.argv[1] && pathToFileURL(process.argv[1]).href === import.meta.url) {
  main().catch((error) => {
    console.error(error.message)
    process.exitCode = 1
  })
}
