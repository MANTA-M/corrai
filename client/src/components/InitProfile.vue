<template>
  <div class="init-profile">
    <div class="card" style="max-width: 500px; margin: 2rem auto">
      <div style="margin-bottom: 24px">
        <h1 style="text-align: center">{{ $t('initProfile.title') }}</h1>
        <p class="muted" style="text-align: center">{{ $t('initProfile.subtitle') }}</p>
      </div>

      <!-- Create or Recover Selection -->
      <div v-if="!showCreateForm && !showRecoverForm" class="options">
        <button
          @click="showCreateForm = true"
          class="button primary"
          style="width: 100%; margin-bottom: 16px"
        >
          {{ $t('initProfile.createProfile') }}
        </button>
        <button
          @click="showRecoverForm = true"
          class="button"
          style="width: 100%"
        >
          {{ $t('initProfile.recoverProfile') }}
        </button>
      </div>

      <!-- Create Profile Form -->
      <div v-if="showCreateForm">
        <form @submit.prevent="handleCreateProfile" style="display: flex; flex-direction: column; gap: 16px">
          <div>
            <label for="userName" style="display: block; margin-bottom: 8px">
              {{ $t('initProfile.userName') }}
              <span class="muted" style="font-size: 0.875rem"> ({{ $t('initProfile.optional') }})</span>
            </label>
            <input
              id="userName"
              v-model="form.userName"
              type="text"
              class="input"
              :placeholder="$t('initProfile.userNamePlaceholder')"
            />
          </div>

          <div style="display: flex; gap: 12px">
            <button
              type="button"
              @click="showCreateForm = false"
              class="button"
              style="flex: 1"
            >
              {{ $t('common.cancel') }}
            </button>
            <button
              type="submit"
              class="button primary"
              style="flex: 1"
              :disabled="loading"
            >
              {{ loading ? $t('initProfile.creating') : $t('initProfile.create') }}
            </button>
          </div>
        </form>
      </div>

      <!-- Recover Profile Form -->
      <div v-if="showRecoverForm">
        <p class="muted" style="text-align: center; margin-bottom: 16px">
          {{ $t('initProfile.recoverDescription') }}
        </p>
        <div style="display: flex; gap: 12px">
          <button
            @click="showRecoverForm = false"
            class="button"
            style="flex: 1"
          >
            {{ $t('common.back') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isValidUserId, useSessionStore } from '@/stores/session'
import { useRouter } from 'vue-router'
import { WSClient } from '@/backend/WSClient'

interface CryptoKeyPair {
  publicKey: string
  privateKey: string
}

const { t } = useI18n()
const sessionStore = useSessionStore()
const router = useRouter()

const showCreateForm = ref(false)
const showRecoverForm = ref(false)
const loading = ref(false)

const form = ref({
  userName: '',
})

/**
 * Helper function to convert ArrayBuffer to base64 string
 */
function arrayBufferToBase64(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer)
  let binary = ''
  for (let i = 0; i < bytes.byteLength; i++) {
    binary += String.fromCharCode(bytes[i])
  }
  return btoa(binary)
}

/**
 * Creates a new cryptographic key pair using Web Crypto API
 * Compatible with the majority of browsers
 * @returns Promise<CryptoKeyPair> The generated key pair with public and private keys as base64 strings
 */
async function createKeyPair(): Promise<CryptoKeyPair> {
  try {
    // Generate key pair using ECDSA with P-256 curve (widely supported)
    const keyPair = await crypto.subtle.generateKey(
      {
        name: 'ECDSA',
        namedCurve: 'P-256',
      },
      true, // extractable
      ['sign', 'verify']
    )

    // Export keys to base64 strings for storage
    const publicKeyArrayBuffer = await crypto.subtle.exportKey('spki', keyPair.publicKey)
    const privateKeyArrayBuffer = await crypto.subtle.exportKey('pkcs8', keyPair.privateKey)

    // Convert ArrayBuffer to base64 string
    const publicKeyBase64 = arrayBufferToBase64(publicKeyArrayBuffer)
    const privateKeyBase64 = arrayBufferToBase64(privateKeyArrayBuffer)

    const keyPairData: CryptoKeyPair = {
      publicKey: publicKeyBase64,
      privateKey: privateKeyBase64,
    }

    return keyPairData
  } catch (error) {
    console.error('Error creating key pair:', error)
    throw new Error('Failed to create key pair')
  }
}

const handleCreateProfile = async () => {
  loading.value = true
  try {
    const keyPair = await createKeyPair()
    const userName = form.value.userName.trim()

    const wsClient = new WSClient(undefined, undefined, true)
    const response = await wsClient.queryWs<{ user?: { id?: string; name?: string } }>(
      'POST',
      '/user',
      undefined,
      { name: userName }
    )

    const userId = response?.user?.id
    if (!isValidUserId(userId)) {
      throw new Error('Server did not return a valid user id')
    }

    sessionStore.setKeyPair(keyPair)
    sessionStore.setUserId(userId)
    sessionStore.setUserName(userName || response.user?.name || '')

    router.push('/exam-list')
  } catch (error) {
    console.error('Error creating profile:', error)
    alert(t('initProfile.createError'))
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.init-profile {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.options {
  display: flex;
  flex-direction: column;
}

.input {
  width: 100%;
}
</style>

