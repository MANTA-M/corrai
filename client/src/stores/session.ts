import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { WSClient, type ApiMessage } from '@/backend/WSClient'
import type { Assessment } from '@/types/types'

import { DEFAULT_LOCALE, type AvailableLocale } from '@/i18n'

interface CryptoKeyPair {
  publicKey: string
  privateKey: string
}

interface SessionState {
  user_name: string
  country: string
  locale: AvailableLocale
  keyPair: CryptoKeyPair | null
  user_id: string | null
  own_assessments: Assessment[]
  debugMode: boolean
}

/** Server assessment payload (uses user_id; client Assessment uses author). */
interface ServerAssessment {
  id: string
  school_id?: string
  user_id?: string
  author?: string
  name: string
  subject: string
  country?: string | null
  level?: string | null
  date: string
  created_at?: string
  files?: Assessment['files']
  students?: Assessment['students']
}

const STORAGE_KEY = 'corrai-session'
const USER_ID_PATTERN = /^[0-9a-zA-Z]{7}$/

const defaultState: SessionState = {
  user_name: '',
  country: 'fr',
  locale: DEFAULT_LOCALE,
  keyPair: null,
  user_id: null,
  own_assessments: [],
  debugMode: false,
}

export function isValidUserId(id: string | null | undefined): id is string {
  return typeof id === 'string' && USER_ID_PATTERN.test(id)
}

function normalizeAssessment(raw: ServerAssessment): Assessment {
  return {
    id: raw.id,
    author: raw.user_id ?? raw.author ?? '',
    name: raw.name,
    subject: raw.subject,
    country: raw.country || null,
    level: raw.level || null,
    date: raw.date,
    files: raw.files ?? [],
    students: raw.students ?? [],
  }
}

