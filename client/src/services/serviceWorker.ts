const baseUrl = new URL(import.meta.env.BASE_URL, window.location.href)
console.log('baseUrl', baseUrl)
const resolvedServiceWorkerUrl = new URL('service-worker.js', baseUrl).href
console.log('resolvedServiceWorkerUrl', resolvedServiceWorkerUrl)

let registrationPromise: Promise<ServiceWorkerRegistration> | null = null

export function getServiceWorkerUrl(): string {
  return resolvedServiceWorkerUrl
}

export function registerServiceWorker(): Promise<ServiceWorkerRegistration> {
  if (!('serviceWorker' in navigator)) {
    return Promise.reject(new Error('Service workers are not supported in this environment'))
  }

  if (!registrationPromise) {
    registrationPromise = navigator.serviceWorker.register(resolvedServiceWorkerUrl)
  }

  return registrationPromise
}

