// Service worker for push notifications
// This service worker needs to be active for push notifications to work

self.addEventListener('install', (event) => {
  console.log('Service Worker installing...')
  // Take immediate control once installed
  self.skipWaiting()
})

self.addEventListener('activate', (event) => {
  console.log('Service Worker activating...')
  // Take immediate control of all pages
  event.waitUntil(clients.claim())
})

// Listen for push events
self.addEventListener('push', (event) => {
  console.log('Push notification received:', event)
  
  const data = event.data?.json() || {}
  const title = data.title || 'Notification'
  const options = {
    body: data.body || '',
    icon: data.icon || '/favicon.ico',
    badge: data.badge || '/favicon.ico',
    vibrate: [200, 100, 200],
    ...data.options
  }

  event.waitUntil(
    self.registration.showNotification(title, options)
  )
})

// Listen for notification clicks
self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  
  event.waitUntil(
    clients.openWindow(event.notification.data?.url || '/')
  )
})

