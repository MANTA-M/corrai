import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import type { Assessment, AssessmentFile, AssessmentStudent } from '@/types/types'

export function useAssessment() {
  const route = useRoute()
  const { t } = useI18n()
  const sessionStore = useSessionStore()

  const assessment = ref<Assessment | null>(null)
  const isLoading = ref(true)
  const error = ref('')

  const assessmentId = computed(() => route.params.id as string)
  const files = computed(() => assessment.value?.files ?? [])
  const students = computed(() => assessment.value?.students ?? [])

  const applyUpdate = (nextFiles: AssessmentFile[], nextStudents?: AssessmentStudent[]) => {
    if (!assessment.value) return
    const studentsValue = nextStudents ?? assessment.value.students ?? []
    assessment.value = {
      ...assessment.value,
      files: nextFiles,
      students: studentsValue,
    }
    const id = assessment.value.id
    if (!id) return
    const index = sessionStore.own_assessments.findIndex((item) => item.id === id)
    if (index !== -1) {
      sessionStore.own_assessments[index] = {
        ...sessionStore.own_assessments[index],
        files: nextFiles,
        students: studentsValue,
      }
    }
  }

  const loadAssessment = async (hash: string) => {
    isLoading.value = true
    error.value = ''
    try {
      const cached = sessionStore.get_assessment(hash)
      if (cached) {
        assessment.value = cached
      }
      const loaded = await sessionStore.load_assessment(hash)
      if (loaded) {
        assessment.value = loaded
      } else if (!cached) {
        assessment.value = null
        error.value = t('assessment.notFoundMessage', { hash })
      }
    } catch (err) {
      console.error('Error loading assessment:', err)
      if (!assessment.value) {
        error.value = t('assessment.error')
      }
    } finally {
      isLoading.value = false
    }
  }

  onMounted(() => {
    if (assessmentId.value) {
      loadAssessment(assessmentId.value)
    }
  })

  watch(assessmentId, (newId) => {
    if (newId) {
      loadAssessment(newId)
    }
  })

  return {
    assessment,
    isLoading,
    error,
    assessmentId,
    files,
    students,
    applyUpdate,
    loadAssessment,
  }
}
