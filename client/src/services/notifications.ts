import { ref } from 'vue'
import { useSessionStore } from '@/stores/session'
import { registerServiceWorker } from '@/services/serviceWorker'

/**
 * Service for handling web push notifications
 */
class NotificationService {
  private permissionState = ref<NotificationPermission>('default')
  private subscription = ref<PushSubscription | null>(null)
  private vapidPublicKey: string | null = null

  constructor() {
    this.permissionState.value = Notification.permission
  }

  /**
   * Get the current permission state
   */
  getPermission(): NotificationPermission {
    return this.permissionState.value
  }

  /**
   * Check if notifications are supported
   */
  isSupported(): boolean {
    return 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window
  }

  /**
   * Check if permission is granted
   */
  isGranted(): boolean {
    return this.permissionState.value === 'granted'
  }

  /**
   * Check if permission is denied
   */
  isDenied(): boolean {
    return this.permissionState.value === 'denied'
  }

  /**
   * Get the subscription
   */
  getSubscription(): PushSubscription | null {
    return this.subscription.value
  }

  /**
   * Request notification permission
   */
  async requestPermission(): Promise<NotificationPermission> {
    if (!this.isSupported()) {
      console.warn('Notifications are not supported in this browser')
      return 'denied'
    }

    try {
      const permission = await Notification.requestPermission()
      this.permissionState.value = permission
      return permission
    } catch (error) {
      console.error('Error requesting notification permission:', error)
      this.permissionState.value = 'denied'
      return 'denied'
    }
  }

  /**
   * Get VAPID public key from backend
   */
  private async getVapidPublicKey(): Promise<string> {
    if (this.vapidPublicKey) {
      return this.vapidPublicKey
    }

    const sessionStore = useSessionStore()
    const client = sessionStore.getWsClient()
    
    try {
      const response = await client.queryWs<{ public_key: string }>('GET', '/keys')
      this.vapidPublicKey = response.public_key
      return this.vapidPublicKey
    } catch (error) {
      console.error('Error getting VAPID public key:', error)
      throw error
    }
  }

  /**
   * Convert VAPID key from base64 to ArrayBuffer
   */
  private urlBase64ToUint8Array(base64String: string): ArrayBuffer {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
    const rawData = window.atob(base64)
    const outputArray = new Uint8Array(rawData.length)
    
    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i)
    }
    return outputArray.buffer
  }

  /**
   * Subscribe to push notifications
   */
  async subscribe(): Promise<PushSubscription | null> {
    if (!this.isSupported()) {
      console.warn('Notifications are not supported')
      return null
    }

    if (this.permissionState.value !== 'granted') {
      console.warn('Notification permission is not granted')
      return null
    }

    try {
      // Ensure service worker is registered and ready
      if (!navigator.serviceWorker.controller) {
        console.warn('Service worker not registered yet, attempting to register...')
        await registerServiceWorker()
        console.log('Service worker registered')
      }
      
      // Check if we already have a subscription
      const registration = await navigator.serviceWorker.ready
      let subscription = await registration.pushManager.getSubscription()

      if (subscription) {
        this.subscription.value = subscription
        return subscription
      }

      // Create new subscription
      const publicKey = import.meta.env.VITE_VAPID_PUBLIC_KEY;
      if(!publicKey) {
        throw new Error('No VAPID public key found')
      }
      const applicationServerKey = this.urlBase64ToUint8Array(publicKey)
      
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: applicationServerKey
      })

      this.subscription.value = subscription
      return subscription
    } catch (error) {
      console.error('Error subscribing to push notifications:', error)
      return null
    }
  }

  /**
   * Subscribe and send subscription to backend
   */
  async subscribeAndSend(): Promise<boolean> {
    const sessionStore = useSessionStore()
    
    if (!sessionStore.isAuthenticated) {
      console.error('User is not authenticated')
      return false
    }

    try {
      const subscription = await this.subscribe()
      
      if (!subscription) {
        return false
      }

      // Send subscription to backend
      const subscriptionJson = subscription.toJSON()
      const client = sessionStore.getWsClient()
      
      const response = await client.queryWs<{ success: boolean }>(
        'POST',
        `/webpush_sub`,
        null,
        subscriptionJson
      )

      return response.success
    } catch (error) {
      console.error('Error sending subscription to backend:', error)
      return false
    }
  }

  /**
   * Request permission and subscribe
   * Note: This method can be blocked by the browser if called from an async chain
   * that is not directly triggered by a user gesture. Use requestPermission()
   * directly from a click handler instead.
   */
  async requestPermissionAndSubscribe(): Promise<boolean> {
    const permission = await this.requestPermission()
    
    if (permission === 'granted') {
      return await this.subscribeAndSend()
    }
    
    return false
  }

  /**
   * Update subscription
   */
  async updateSubscription(): Promise<void> {
    this.subscription.value = null
    await this.subscribeAndSend()
  }

  /**
   * Unsubscribe from push notifications
   */
  async unsubscribe(): Promise<boolean> {
    if (!this.isSupported()) {
      console.warn('Notifications are not supported')
      return false
    }

    try {
      this.permissionState.value = "default" as NotificationPermission

      console.log('Permission state:', this.permissionState.value)
      console.log('Unsubscribing from push notifications')
      
      // Ensure service worker is registered and ready
      if (!navigator.serviceWorker.controller) {
        console.warn('Service worker not available for unsubscribe')
        return false
      }
      
      const registration = await navigator.serviceWorker.ready
      const subscription = await registration.pushManager.getSubscription()
      
      if (subscription) {
        const success = await subscription.unsubscribe()
        if (success) {
          this.subscription.value = null
          console.log('Successfully unsubscribed from push notifications')
          return true
        }
      }
      return false
    } catch (error) {
      console.error('Error unsubscribing from push notifications:', error)
      return false
    }
  }

  /**
   * Unsubscribe from push notifications and notify backend
   */
  async unsubscribeAndNotify(): Promise<boolean> {
    const sessionStore = useSessionStore()
    
    if (!sessionStore.isAuthenticated) {
      console.error('User is not authenticated')
      return false
    }

    try {
      const unsubscribeSuccess = await this.unsubscribe()
      
      if (!unsubscribeSuccess) {
        return false
      }

      // Notify backend to remove subscription
      const client = sessionStore.getWsClient()
      
      const response = await client.queryWs<{ success: boolean }>(
        'DELETE',
        `/webpush_sub`,
        null,
        null
      )

      return response.success
    } catch (error) {
      console.error('Error notifying backend of unsubscribe:', error)
      // Still return true if we successfully unsubscribed locally
      return true
    }
  }
}

// Singleton instance
let notificationServiceInstance: NotificationService | null = null

/**
 * Get the notification service instance
 */
export function useNotificationService(): NotificationService {
  if (!notificationServiceInstance) {
    notificationServiceInstance = new NotificationService()
  }
  return notificationServiceInstance
}

