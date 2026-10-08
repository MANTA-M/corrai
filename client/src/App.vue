<script setup lang="ts">
import MainNavigation from './components/MainNavigation.vue'
import InitProfile from './components/InitProfile.vue'
import { useI18n } from 'vue-i18n'
import { DEFAULT_LOCALE, loadLanguage } from './i18n'
import { isValidUserId, useSessionStore } from '@/stores/session'
import { onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useNotificationService } from './services/notifications'

const { t } = useI18n()
const sessionStore = useSessionStore()
const router = useRouter()
const notificationService = useNotificationService()
const provisioning = ref(false)

const initialParams = new URLSearchParams(window.location.search)
const initialAs = initialParams.get('as')
const adoptingAsTeacher = ref(isValidUserId(initialAs))

loadLanguage(sessionStore.locale || DEFAULT_LOCALE)

function arrayBufferToBase64(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (let i = 0; i < bytes.byteLength; i++) {
    binary += String.fromCharCode(bytes[i])
  }
  return btoa(binary)
}

async function createLocalKeyPair(): Promise<{ publicKey: string; privateKey: string }> {
  const keyPair = await crypto.subtle.generateKey(
    {
      name: 'ECDSA',
      namedCurve: 'P-256',
    },
    true,
    ['sign', 'verify'],
  )

  const publicKeyArrayBuffer = await crypto.subtle.exportKey('spki', keyPair.publicKey)
  const privateKeyArrayBuffer = await crypto.subtle.exportKey('pkcs8', keyPair.privateKey)

  return {
    publicKey: arrayBufferToBase64(publicKeyArrayBuffer),
    privateKey: arrayBufferToBase64(privateKeyArrayBuffer),
  }
}

const clearAsParamsFromUrl = async () => {
  const params = new URLSearchParams(window.location.search)
  if (!params.has('as') && !params.has('name')) {
    return
  }
  const query = { ...router.currentRoute.value.query }
  delete query.as
  delete query.name
  await router.replace({ path: router.currentRoute.value.path, query })
}

const adoptTeacherFromQuery = async (): Promise<boolean> => {
  const params = new URLSearchParams(window.location.search)
  const as = params.get('as')
  if (!isValidUserId(as)) {
    adoptingAsTeacher.value = false
    return false
  }

  adoptingAsTeacher.value = true
  provisioning.value = true
  try {
    sessionStore.setUserId(as)
    const name = params.get('name')?.trim()
    if (name) {
      sessionStore.setUserName(name)
    }

    if (!sessionStore.keyPair) {
      sessionStore.setKeyPair(await createLocalKeyPair())
    }

    await clearAsParamsFromUrl()
    adoptingAsTeacher.value = false
    return true
  } catch (error) {
    console.error('Failed to adopt teacher from query:', error)
    adoptingAsTeacher.value = false
    return false
  } finally {
    provisioning.value = false
  }
}

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
  { immediate: true },
)

onMounted(async () => {
  handleUrlFragment()
  const adopted = await adoptTeacherFromQuery()
  if (adopted) {
    return
  }
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
  <div class="shell">
    <InitProfile v-if="!sessionStore.keyPair && !adoptingAsTeacher" />

    <div
      v-else-if="adoptingAsTeacher || provisioning || !sessionStore.isInitialized"
      class="provisioning"
    >
      <p>{{ t('assessment.loading') }}</p>
    </div>

    <template v-else>
      <MainNavigation />

      <main class="page">
        <router-view />
      </main>
    </template>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100%;
}

.page {
  min-height: calc(100vh - 72px);
}

.provisioning {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 2rem;
  text-align: center;
  color: var(--text-muted);
}
</style>