export const useSessionStore = defineStore('session', () => {
  const user_name = ref<string>(defaultState.user_name)
  const country = ref<string>(defaultState.country)
  const locale = ref<AvailableLocale>(defaultState.locale)
  const keyPair = ref<CryptoKeyPair | null>(defaultState.keyPair)
  const user_id = ref<string | null>(defaultState.user_id)
  const own_assessments = ref<Assessment[]>(defaultState.own_assessments)
  const debugMode = ref<boolean>(defaultState.debugMode)

  const hasValidUserId = computed(() => isValidUserId(user_id.value))
  const isAuthenticated = computed(
    () =>
      !!keyPair.value &&
      !!keyPair.value.publicKey &&
      !!keyPair.value.privateKey &&
      hasValidUserId.value
  )
  const isInitialized = computed(() => !!keyPair.value && hasValidUserId.value)
  const messages = ref<ApiMessage[]>([])
  const loading = ref(false)

  function base64ToArrayBuffer(base64: string): ArrayBuffer {
    const binary = atob(base64)
    const bytes = new Uint8Array(binary.length)
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i)
    }
    return bytes.buffer
  }

  function setKeyPair(newKeyPair: CryptoKeyPair): void {
    keyPair.value = newKeyPair
  }

  function setUserId(id: string): void {
    user_id.value = isValidUserId(id) ? id : null
  }

  function discardInvalidUserId(): void {
    if (user_id.value && !isValidUserId(user_id.value)) {
      user_id.value = null
    }
  }

  /**
   * Legacy sessions stored the ECDSA public key as identity. Create an IND
   * teacher on the server and persist the 7-character user id instead.
   */
  async function ensureServerUser(): Promise<void> {
    discardInvalidUserId()
    if (isValidUserId(user_id.value) || !keyPair.value) {
      return
    }

    const wsClient = new WSClient(loading, messages, true)
    const response = await wsClient.queryWs<{ user?: { id?: string; name?: string } }>(
      'POST',
      '/user',
      undefined,
      { name: user_name.value }
    )

    const id = response?.user?.id
    if (!isValidUserId(id)) {
      throw new Error('Server did not return a valid user id')
    }

    user_id.value = id
    if (!user_name.value && response.user?.name) {
      user_name.value = response.user.name
    }
  }

  function setUserName(userName: string): void {
    user_name.value = userName
  }

  function setCountry(code: string): void {
    country.value = code
  }

  async function loadUser(): Promise<void> {
    const wsClient = getWsClient()
    const response = await wsClient.queryWs<{ user?: { country?: string; name?: string } }>(
      'GET',
      '/user'
    )
    const loaded = response?.user?.country
    if (typeof loaded === 'string' && loaded !== '') {
      country.value = loaded
    }
    if (!user_name.value && response?.user?.name) {
      user_name.value = response.user.name
    }
  }

  async function saveCountry(code: string): Promise<void> {
    const wsClient = getWsClient()
    const response = await wsClient.queryWs<{ user?: { country?: string } }>(
      'PUT',
      '/user',
      undefined,
      { country: code }
    )
    country.value = response?.user?.country || code
  }

  function setLocale(newLocale: AvailableLocale): void {
    locale.value = newLocale
  }

  function setDebugMode(enabled: boolean): void {
    debugMode.value = enabled
  }

  function logout(): void {
    clearSession()
  }

  async function getCryptoKeys(): Promise<{ publicKey: CryptoKey; privateKey: CryptoKey } | null> {
    if (!keyPair.value) {
      return null
    }

    try {
      const publicKeyArrayBuffer = base64ToArrayBuffer(keyPair.value.publicKey)
      const privateKeyArrayBuffer = base64ToArrayBuffer(keyPair.value.privateKey)

      const publicKey = await crypto.subtle.importKey(
        'spki',
        publicKeyArrayBuffer,
        {
          name: 'ECDSA',
          namedCurve: 'P-256',
        },
        true,
        ['verify']
      )

      const privateKey = await crypto.subtle.importKey(
        'pkcs8',
        privateKeyArrayBuffer,
        {
          name: 'ECDSA',
          namedCurve: 'P-256',
        },
        true,
        ['sign']
      )

      return { publicKey, privateKey }
    } catch (error) {
      console.error('Error importing crypto keys:', error)
      return null
    }
  }

  function clearSession(): void {
    keyPair.value = null
    user_id.value = null
    own_assessments.value = []
  }

  const getWsClient = (noRedirect = false) => {
    const wsClient = new WSClient(loading, messages, noRedirect)
    if (isValidUserId(user_id.value)) {
      wsClient.auth_token = user_id.value
    }
    return wsClient
  }

  function get_assessment(id: string): Assessment | null {
    return own_assessments.value.find(e => e.id === id) ?? null
  }

  async function load_assessments(): Promise<Assessment[]> {
    try {
      const wsClient = getWsClient()
      const response = await wsClient.queryWs<{ assessments: ServerAssessment[] }>('GET', '/assessments')
      const assessments = (response?.assessments ?? []).map(normalizeAssessment)
      own_assessments.value = assessments
      return assessments
    } catch (error) {
      console.error('Error loading assessments:', error)
      return []
    }
  }

  async function load_assessment(hash: string): Promise<Assessment | null> {
    try {
      const wsClient = getWsClient()
      const response = await wsClient.queryWs<{
        assessment: ServerAssessment
        files?: Assessment['files']
        students?: Assessment['students']
      }>('GET', '/assessment', { hash })

      if (!response || !response.assessment || !response.assessment.id) {
        console.error('Assessment loaded but missing id')
        return null
      }

      const assessment = normalizeAssessment({
        ...response.assessment,
        files: response.files ?? response.assessment.files ?? [],
        students: response.students ?? response.assessment.students ?? [],
      })
      const existingIndex = own_assessments.value.findIndex(e => e.id === assessment.id)
      if (existingIndex !== -1) {
        own_assessments.value[existingIndex] = assessment
      } else {
        own_assessments.value.push(assessment)
      }

      return assessment
    } catch (error) {
      console.error('Error loading assessment:', error)
      return null
    }
  }

  function remove_assessment(id: string): void {
    own_assessments.value = own_assessments.value.filter(e => e.id !== id)
  }

  return {
    user_name,
    country,
    locale,
    keyPair,
    user_id,
    own_assessments,
    debugMode,
    hasValidUserId,
    isAuthenticated,
    isInitialized,
    messages,
    loading,
    setKeyPair,
    setUserId,
    discardInvalidUserId,
    ensureServerUser,
    setUserName,
    setCountry,
    loadUser,
    saveCountry,
    setLocale,
    setDebugMode,
    logout,
    getCryptoKeys,
    clearSession,
    getWsClient,
    get_assessment,
    load_assessments,
    load_assessment,
    remove_assessment,
  }
}, {
  persist: {
    key: STORAGE_KEY,
    storage: localStorage,
    pick: ['user_name', 'country', 'locale', 'keyPair', 'user_id', 'debugMode'],
    afterHydrate: ({ store }) => {
      store.discardInvalidUserId()
      if (typeof store.debugMode !== 'boolean') {
        store.debugMode = false
      }
    },
  }
})
