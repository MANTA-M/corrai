import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import type { SubjectNode } from '@/types/types'

const subjects = ref<SubjectNode[]>([])
let loadedLocale = ''
let inflight: Promise<void> | null = null
let localeWatchStarted = false

/**
 * Shared subject → country → level tree from GET /subject.
 * Labels follow the active locale.
 */
export function useSubjectCatalog() {
  const { locale } = useI18n()
  const sessionStore = useSessionStore()

  const load = async () => {
    const current = String(locale.value)
    if (loadedLocale === current && subjects.value.length > 0) {
      return
    }
    if (inflight) {
      await inflight
      if (loadedLocale === current) return
    }
    const requestLocale = current
    inflight = (async () => {
      try {
        const response = await sessionStore.getWsClient().queryWs<{ subjects?: SubjectNode[] }>(
          'GET',
          '/subject',
          { locale: requestLocale }
        )
        if (String(locale.value) !== requestLocale) return
        subjects.value = response?.subjects ?? []
        loadedLocale = requestLocale
      } catch (err) {
        console.error('Error loading subjects:', err)
        if (String(locale.value) === requestLocale) {
          subjects.value = []
          loadedLocale = ''
        }
      } finally {
        inflight = null
      }
    })()
    await inflight
  }

  if (!localeWatchStarted) {
    localeWatchStarted = true
    watch(locale, () => {
      void load()
    })
  }

  const subjectName = (subject: string) =>
    subjects.value.find(node => node.subject === subject)?.name || subject || ''

  const countryName = (subject: string, country: string | null | undefined) => {
    if (!country) return ''
    const node = subjects.value.find(item => item.subject === subject)
    return node?.countries.find(item => item.country === country)?.name || country
  }

  const levelName = (
    subject: string,
    country: string | null | undefined,
    level: string | null | undefined
  ) => {
    if (!level) return ''
    const node = subjects.value.find(item => item.subject === subject)
    const inCountry = country
      ? node?.countries.find(item => item.country === country)?.levels.find(item => item.level === level)?.name
      : undefined
    const inSubject = node?.levels.find(item => item.level === level)?.name
    return inCountry || inSubject || level
  }

  return { subjects, load, levelName }
}
