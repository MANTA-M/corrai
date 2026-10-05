import fs from 'node:fs'

/**
 * E2E hits the app that is already running, not the Vite dev server.
 * On the test server (host nginx for mantam.eu), that is the server itself.
 * On a dev machine, that is the Docker nginx published on port 80, at the site root.
 * The /corrai_test/ prefix is only the public root on the test server.
 */
const onTestServer = fs.existsSync('/etc/nginx/sites-enabled/mantam')

export const e2eBaseURL =
  process.env.PLAYWRIGHT_BASE_URL ??
  (onTestServer ? 'https://mantam.eu/corrai_test/' : 'http://127.0.0.1/')
