import { defineConfig, devices } from '@playwright/test'
import { e2eBaseURL } from './playwright.target'

/**
 * Long end-to-end suite that creates a teacher, runs a real dictation correction,
 * and deletes the data afterwards. Kept out of `npm test`.
 *
 * Run from client/: npm run test:dictation
 */
export default defineConfig({
  testDir: './tests/special',
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: 0,
  workers: 1,
  timeout: 12 * 60 * 1000,
  expect: { timeout: 20_000 },
  reporter: 'html',
  use: {
    baseURL: e2eBaseURL,
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
