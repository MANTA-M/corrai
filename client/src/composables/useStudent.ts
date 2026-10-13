import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { useAssessmentStore } from '@/stores/assessment'
import type { Assessment, AssessmentFile, AssessmentStats, AssessmentStudent } from '@/types/types'

/** Server assessment payload (uses user_id; client Assessment uses author). */
interface ServerAssessment {
  id: string
  user_id?: string
  author?: string
  name: string
  subject: string
  country?: string | null
  level?: string | null
  date: string
  assessed_students_number?: number | null
  mark_average?: number | null
  mark_min?: number | null
  mark_max?: number | null
  label?: string
  menu?: Assessment['menu']
}

function normalizeAssessment(
  raw: ServerAssessment,
  files: AssessmentFile[],
  students: AssessmentStudent[],
): Assessment {
  return {
    id: raw.id,
    author: raw.user_id ?? raw.author ?? '',
    name: raw.name,
    subject: raw.subject,
    country: raw.country || null,
    level: raw.level || null,
    date: raw.date,
    assessed_students_number:
      typeof raw.assessed_students_number === 'number' ? raw.assessed_students_number : undefined,
    mark_average: raw.mark_average ?? null,
    mark_min: raw.mark_min ?? null,
    mark_max: raw.mark_max ?? null,
    label: raw.label,
    menu: raw.menu,
    files,
    students,
  }
}

function filesForStudent(files: AssessmentFile[], studentId: string): AssessmentFile[] {
  return files.filter((file) => (file.student ?? '') === studentId)
}

/**
 * Load a single student page: shared assessment context via pinia + student-scoped files.
 */
export function useStudent() {
  const route = useRoute()
  const { t, locale } = useI18n()
  const sessionStore = useSessionStore()
  const assessmentStore = useAssessmentStore()
  const { assessment } = storeToRefs(assessmentStore)

  const isLoading = ref(true)
  const error = ref('')

  const assessmentId = computed(() => route.params.id as string)
  const studentId = computed(() => route.params.studentId as string)

  const files = computed(() =>
    filesForStudent(assessment.value?.files ?? [], studentId.value),
  )
  const students = computed(() =>
    (assessment.value?.students ?? []).filter((item) => item.id === studentId.value),
  )

  const applyUpdate = (
    nextFiles: AssessmentFile[],
    nextStudents?: AssessmentStudent[],
    stats?: AssessmentStats,
  ) => {
    assessmentStore.applyStudentFilesUpdate(
      studentId.value,
      nextFiles,
      nextStudents,
      stats,
    )
  }

  const loadStudent = async (hash: string, currentStudentId: string) => {
    isLoading.value = true
    error.value = ''
    try {
      // Hydrate shared assessment context when id changes or cache is empty.
      await assessmentStore.ensureAssessment(hash)

      const response = await sessionStore.getWsClient().queryWs<{
        assessment: ServerAssessment
        student?: AssessmentStudent
        files?: AssessmentFile[]
        students?: AssessmentStudent[]
      }>('GET', '/student', {
        id: hash,
        student: currentStudentId,
        locale: locale.value,
      })

      if (!response?.assessment?.id) {
        if (assessmentStore.assessment?.id !== hash) {
          assessmentStore.clear()
        }
        error.value = t('assessment.notFoundMessage', { hash })
        return
      }

      const loadedFiles = response.files ?? []
      const loadedStudents = response.students ?? (response.student ? [response.student] : [])
      const meta = normalizeAssessment(response.assessment, loadedFiles, loadedStudents)
      assessmentStore.mergeStudentPayload(meta, loadedFiles, loadedStudents, currentStudentId)
    } catch (err) {
      console.error('Error loading student:', err)
      if (!assessment.value) {
        error.value = t('assessment.error')
      }
    } finally {
      isLoading.value = false
    }
  }

  onMounted(() => {
    if (assessmentId.value && studentId.value) {
      void loadStudent(assessmentId.value, studentId.value)
    }
  })

  watch([assessmentId, studentId], ([newAssessmentId, newStudentId]) => {
    if (newAssessmentId && newStudentId) {
      // ensureAssessment inside loadStudent is a no-op when the assessment id is unchanged.
      void loadStudent(newAssessmentId, newStudentId)
    }
  })

  const reload = async () => {
    const id = assessment.value?.id || assessmentId.value
    if (!id || !studentId.value) return
    await loadStudent(id, studentId.value)
  }

  return {
    assessment,
    isLoading,
    error,
    assessmentId,
    studentId,
    files,
    students,
    applyUpdate,
    reload,
  }
}
