import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { WSClient, type ApiMessage } from '@/backend/WSClient'
import type { Exam } from '@/types/types'

import { DEFAULT_LOCALE, type AvailableLocale } from '@/i18n'

interface CryptoKeyPair {
  publicKey: string
  privateKey: string
}

interface SessionState {
  user_name: string
  locale: AvailableLocale
  keyPair: CryptoKeyPair | null
  user_id: string | null
  own_exams: Exam[]
}

/** Server exam payload (uses user_id; client Exam uses author). */
interface ServerExam {
  id: string
  school_id?: string
  user_id?: string
  author?: string
  name: string
  subject: string
  date: string
  created_at?: string
  files?: Exam['files']
}

const STORAGE_KEY = 'corrai-session'
const USER_ID_PATTERN = /^[0-9a-zA-Z]{7}$/

const defaultState: SessionState = {
  user_name: '',
  locale: DEFAULT_LOCALE,
  keyPair: null,
  user_id: null,
  own_exams: [],
}

export function isValidUserId(id: string | null | undefined): id is string {
  return typeof id === 'string' && USER_ID_PATTERN.test(id)
}

function normalizeExam(raw: ServerExam): Exam {
  return {
    id: raw.id,
    author: raw.user_id ?? raw.author ?? '',
    name: raw.name,
    subject: raw.subject,
    date: raw.date,
    files: raw.files ?? [],
  }
}

export const useSessionStore = defineStore('session', () => {
  const user_name = ref<string>(defaultState.user_name)
  const locale = ref<AvailableLocale>(defaultState.locale)
  const keyPair = ref<CryptoKeyPair | null>(defaultState.keyPair)
  const user_id = ref<string | null>(defaultState.user_id)
  const own_exams = ref<Exam[]>(defaultState.own_exams)

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

  function setLocale(newLocale: AvailableLocale): void {
    locale.value = newLocale
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
    own_exams.value = []
  }

  const getWsClient = (noRedirect = false) => {
    const wsClient = new WSClient(loading, messages, noRedirect)
    if (isValidUserId(user_id.value)) {
      wsClient.auth_token = user_id.value
    }
    return wsClient
  }

  function get_exam(id: string): Exam | null {
    return own_exams.value.find(e => e.id === id) ?? null
  }

  async function load_exams(): Promise<Exam[]> {
    try {
      const wsClient = getWsClient()
      const response = await wsClient.queryWs<{ exams: ServerExam[] }>('GET', '/exams')
      const exams = (response?.exams ?? []).map(normalizeExam)
      own_exams.value = exams
      return exams
    } catch (error) {
      console.error('Error loading exams:', error)
      return []
    }
  }

  async function load_exam(hash: string): Promise<Exam | null> {
    try {
      const wsClient = getWsClient()
      const response = await wsClient.queryWs<{ exam: ServerExam; files?: Exam['files'] }>(
        'GET',
        '/exam',
        { hash }
      )

      if (!response || !response.exam || !response.exam.id) {
        console.error('Exam loaded but missing id')
        return null
      }

      const exam = normalizeExam({
        ...response.exam,
        files: response.files ?? response.exam.files ?? [],
      })
      const existingIndex = own_exams.value.findIndex(e => e.id === exam.id)
      if (existingIndex !== -1) {
        own_exams.value[existingIndex] = exam
      } else {
        own_exams.value.push(exam)
      }

      return exam
    } catch (error) {
      console.error('Error loading exam:', error)
      return null
    }
  }

  function remove_exam(id: string): void {
    own_exams.value = own_exams.value.filter(e => e.id !== id)
  }

  return {
    user_name,
    locale,
    keyPair,
    user_id,
    own_exams,
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
    setLocale,
    logout,
    getCryptoKeys,
    clearSession,
    getWsClient,
    get_exam,
    load_exams,
    load_exam,
    remove_exam,
  }
}, {
  persist: {
    key: STORAGE_KEY,
    storage: localStorage,
    pick: ['user_name', 'locale', 'keyPair', 'user_id'],
    afterHydrate: ({ store }) => {
      store.discardInvalidUserId()
    },
  }
})
