import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import type { SubjectCountryNode, SubjectLevelNode, SubjectNode } from '@/types/types'

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

  const subjectNode = (subject: string): SubjectNode | undefined =>
    subjects.value.find(node => node.subject === subject)

  const subjectName = (subject: string) =>
    subjectNode(subject)?.name || subject || ''

  const countriesFor = (subject: string): SubjectCountryNode[] =>
    subjectNode(subject)?.countries ?? []

  const uniqueLevels = (...lists: SubjectLevelNode[][]): SubjectLevelNode[] => {
    const seen = new Set<string>()
    const out: SubjectLevelNode[] = []
    for (const list of lists) {
      for (const item of list) {
        if (seen.has(item.level)) continue
        seen.add(item.level)
        out.push(item)
      }
    }
    return out
  }

  const levelsFor = (subject: string, country: string | null | undefined): SubjectLevelNode[] => {
    const node = subjectNode(subject)
    if (!node) return []
    if (country) {
      const inCountry = node.countries.find(item => item.country === country)
      return uniqueLevels(node.levels, inCountry?.levels ?? [])
    }
    return uniqueLevels(node.levels, ...node.countries.map(item => item.levels))
  }

  const countryForLevel = (subject: string, level: string): string | null => {
    const matches = countriesFor(subject).filter(item =>
      item.levels.some(entry => entry.level === level)
    )
    return matches.length === 1 ? matches[0].country : null
  }

  const countryName = (subject: string, country: string | null | undefined) => {
    if (!country) return ''
    return countriesFor(subject).find(item => item.country === country)?.name || country
  }

  const levelName = (
    subject: string,
    country: string | null | undefined,
    level: string | null | undefined
  ) => {
    if (!level) return ''
    const node = subjectNode(subject)
    const inCountry = country
      ? node?.countries.find(item => item.country === country)?.levels.find(item => item.level === level)?.name
      : undefined
    const inSubject = node?.levels.find(item => item.level === level)?.name
    const anywhere = node?.countries
      .flatMap(item => item.levels)
      .find(item => item.level === level)?.name
    return inCountry || inSubject || anywhere || level
  }

  return { subjects, load, subjectName, countryName, levelName, countriesFor, levelsFor, countryForLevel }
}
