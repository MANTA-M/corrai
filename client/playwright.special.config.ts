import { defineConfig, devices } from '@playwright/test'

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
    baseURL: 'http://localhost:5173/corrai_test/',
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
  webServer: {
    command: 'npm run dev',
    url: 'http://localhost:5173/corrai_test/',
    reuseExistingServer: !process.env.CI,
    timeout: 120_000,
  },
})
