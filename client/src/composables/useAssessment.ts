import { computed, onMounted, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { type LoadAssessmentOptions } from '@/stores/session'
import { useAssessmentStore } from '@/stores/assessment'
import type { AssessmentFile, AssessmentStats, AssessmentStudent } from '@/types/types'

const SUBJECT_FILE_TYPES = new Set(['subject', 'solution', 'instructions'])

export function useAssessment(options?: LoadAssessmentOptions) {
  const route = useRoute()
  const { t } = useI18n()
  const assessmentStore = useAssessmentStore()
  const { assessment, error } = storeToRefs(assessmentStore)

  const assessmentId = computed(() => route.params.id as string)

  /** Loading until the store holds this route's assessment (or a fetch finishes). */
  const isLoading = computed(
    () =>
      assessmentStore.isLoading ||
      (Boolean(assessmentId.value) && assessment.value?.id !== assessmentId.value),
  )

  const files = computed(() => {
    const all = assessment.value?.files ?? []
    if (options?.files === 'subject') {
      return all.filter((file) => SUBJECT_FILE_TYPES.has(file.type ?? ''))
    }
    return all
  })

  const students = computed(() => {
    if (options?.students === false) return []
    return assessment.value?.students ?? []
  })

  const applyUpdate = (
    nextFiles: AssessmentFile[],
    nextStudents?: AssessmentStudent[],
    stats?: AssessmentStats,
  ) => {
    if (options?.files === 'subject') {
      assessmentStore.applySubjectFilesUpdate(nextFiles, nextStudents, stats)
      return
    }
    assessmentStore.applyUpdate(nextFiles, nextStudents, stats)
  }

  const loadAssessment = async (hash: string) => {
    assessmentStore.error = ''
    try {
      const loaded = await assessmentStore.ensureAssessment(hash, options)
      if (!loaded) {
        assessmentStore.error = t('assessment.notFoundMessage', { hash })
      }
    } catch {
      if (!assessment.value) {
        assessmentStore.error = t('assessment.error')
      }
    }
  }

  onMounted(() => {
    if (assessmentId.value) {
      void loadAssessment(assessmentId.value)
    }
  })

  watch(assessmentId, (newId) => {
    if (newId) {
      void loadAssessment(newId)
    }
  })

  const reload = async () => {
    const id = assessment.value?.id || assessmentId.value
    if (!id) return
    try {
      await assessmentStore.reload(options)
    } catch {
      // keep previous assessment on failure
    }
  }

  return {
    assessment,
    isLoading,
    error,
    assessmentId,
    files,
    students,
    applyUpdate,
    reload,
  }
}
