import { VitePWA } from 'vite-plugin-pwa'

export function createPwaPlugin() {
  return VitePWA({
    strategies: 'injectManifest',
    srcDir: 'src',
    filename: 'service-worker.js',
    injectRegister: false,
    registerType: 'autoUpdate',
    includeAssets: ['pwa-192x192.png', 'pwa-512x512.png'],
    manifest: {
      name: 'Corrai',
      short_name: 'Corrai',
      description: 'Corrai assessment management',
      theme_color: '#ffffff',
      background_color: '#ffffff',
      display: 'standalone',
      start_url: '.',
      scope: '.',
      icons: [
        {
          src: 'pwa-192x192.png',
          sizes: '192x192',
          type: 'image/png',
        },
        {
          src: 'pwa-512x512.png',
          sizes: '512x512',
          type: 'image/png',
        },
      ],
      share_target: {
        action: 'share-target',
        method: 'POST',
        enctype: 'multipart/form-data',
        params: {
          title: 'title',
          text: 'text',
          url: 'url',
          files: [
            {
              name: 'files',
              accept: [
                'image/*',
                'application/pdf',
                '.pdf',
                '.tif',
                '.tiff',
                'image/tiff',
              ],
            },
          ],
        },
      },
    },
    injectManifest: {
      globPatterns: ['**/*.{js,css,html,ico,png,svg,woff2}'],
    },
    devOptions: {
      enabled: true,
      type: 'module',
    },
  })
}
