/// <reference lib="webworker" />
import { precacheAndRoute } from 'workbox-precaching'

const SHARE_CACHE = 'share-target'

precacheAndRoute(self.__WB_MANIFEST || [])

self.addEventListener('install', () => {
  console.log('Service Worker installing...')
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  console.log('Service Worker activating...')
  event.waitUntil(clients.claim())
})

self.addEventListener('push', (event) => {
  console.log('Push notification received:', event)

  const data = event.data?.json() || {}
  const title = data.title || 'Notification'
  const options = {
    body: data.body || '',
    icon: data.icon || 'pwa-192x192.png',
    badge: data.badge || 'pwa-192x192.png',
    vibrate: [200, 100, 200],
    ...data.options,
  }

  event.waitUntil(self.registration.showNotification(title, options))
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()

  event.waitUntil(clients.openWindow(event.notification.data?.url || self.registration.scope))
})

function isShareTargetRequest(request) {
  if (request.method !== 'POST') return false
  const url = new URL(request.url)
  return url.pathname.endsWith('/share-target') || url.pathname.endsWith('/share-target/')
}

async function handleShareTarget(request) {
  const formData = await request.formData()
  const incoming = formData.getAll('files')

  await caches.delete(SHARE_CACHE)
  const cache = await caches.open(SHARE_CACHE)

  let index = 0
  for (const entry of incoming) {
    if (!(entry instanceof File) || entry.size === 0) continue

    const headers = new Headers({
      'Content-Type': entry.type || 'application/octet-stream',
      'Content-Length': String(entry.size),
      'X-File-Name': encodeURIComponent(entry.name || `shared-file-${index}`),
    })

    await cache.put(
      new URL(`share-target/file/${index}`, self.registration.scope),
      new Response(entry, { headers }),
    )
    index += 1
  }

  return Response.redirect(new URL('share', self.registration.scope).href, 303)
}

self.addEventListener('fetch', (event) => {
  if (isShareTargetRequest(event.request)) {
    event.respondWith(handleShareTarget(event.request))
  }
})
