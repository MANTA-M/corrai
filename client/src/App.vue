<script setup lang="ts">
import MainNavigation from './components/MainNavigation.vue'
import InitProfile from './components/InitProfile.vue'
import { useI18n } from 'vue-i18n'
import { loadLanguage } from './i18n'
import { useSessionStore } from '@/stores/session'
import { onMounted, ref, watch } from 'vue'
import { useNotificationService } from './services/notifications'

const { t } = useI18n()
const sessionStore = useSessionStore()
const notificationService = useNotificationService()
const provisioning = ref(false)

loadLanguage('en')

const handleUrlFragment = async () => {
  // Token-based authentication removed - authentication is now based on keypair
  // Clean up any hash from URL if present
  if (window.location.hash) {
    history.pushState('', document.title, window.location.pathname + window.location.search)
  }
}

// Handle notification permission on login
watch(
  () => sessionStore.isAuthenticated,
  async (isAuthenticated) => {
    if (isAuthenticated && notificationService.isSupported()) {
      // Request notification permission if not already granted or denied
      if (notificationService.getPermission() === 'default') {
        // Only request if permission hasn't been requested before
        const lastRequest = localStorage.getItem('notification_permission_requested')
        if (!lastRequest) {
          await notificationService.requestPermissionAndSubscribe()
          localStorage.setItem('notification_permission_requested', 'true')
        }
      } else if (notificationService.isGranted()) {
        // If already granted, make sure subscription is sent to backend
        await notificationService.subscribeAndSend()
      }
    }
  },
  { immediate: true }
)

onMounted(async () => {
  handleUrlFragment()
  if (sessionStore.keyPair && !sessionStore.hasValidUserId) {
    provisioning.value = true
    try {
      await sessionStore.ensureServerUser()
    } catch (error) {
      console.error('Failed to provision server user for existing profile:', error)
      sessionStore.clearSession()
    } finally {
      provisioning.value = false
    }
  }
})
</script>

<template>
  <div class="app">
    <InitProfile v-if="!sessionStore.keyPair" />

    <div v-else-if="provisioning || !sessionStore.isInitialized" class="provisioning">
      <p>{{ t('exam.loading') }}</p>
    </div>

    <template v-else>
      <!-- Header -->
      <MainNavigation />

      <!-- Main Content -->
      <main>
        <router-view />
      </main>
    </template>
  </div>
</template>

<style scoped>
.provisioning {
  padding: 2rem;
  text-align: center;
  color: var(--text-muted);
}

@media (max-width: 768px) {
  .app {
    padding: 0;
  }
}
</style>
