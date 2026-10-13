import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { useSessionStore, type LoadAssessmentOptions } from '@/stores/session'
import type { Assessment, AssessmentFile, AssessmentStats, AssessmentStudent } from '@/types/types'

const SUBJECT_FILE_TYPES = new Set(['subject', 'solution', 'instructions'])

type CacheScope = {
  files: 'all' | 'subject' | false
  students: boolean
}

function normalizeOptions(options?: LoadAssessmentOptions): CacheScope {
  return {
    files: options?.files ?? 'all',
    students: options?.students !== false,
  }
}

/** True when cached payload is at least as complete as the request. */
function scopeCovers(cached: CacheScope, requested: CacheScope): boolean {
  const filesOk =
    cached.files === 'all' ||
    requested.files === false ||
    cached.files === requested.files
  const studentsOk = cached.students || !requested.students
  return filesOk && studentsOk
}

function syncOwnAssessments(
  sessionStore: ReturnType<typeof useSessionStore>,
  next: Assessment,
): void {
  const id = next.id
  if (!id) return
  const index = sessionStore.own_assessments.findIndex((item) => item.id === id)
  if (index !== -1) {
    sessionStore.own_assessments[index] = {
      ...sessionStore.own_assessments[index],
      ...next,
    }
  }
}

/**
 * Current assessment environment (subject / student / hub pages).
 * Cached by assessment id: reuse while the route id matches; refetch when it changes.
 */
