<template>
  <div class="app">
    <div class="card">
      <div style="margin-bottom: 16px">
        <h1>
          {{ $t('settings.title') }}
        </h1>
        <p class="muted">{{ $t('settings.subtitle') }}</p>
      </div>
      <div>
        <div style="display: flex; flex-direction: column; gap: 32px">
          <!-- General Settings -->
          <section>
            <h2>
              {{ $t('settings.general') }}
            </h2>
            <div style="display: flex; flex-direction: column; gap: 16px">
              <div style="display: flex; align-items: center; gap: 8px">
                <router-link :to="link_data">{{ $t('settings.admin_console_link') }}</router-link>
                <button
                  class="copy-button"
                  @click.prevent="copyLinkToClipboard"
                  :title="$t('settings.copy_link')"
                >
                  <svg
                    xmlns="http://www.w3.org/2000/svg"
                    width="16"
                    height="16"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                  </svg>
                </button>
              </div>
              <div style="display: flex; flex-flow: row wrap; gap: 8px; align-items: center">
                <label for="admin_locale" style="display: block; margin-bottom: 8px">{{
                  $t('settings.admin_locale')
                }}</label>
                <select
                  id="admin_locale"
                  v-model="settings.admin_locale"
                  class="select"
                  @change="saveSetting('admin_locale')"
                >
                  <option v-for="locale in availableLocales" :key="locale.code" :value="locale.code">
                    {{ locale.name }}
                  </option>
                </select>
              </div>
            </div>
          </section>

          <!-- Notifications Settings -->
          <section v-if="notificationService.isSupported()">
            <h2>{{ $t('settings.notifications') }}</h2>
            <div style="display: flex; flex-direction: column; gap: 16px">
              <div
                v-if="notificationService.getPermission() === 'denied'"
                class="status-banner status-banner--warning"
              >
                <p class="status-banner__text">
                  {{ $t('settings.notifications_permission_denied_warning') }}
                </p>
                <button
                  @click.prevent.stop="requestNotificationPermission"
                  :disabled="isRequestingNotificationPermission"
                  class="test-button test-button--warning"
                >
                  {{ isRequestingNotificationPermission ? '...' : $t('settings.enable_notifications') }}
                </button>
              </div>
              <div v-else-if="notificationService.getPermission() === 'granted'">
                <div class="status-banner status-banner--success" style="margin-bottom: 12px">
                  <p class="status-banner__text">
                    {{ $t('settings.notifications_enabled_status') }}
                  </p>
                </div>
                <div style="display: flex; flex-wrap: wrap; gap: 12px">
                  <button
                    @click.prevent.stop="createWebpushSubscription"
                    :disabled="isCreatingWebpushSubscription"
                    class="test-button"
                  >
                    {{ isCreatingWebpushSubscription ? '...' : $t('settings.create_webpush_subscription') }}
                  </button>
                  <button
                    @click.prevent.stop="resetNotifications"
                    :disabled="isResettingNotifications"
                    class="test-button test-button--danger"
                  >
                    {{ isResettingNotifications ? '...' : $t('settings.reset_notifications') }}
                  </button>
                </div>
              </div>
              <div
                v-else-if="notificationService.getPermission() === 'default'"
                class="status-banner status-banner--info"
              >
                <p class="status-banner__text">
                  {{ $t('settings.notifications_not_requested') }}
                </p>
                <button
                  @click.prevent.stop="requestNotificationPermission"
                  :disabled="isRequestingNotificationPermission"
                  class="test-button test-button--info"
                >
                  {{ isRequestingNotificationPermission ? '...' : $t('settings.enable_notifications') }}
                </button>
              </div>
            </div>
          </section>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { AVAILABLE_LOCALES } from '@/i18n'
import { useNotificationService } from '@/services/notifications'

const sessionStore = useSessionStore()
const { t } = useI18n()
const notificationService = useNotificationService()

// Use native language names from LocaleSelector.vue
const availableLocales = computed(() => {
  const localeNames: Record<string, string> = {
    en: 'English',
    fr: 'Français',
    ru: 'Русский',
    uk: 'Українська',
    es: 'Español',
    pt: 'Português',
    ro: 'Română',
    de: 'Deutsch',
  }
  
  return AVAILABLE_LOCALES.map(code => ({
    code,
    name: localeNames[code] || code,
  }))
})

// Load settings on component mount

interface Settings {
  admin_locale: string
  link?: string
}

// Initialize settings with default values
const settings = ref<Settings>({
  admin_locale: 'en',
})

// Notification permission state
const isRequestingNotificationPermission = ref(false)
const isResettingNotifications = ref(false)
const isCreatingWebpushSubscription = ref(false)

const requestNotificationPermission = async () => {
  isRequestingNotificationPermission.value = true
  try {
    // Request permission directly from user gesture to avoid browser blocking
    const permission = await notificationService.requestPermission()
    
    if (permission === 'granted') {
      // Now subscribe and send to backend
      const success = await notificationService.subscribeAndSend()
      if (success) {
        alert(t('settings.notifications_enabled'))
      } else {
        alert(t('settings.notifications_error'))
      }
    } else if (permission === 'denied') {
      alert(t('settings.notifications_permission_denied'))
    }
  } catch (error) {
    console.error('Error requesting notification permission:', error)
    alert(t('settings.notifications_error'))
  } finally {
    isRequestingNotificationPermission.value = false
  }
}