export const useAssessmentStore = defineStore('assessment', () => {
  const assessment = ref<Assessment | null>(null)
  const isLoading = ref(false)
  const error = ref('')
  const scope = ref<CacheScope>({ files: 'all', students: true })

  let inflight: { key: string; promise: Promise<Assessment | null> } | null = null

  const assessmentId = computed(() => assessment.value?.id ?? null)
  const files = computed(() => assessment.value?.files ?? [])
  const students = computed(() => assessment.value?.students ?? [])

  function clear(): void {
    assessment.value = null
    isLoading.value = false
    error.value = ''
    scope.value = { files: 'all', students: true }
    inflight = null
  }

  function setAssessment(next: Assessment, nextScope: CacheScope): void {
    assessment.value = next
    scope.value = nextScope
  }

  function patchAssessment(patch: Partial<Assessment>): void {
    if (!assessment.value) return
    assessment.value = { ...assessment.value, ...patch }
    syncOwnAssessments(useSessionStore(), assessment.value)
  }

  /** Full replace of files/students/stats (assessment hub / full loads). */
  function applyUpdate(
    nextFiles: AssessmentFile[],
    nextStudents?: AssessmentStudent[],
    stats?: AssessmentStats,
  ): void {
    if (!assessment.value) return
    const studentsValue = nextStudents ?? assessment.value.students ?? []
    const nextStats = stats ?? {}
    assessment.value = {
      ...assessment.value,
      ...nextStats,
      files: nextFiles,
      students: studentsValue,
    }
    syncOwnAssessments(useSessionStore(), assessment.value)
  }

  /**
   * Replace subject-area files only; keep submissions / other types when the cache is fuller.
   */
  function applySubjectFilesUpdate(
    nextFiles: AssessmentFile[],
    nextStudents?: AssessmentStudent[],
    stats?: AssessmentStats,
  ): void {
    if (!assessment.value) return
    if (scope.value.files !== 'all') {
      applyUpdate(nextFiles, nextStudents, stats)
      return
    }
    const kept = (assessment.value.files ?? []).filter(
      (file) => !SUBJECT_FILE_TYPES.has(file.type ?? ''),
    )
    applyUpdate([...kept, ...nextFiles], nextStudents, stats)
  }

  /**
   * Merge one student's files into the full assessment without dropping other students' files.
   */
  function applyStudentFilesUpdate(
    studentId: string,
    nextFiles: AssessmentFile[],
    nextStudents?: AssessmentStudent[],
    stats?: AssessmentStats,
  ): void {
    if (!assessment.value) return
    const scopedIncoming = nextFiles.filter((file) => (file.student ?? '') === studentId)
    const kept = (assessment.value.files ?? []).filter(
      (file) => (file.student ?? '') !== studentId,
    )

    let studentsValue = assessment.value.students ?? []
    if (nextStudents) {
      const byId = new Map(studentsValue.map((s) => [s.id, s]))
      for (const s of nextStudents) {
        byId.set(s.id, { ...byId.get(s.id), ...s })
      }
      studentsValue = [...byId.values()]
    }

    applyUpdate([...kept, ...scopedIncoming], studentsValue, stats)
  }

  /**
   * Merge assessment metadata + student-scoped files from GET /student into the cache.
   */
  function mergeStudentPayload(
    meta: Assessment,
    studentFiles: AssessmentFile[],
    studentList: AssessmentStudent[],
    studentId: string,
  ): void {
    const sessionStore = useSessionStore()
    if (!assessment.value || assessment.value.id !== meta.id) {
      setAssessment(
        {
          ...meta,
          files: studentFiles,
          students: studentList,
        },
        { files: 'all', students: true },
      )
      syncOwnAssessments(sessionStore, assessment.value!)
      return
    }

    assessment.value = {
      ...assessment.value,
      name: meta.name,
      subject: meta.subject,
      country: meta.country,
      level: meta.level,
      date: meta.date,
      author: meta.author,
      label: meta.label,
      menu: meta.menu,
      mark_average: meta.mark_average,
      mark_min: meta.mark_min,
      mark_max: meta.mark_max,
      assessed_students_number: meta.assessed_students_number,
    }
    applyStudentFilesUpdate(studentId, studentFiles, studentList)
  }

  async function fetchAssessment(
    id: string,
    options?: LoadAssessmentOptions,
  ): Promise<Assessment | null> {
    const sessionStore = useSessionStore()
    const requested = normalizeOptions(options)
    const loaded = await sessionStore.load_assessment(id, options)
    if (!loaded) {
      if (assessment.value?.id !== id) {
        assessment.value = null
      }
      return null
    }

    // Partial loads upsert carefully into own_assessments; prefer that merged entry
    // when the list already held a fuller copy of the same assessment.
    const listed = sessionStore.get_assessment(id)

    if (assessment.value?.id === id && scope.value.files === 'all' && requested.files !== 'all') {
      // Keep the fuller current context; subject page can filter locally.
      return assessment.value
    }

    if (listed && requested.files === 'all' && requested.students) {
      setAssessment(listed, requested)
      return assessment.value
    }

    if (
      listed &&
      assessment.value?.id === id &&
      scopeCovers(
        {
          files: (listed.files?.length ?? 0) >= (loaded.files?.length ?? 0) ? 'all' : requested.files,
          students:
            (listed.students?.length ?? 0) >= (loaded.students?.length ?? 0)
              ? true
              : requested.students,
        },
        requested,
      ) &&
      scope.value.files === 'all'
    ) {
      setAssessment(listed, scope.value)
      return assessment.value
    }

    setAssessment(loaded, requested)
    return assessment.value
  }

  /**
   * Reuse pinia assessment when route id matches and scope covers the request;
   * otherwise fetch and replace.
   */
  async function ensureAssessment(
    id: string,
    options?: LoadAssessmentOptions,
  ): Promise<Assessment | null> {
    const requested = normalizeOptions(options)

    if (assessment.value?.id === id && scopeCovers(scope.value, requested)) {
      error.value = ''
      return assessment.value
    }

    const requestKey = `${id}:${requested.files}:${requested.students}`
    if (inflight?.key === requestKey) {
      isLoading.value = true
      try {
        return await inflight.promise
      } finally {
        if (!inflight) {
          isLoading.value = false
        }
      }
    }

    isLoading.value = true
    error.value = ''

    const promise = fetchAssessment(id, options).catch((err) => {
      console.error('Error loading assessment:', err)
      if (!assessment.value || assessment.value.id !== id) {
        assessment.value = null
      }
      throw err
    })

    inflight = { key: requestKey, promise }
    try {
      return await promise
    } finally {
      if (inflight?.key === requestKey) {
        inflight = null
      }
      isLoading.value = false
    }
  }

  async function reload(options?: LoadAssessmentOptions): Promise<Assessment | null> {
    const id = assessment.value?.id
    if (!id) return null
    const opts = options ?? {
      files: scope.value.files,
      students: scope.value.students,
    }
    // Invalidate cache match so ensureAssessment refetches.
    scope.value = { files: false, students: false }
    return ensureAssessment(id, opts)
  }

  return {
    assessment,
    isLoading,
    error,
    scope,
    assessmentId,
    files,
    students,
    clear,
    setAssessment,
    patchAssessment,
    applyUpdate,
    applySubjectFilesUpdate,
    applyStudentFilesUpdate,
    mergeStudentPayload,
    ensureAssessment,
    reload,
  }
})