const createWebpushSubscription = async () => {
  if (isCreatingWebpushSubscription.value) {
    return
  }

  isCreatingWebpushSubscription.value = true

  try {
    const success = await notificationService.subscribeAndSend()

    if (success) {
      alert(t('settings.notifications_enabled'))
    } else {
      alert(t('settings.notifications_error'))
    }
  } catch (error) {
    console.error('Error creating web push subscription:', error)
    alert(t('settings.notifications_error'))
  } finally {
    isCreatingWebpushSubscription.value = false
  }
}

const resetNotifications = async () => {
  if (!confirm(t('settings.reset_notifications_confirm'))) {
    return
  }

  isResettingNotifications.value = true
  try {
    const success = await notificationService.unsubscribeAndNotify()
    if (success) {
      alert(t('settings.notifications_reset_success'))
      // Reload the page to reflect the new permission state
      window.location.reload()
    } else {
      alert(t('settings.notifications_reset_error'))
    }
  } catch (error) {
    console.error('Error resetting notifications:', error)
    alert(t('settings.notifications_reset_error'))
  } finally {
    isResettingNotifications.value = false
  }
}

const copyLinkToClipboard = () => {
  const { origin } = window.location
  const { path, hash } = link_data.value
  const fullUrl = `${origin}${path}${hash}`
  navigator.clipboard
    .writeText(fullUrl)
    .then(() => {
      // You could add a toast notification here if you have one
      console.log('Link copied to clipboard')
    })
    .catch((err) => {
      console.error('Failed to copy link:', err)
    })
}

const saveSetting = async (setting: keyof Settings) => {
  const originalValue = settings.value[setting]

  try {
    const response = await sessionStore
      .getWsClient()
      .queryWs<any>('PUT', '/settings/' + setting, null, { new_value: originalValue })

    if (!response.ok) {
      throw new Error('Failed to save setting')
    }
  } catch (error) {
    console.error(`Failed to save ${setting}:`, error)
    // Revert the value if save failed
    settings.value = {
      ...settings.value,
      [setting]: originalValue,
    }
  }
}

const link_data = computed(() => {
  return {
    path: '/',
    hash: sessionStore.keyPair?.publicKey ? '#' + sessionStore.keyPair.publicKey : '',
  }
})

onMounted(async () => {
  try {
    const adminLocaleResponse = await sessionStore
      .getWsClient()
      .queryWs<{ admin_locale: string }>('GET', '/settings/admin_locale')
    settings.value.admin_locale = adminLocaleResponse.admin_locale
  } catch (error) {
    console.error('Error loading admin_locale:', error)
  }
})
</script>

<style scoped>
.select {
  padding: 10px 12px;
  border-radius: var(--radius-md);
  border: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
  min-width: 200px;
  cursor: pointer;
}

.select:hover {
  border-color: var(--muted);
}

.select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--accent) 25%, transparent);
}

.copy-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 4px;
  border: none;
  background: none;
  color: var(--muted);
  cursor: pointer;
  border-radius: var(--radius-sm);
  transition: all 0.2s;
}

.copy-button:hover {
  color: var(--accent);
  background-color: color-mix(in srgb, var(--accent) 12%, transparent);
}

.copy-button:active {
  transform: scale(0.95);
}

.test-button {
  padding: 12px 18px;
  border: none;
  border-radius: var(--radius-md);
  background-color: var(--accent);
  color: white;
  cursor: pointer;
  font-size: var(--type-label);
  transition: all 0.2s;
  min-width: 120px;
}

.test-button:hover:not(:disabled) {
  background-color: var(--accent-600);
  transform: translateY(-1px);
}

.test-button:active:not(:disabled) {
  transform: translateY(0);
}

.test-button:disabled {
  background-color: var(--disabled);
  cursor: not-allowed;
  opacity: 0.6;
}

.test-button--warning {
  background-color: var(--warning);
  color: white;
  box-shadow: none;
}

.test-button--warning:hover:not(:disabled) {
  background-color: color-mix(in srgb, var(--warning) 85%, black);
}

.test-button--danger {
  background-color: var(--danger);
}

.test-button--danger:hover:not(:disabled) {
  background-color: var(--danger-600);
}

.test-button--info {
  background-color: var(--info);
  color: white;
  box-shadow: none;
}

.test-button--info:hover:not(:disabled) {
  background-color: color-mix(in srgb, var(--info) 85%, black);
}

.status-banner {
  padding: 14px;
  border-radius: var(--radius-md);
}

.status-banner__text {
  margin: 0;
  margin-bottom: 8px;
  font-weight: 500;
  pointer-events: none;
}

.status-banner--warning {
  background-color: color-mix(in srgb, var(--warning) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--warning) 40%, transparent);
}

.status-banner--warning .status-banner__text {
  color: var(--warning);
}

.status-banner--success {
  background-color: color-mix(in srgb, var(--success) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--success) 40%, transparent);
}

.status-banner--success .status-banner__text {
  color: var(--success);
  margin-bottom: 0;
}

.status-banner--info {
  background-color: color-mix(in srgb, var(--info) 12%, transparent);
  border: 1px solid color-mix(in srgb, var(--info) 40%, transparent);
}

.status-banner--info .status-banner__text {
  color: var(--info);
}

.notification-message {
  padding: 12px 14px;
  border-radius: var(--radius-md);
  font-size: var(--type-label);
  font-weight: 600;
}

.notification-message.success {
  background-color: color-mix(in srgb, var(--success) 12%, transparent);
  color: var(--success);
  border: 1px solid color-mix(in srgb, var(--success) 40%, transparent);
}

.notification-message.error {
  background-color: color-mix(in srgb, var(--danger) 12%, transparent);
  color: var(--danger);
  border: 1px solid color-mix(in srgb, var(--danger) 40%, transparent);
}
</style>
