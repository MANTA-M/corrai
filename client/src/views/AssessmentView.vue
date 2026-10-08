<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('assessment.loading') }}</p>
      </div>

      <div v-else-if="error && !assessment" class="error">
        <h1>{{ t('assessment.error') }}</h1>
        <p>{{ error }}</p>
        <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>

      <div v-else-if="assessment" class="assessment-view">
        <div class="header">
          <div>
            <div class="title-line">
              <h1 data-testid="assessment-details-heading">{{ assessment.name || t('assessment.details') }}</h1>
              <div
                v-if="sessionStore.debugMode"
                ref="debugMenuRoot"
                class="debug-menu"
                data-testid="assessment-debug-menu"
              >
                <button
                  type="button"
                  class="debug-menu-button"
                  data-testid="assessment-debug-menu-button"
                  :aria-label="assessment.name || t('assessment.details')"
                  :aria-expanded="debugMenuOpen"
                  @click="toggleDebugMenu"
                >
                  <ActionIcon name="caret" />
                </button>
                <ul v-if="debugMenuOpen" class="debug-menu-list" data-testid="assessment-debug-menu-list">
                  <li v-if="debugLoading" class="debug-menu-status">…</li>
                  <template v-else>
                    <li class="debug-menu-heading">{{ t('assessment.files') }}</li>
                    <li
                      v-if="rootObjects.length === 0"
                      class="debug-menu-status"
                      data-testid="assessment-debug-files-empty"
                    >
                      {{ t('assessment.fileAnnexesEmpty') }}
                    </li>
                    <li v-for="name in rootObjects" :key="name">
                      <S3File
                        :label="name"
                        :href="rootObjectUrl(name)"
                        :test-id="`assessment-s3-${name}`"
                      />
                    </li>
                    <li class="debug-menu-heading">{{ t('assessment.fileEventsTitle') }}</li>
                    <li
                      v-if="assessmentEvents.length === 0"
                      class="debug-menu-status"
                      data-testid="assessment-debug-events-empty"
                    >
                      {{ t('assessment.fileHistoryEmpty') }}
                    </li>
                    <li v-for="event in assessmentEvents" :key="event.id">
                      <S3File
                        :label="eventLabel(event)"
                        :href="eventUrl(event.id)"
                        test-id="assessment-event"
                      />
                    </li>
                  </template>
                </ul>
              </div>
            </div>
            <p class="assessment-meta" data-testid="assessment-meta">
              <template v-if="assessment.date"
                ><span data-testid="assessment-date-value">{{ assessment.date }}</span>, </template
              ><span data-testid="assessment-subject-value">{{ subjectLabel(assessment.subject) }}</span
              ><template v-if="assessment.level"
                >, <span data-testid="assessment-level-value">{{ levelLabel(assessment.subject, assessment.country, assessment.level) }}</span></template
              >
            </p>
            <p v-if="showAssessmentStats" class="assessment-stats" data-testid="assessment-stats">
              <span
                v-if="(assessment.assessed_students_number ?? 0) > 0"
                data-testid="assessed-students-number"
              >{{ t('assessment.assessedStudentsNumber', { count: assessment.assessed_students_number }) }}</span>
              <template v-if="hasMarkStats">
                <span v-if="(assessment.assessed_students_number ?? 0) > 0"> · </span>
                <span data-testid="mark-average">{{ t('assessment.markAverage', { mark: formatMark(displayedMarkAverage ?? 0) }) }}</span>
                <span> · </span>
                <span data-testid="mark-min">{{ t('assessment.markMin', { mark: formatMark(displayedMarkMin ?? 0) }) }}</span>
                <span> · </span>
                <span data-testid="mark-max">{{ t('assessment.markMax', { mark: formatMark(displayedMarkMax ?? 0) }) }}</span>
              </template>
            </p>
          </div>
        <div v-if="headerMenu.length" class="header-actions">
          <button
            v-for="item in headerMenu"
            :key="item.key"
            type="button"
            :class="textButtonClass(item)"
            :data-testid="assessmentTestId(item.key)"
            :disabled="item.key === 'delete' && isDeleting"
            @click="onAssessmentAction(item.key)"
          >
            {{ item.key === 'delete' && isDeleting ? t('assessment.deleting') : item.label }}
          </button>
        </div>
        </div>

        <div class="page-actions">
          <button
            v-for="item in pageMenu"
            :key="item.key"
            type="button"
            :class="textButtonClass(item)"
            :data-testid="assessmentTestId(item.key)"
            :disabled="((item.key === 'test_correction' || item.key === 'start_correction') && isStartingCorrection) || (item.key === 'create_correction_grid' && isCreatingCorrectionGrid)"
            @click="onAssessmentAction(item.key)"
          >
            {{ item.label }}
          </button>
        </div>

        <p v-if="error" class="error-message">{{ error }}</p>

        <section class="section" data-testid="student-section">
          <h2>{{ t('assessment.studentsHeading') }}</h2>
          <p v-if="!studentRows.length" class="zone-empty" data-testid="students-empty">
            {{ t('assessment.studentsEmpty') }}
          </p>
          <ul v-else class="entity-list" data-testid="student-list">
            <li
              v-for="student in studentRows"
              :key="student.id"
              class="entity-row student-row"
              :class="{ 'is-loading': student.loading }"
              data-testid="student-item"
              role="button"
              tabindex="0"
              @click="goStudent(student.id)"
              @keydown.enter="goStudent(student.id)"
              @keydown.space.prevent="goStudent(student.id)"
            >
              <span class="entity-name">{{ student.name }}</span>
              <span
                v-if="student.loading"
                class="student-loading"
                data-testid="student-loading"
                aria-label="Loading"
              >
                <span class="loading-spinner"></span>
              </span>
              <span
                v-if="student.status"
                class="student-status"
                data-testid="student-status"
              >{{ sessionStore.stateLabel(sessionStore.studentStates, student.status, student.status_label) }}</span>
              <span
                v-if="student.mark != null"
                class="student-mark"
                data-testid="student-mark"
              >{{ formatMark(student.mark) }}</span>
              <div class="row-actions" @click.stop @keydown.stop>
                <MenuIconButton
                  v-for="item in studentMenu(student)"
                  :key="item.key"
                  :item="item"
                  :test-id="`student-${item.key}`"
                  @click="onStudentAction(student, item.key)"
                />
              </div>
            </li>
          </ul>
        </section>

        <section
          v-if="unassignedFiles.length > 0"
          class="section"
          data-testid="unassigned-files"
        >
          <h2>{{ t('assessment.unassignedFiles') }}</h2>
          <AssessmentFileList
            :assessment-id="assessment.id || ''"
            :files="unassignedFiles"
            :students="students"
            :empty-text="t('assessment.unassignedEmpty')"
            cards
            @updated="onFilesUpdated"
          />
        </section>

        <div v-if="deleteMenuItem" class="assessment-danger-zone">
          <button
            type="button"
            :class="textButtonClass(deleteMenuItem)"
            :data-testid="assessmentTestId(deleteMenuItem.key)"
            :disabled="isDeleting"
            @click="onAssessmentAction(deleteMenuItem.key)"
          >
            {{ isDeleting ? t('assessment.deleting') : deleteMenuItem.label }}
          </button>
        </div>
      </div>

      <div v-else class="error">
        <h1>{{ t('assessment.notFound') }}</h1>
        <p>{{ t('assessment.notFoundMessage', { hash: assessmentId }) }}</p>
        <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>
    </div>
  </div>

  <div
    v-if="showStartCorrection"
    class="popup-overlay"
    data-testid="start-correction-popup"
    @click.self="closeStartCorrection"
  >
    <div class="popup-content file-action-popup">
      <div class="popup-header">
        <h2>{{ t('assessment.startCorrection') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="start-correction-close"
          :aria-label="t('common.cancel')"
          @click="closeStartCorrection"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <p v-if="showCorrectionPrice" data-testid="start-correction-price">
          {{ t('assessment.correctionPrice', { count: copyFiles.length, price: correctionUnitPrice }) }}
        </p>
        <p v-if="startCorrectionError" class="error-message">{{ startCorrectionError }}</p>
      </div>
      <div class="popup-footer">
        <button type="button" class="button secondary" :disabled="isStartingCorrection" @click="closeStartCorrection">
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button primary"
          data-testid="start-correction-launch"
          :disabled="isStartingCorrection"
          @click="launchCorrection"
        >
          {{ t('assessment.startCorrectionLaunch') }}
        </button>
      </div>
    </div>
  </div>

  <AddFilePopup
    v-if="showAddCopies && assessment?.id"
    :assessment-id="assessment.id"
    fixed-type="submission"
    :title="t('assessment.addCopies')"
    auto-close
    @close="closeAddCopies"
    @uploaded="onCopiesUploaded"
    @all-uploaded="onAllCopiesUploaded"
  />

  <div
    v-if="renameStudent"
    class="popup-overlay"
    data-testid="rename-student-popup"
    @click.self="closeRenameStudent"
  >
    <div class="popup-content file-action-popup">
      <div class="popup-header">
        <h2>{{ t('assessment.studentRenameTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="rename-student-close"
          :aria-label="t('common.cancel')"
          @click="closeRenameStudent"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <form @submit.prevent="submitRenameStudent">
          <label class="file-action-label" for="rename-student-input">{{ t('assessment.studentNamePlaceholder') }}</label>
          <input
            id="rename-student-input"
            ref="renameStudentInput"
            v-model="renameStudentDraft"
            type="text"
            class="input"
            data-testid="rename-student-input"
            :disabled="isUpdatingStudent"
          />
        </form>
        <p v-if="studentActionError" class="error-message">{{ studentActionError }}</p>
      </div>
      <div class="popup-footer">
        <button type="button" class="button secondary" :disabled="isUpdatingStudent" @click="closeRenameStudent">
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button primary"
          data-testid="rename-student-save"
          :disabled="isUpdatingStudent || !renameStudentDraft.trim()"
          @click="submitRenameStudent"
        >
          {{ isUpdatingStudent ? t('assessment.renaming') : t('assessment.rename') }}
        </button>
      </div>
    </div>
  </div>

  <div
    v-if="deleteStudentTarget"
    class="popup-overlay"
    data-testid="delete-student-popup"
    @click.self="closeDeleteStudent"
  >
    <div class="popup-content file-action-popup">
      <div class="popup-header">
        <h2>{{ t('assessment.studentDeleteTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          :aria-label="t('common.cancel')"
          @click="closeDeleteStudent"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <p data-testid="delete-student-confirm">
          {{ t('assessment.studentDeleteConfirm', { name: deleteStudentTarget.name }) }}
        </p>
        <p v-if="studentActionError" class="error-message">{{ studentActionError }}</p>
      </div>
      <div class="popup-footer">
        <button
          type="button"
          class="button secondary"
          data-testid="delete-student-cancel"
          :disabled="isUpdatingStudent"
          @click="closeDeleteStudent"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button danger"
          data-testid="delete-student-confirm-button"
          :disabled="isUpdatingStudent"
          @click="submitDeleteStudent"
        >
          {{ isUpdatingStudent ? t('assessment.deleting') : t('common.delete') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { toast } from 'vue3-toastify'
import ActionIcon from '@/components/ActionIcon.vue'
import AddFilePopup from '@/components/AddFilePopup.vue'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import MenuIconButton from '@/components/MenuIconButton.vue'
import S3File from '@/components/S3File.vue'
import { useAssessment } from '@/composables/useAssessment'
import { useAssessmentStream } from '@/composables/useAssessmentStream'
import { applyAssessmentStream } from '@/utils/assessmentStream'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { educationLevelName } from '@/data/levels'
import { isAssessmentSubject, type AssessmentFile, type AssessmentStats, type AssessmentStudent, type MenuItem, type StateLocales } from '@/types/types'
import { isDebugFile, isUnassignedFile } from '@/utils/assessmentFiles'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()
const streamEnabled = computed(() => Boolean(assessment.value?.id))

useAssessmentStream({
  assessmentId,
  locale,
  enabled: streamEnabled,
  onEvent: (event) => {
    if (event.scope !== 'assessment' || !assessment.value) return
    const merged = applyAssessmentStream(files.value, students.value, event)
    const stats: AssessmentStats = {}
    if ('assessed_students_number' in event) stats.assessed_students_number = event.assessed_students_number
    if ('mark_average' in event) stats.mark_average = event.mark_average
    if ('mark_min' in event) stats.mark_min = event.mark_min
    if ('mark_max' in event) stats.mark_max = event.mark_max
    applyUpdate(merged.files, merged.students, Object.keys(stats).length > 0 ? stats : undefined)
  },
})
const { subjects, load: loadSubjects, levelName } = useSubjectCatalog()

const isDeleting = ref(false)
const isUpdatingStudent = ref(false)
const showAddCopies = ref(false)
const showStartCorrection = ref(false)
const isStartingCorrection = ref(false)
const isCreatingCorrectionGrid = ref(false)
const startCorrectionError = ref('')
const studentActionError = ref('')
const renameStudent = ref<AssessmentStudent | null>(null)
const renameStudentDraft = ref('')
const renameStudentInput = ref<HTMLInputElement | null>(null)
const deleteStudentTarget = ref<AssessmentStudent | null>(null)
const debugMenuRoot = ref<HTMLElement | null>(null)
const debugMenuOpen = ref(false)
const debugLoading = ref(false)
const rootObjects = ref<string[]>([])
const assessmentEvents = ref<AssessmentEvent[]>([])

interface AssessmentEvent {
  id: string
  timestamp: number
  name: string
}

const subjectLabel = (subject: string) => {
  const node = subjects.value.find(item => item.subject === subject)
  if (node?.name) return node.name
  if (isAssessmentSubject(subject)) return t(`assessment.subjects.${subject}`)
  return subject || '—'
}

const levelLabel = (subject: string, country: string | null | undefined, level: string | null | undefined) => {
  if (!level) return ''
  const fromCatalog = levelName(subject, country, level)
  if (fromCatalog && fromCatalog !== level) return fromCatalog
  const fromEducation = country ? educationLevelName(country, level) : null
  if (fromEducation) return fromEducation
  return fromCatalog || level
}

const unassignedFiles = computed(() =>
  files.value.filter((file) => isUnassignedFile(file, sessionStore.debugMode))
)

const copyFiles = computed(() =>
  files.value.filter((file) => (file.type ?? '') === 'submission' && (file.status ?? '') !== 'corrected')
)

const effectiveDiscountRate = computed(() => {
  const discount = sessionStore.discountRate
  return Number.isInteger(discount) && discount >= 0 && discount <= 100 ? discount : 0
})

const showCorrectionPrice = computed(() => {
  const rate = effectiveDiscountRate.value
  if (rate === 100) return false
  const unitCents = Math.floor((100 * (100 - rate)) / 100)
  return unitCents >= 50
})

const correctionUnitPrice = computed(() => {
  const rate = effectiveDiscountRate.value
  return ((100 - rate) / 100).toLocaleString(String(locale.value), {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })
})

const headerMenu = computed(() =>
  (assessment.value?.menu ?? []).filter((item) => false)
)

const deleteMenuItem = computed(() =>
  (assessment.value?.menu ?? []).find((item) => item.key === 'delete')
)

const pageMenuOrder = ['edit_subject', 'add_copies', 'create_correction_grid', 'start_correction', 'test_correction']

const pageMenu = computed(() => {
  const items = (assessment.value?.menu ?? []).filter((item) => item.key !== 'edit' && item.key !== 'delete')
  return [...items].sort((a, b) => {
    const indexA = pageMenuOrder.indexOf(a.key)
    const indexB = pageMenuOrder.indexOf(b.key)
    const orderA = indexA === -1 ? Number.MAX_SAFE_INTEGER : indexA
    const orderB = indexB === -1 ? Number.MAX_SAFE_INTEGER : indexB
    return orderA - orderB
  })
})

const studentMenuFallback = computed(
  () =>
    (students.value.find((student) => student.menu?.length)?.menu ?? []).filter(
      (item) => item.key !== 'view'
    )
)

const studentMenu = (student: AssessmentStudent) =>
  (student.menu ?? studentMenuFallback.value).filter((item) => item.key !== 'view')

const assessmentTestId = (key: string) =>
  key === 'add_copies' ? 'assessment-add-file' : `assessment-${key.replace(/_/g, '-')}`

const textButtonClass = (item: MenuItem) => {
  if (item.key === 'delete' || item.color === '#c93b45') return 'button delete'
  if (item.color === '#1a55e8') return 'button primary'
  return 'button secondary'
}

const onAssessmentAction = (key: string) => {
  if (key === 'delete') void confirmDelete()
  else if (key === 'edit_subject') goSubject()
  else if (key === 'add_copies') showAddCopies.value = true
  else if (key === 'start_correction') openStartCorrection()
  else if (key === 'test_correction') void launchTestCorrection()
  else if (key === 'create_correction_grid') void createCorrectionGrid()
}

const onStudentAction = (student: AssessmentStudent, key: string) => {
  if (key === 'view') goStudent(student.id)
  else if (key === 'transcribe') void transcribeStudent(student)
  else if (key === 'correct') void correctStudent(student)
  else if (key === 'rename') startRenameStudent(student)
  else if (key === 'delete') startDeleteStudent(student)
}

const formatMark = (mark: number) =>
  new Intl.NumberFormat(String(locale.value), { maximumFractionDigits: 2 }).format(mark)

const studentMarks = computed(() =>
  students.value
    .map((student) => student.mark)
    .filter((mark): mark is number => typeof mark === 'number' && Number.isFinite(mark))
)

const displayedMarkAverage = computed(() => {
  const marks = studentMarks.value
  if (marks.length === 0) return assessment.value?.mark_average ?? null
  return marks.reduce((sum, mark) => sum + mark, 0) / marks.length
})

const displayedMarkMin = computed(() => {
  const marks = studentMarks.value
  if (marks.length === 0) return assessment.value?.mark_min ?? null
  return Math.min(...marks)
})

const displayedMarkMax = computed(() => {
  const marks = studentMarks.value
  if (marks.length === 0) return assessment.value?.mark_max ?? null
  return Math.max(...marks)
})

const hasMarkStats = computed(() =>
  displayedMarkAverage.value != null
  && displayedMarkMin.value != null
  && displayedMarkMax.value != null
)

const showAssessmentStats = computed(() =>
  (assessment.value?.assessed_students_number ?? 0) > 0 || hasMarkStats.value
)

const studentRows = computed(() => {
  const byId = new Map<string, AssessmentStudent>()
  for (const student of students.value) {
    byId.set(student.id, student)
  }
  for (const file of files.value) {
    if (isDebugFile(file) && !sessionStore.debugMode) continue
    const id = (file.student ?? '').trim()
    if (!id || byId.has(id)) continue
    byId.set(id, { id, name: (file.student_name ?? '').trim() || id })
  }
  return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name, String(locale.value || 'fr')))
})

const goBack = () => {
  router.push({ name: 'assessment-list' })
}

const goSubject = () => {
  if (!assessment.value?.id) return
  router.push({ name: 'assessment-subject', params: { id: assessment.value.id } })
}

const goStudent = (studentId: string) => {
  if (!assessment.value?.id) return
  router.push({ name: 'assessment-student', params: { id: assessment.value.id, studentId } })
}

const onFilesUpdated = (payload: { files: AssessmentFile[]; students?: AssessmentStudent[] } & AssessmentStats) => {
  const { files, students, ...stats } = payload
  applyUpdate(files, students, stats)
}

const reloadAssessment = async () => {
  const id = assessment.value?.id || assessmentId.value
  if (!id) return
  const loaded = await sessionStore.load_assessment(id)
  if (loaded) {
    assessment.value = loaded
  }
}

const closeAddCopies = async () => {
  showAddCopies.value = false
  await reloadAssessment()
}

const onCopiesUploaded = (updatedFiles: AssessmentFile[]) => {
  applyUpdate(updatedFiles)
}

const onAllCopiesUploaded = async (updatedFiles: AssessmentFile[]) => {
  showAddCopies.value = false
  if (updatedFiles.length > 0) {
    applyUpdate(updatedFiles)
  }
  await reloadAssessment()
}

const openStartCorrection = () => {
  startCorrectionError.value = ''
  showStartCorrection.value = true
}

const closeStartCorrection = () => {
  if (isStartingCorrection.value) return
  showStartCorrection.value = false
  startCorrectionError.value = ''
}

const checkoutReturnHandled = ref(false)

const createCorrectionGrid = async () => {
  if (!assessment.value?.id || isCreatingCorrectionGrid.value) return
  isCreatingCorrectionGrid.value = true
  error.value = ''
  try {
    await sessionStore.getWsClient().queryWs('POST', '/assessment_correction_grid', {
      id: assessment.value.id,
      locale: String(locale.value),
    })
    toast.success(t('assessment.correctionGridSuccess'), {
      position: toast.POSITION.TOP_CENTER,
    })
  } catch (err) {
    console.error('Error creating correction grid:', err)
    error.value = t('assessment.correctionGridError')
  } finally {
    isCreatingCorrectionGrid.value = false
  }
}

const launchTestCorrection = async () => {
  if (!assessment.value?.id || isStartingCorrection.value) return
  startCorrectionError.value = ''
  isStartingCorrection.value = true
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>('POST', '/assessment_test_correction', {
      id: assessment.value.id,
      locale: String(locale.value),
    })
    if (response?.files) {
      applyUpdate(response.files, response.students)
      toast.success(t('assessment.startCorrectionSuccess'), {
        position: toast.POSITION.TOP_CENTER,
      })
      return
    }
    startCorrectionError.value = t('assessment.startCorrectionError')
  } catch (err) {
    console.error('Error starting test correction:', err)
    startCorrectionError.value = t('assessment.startCorrectionError')
    error.value = t('assessment.startCorrectionError')
  } finally {
    isStartingCorrection.value = false
  }
}

const launchCorrection = async () => {
  if (!assessment.value?.id) return
  startCorrectionError.value = ''
  isStartingCorrection.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{
      url?: string | null
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>('POST', '/assessment_checkout', {
      id: assessment.value.id,
      locale: String(locale.value),
    })
    if (response?.url) {
      window.location.assign(response.url)
      return
    }
    if (response?.files) {
      applyUpdate(response.files, response.students)
      showStartCorrection.value = false
      toast.success(t('assessment.startCorrectionSuccess'), {
        position: toast.POSITION.TOP_CENTER,
      })
      isStartingCorrection.value = false
      return
    }
    startCorrectionError.value = t('assessment.startCorrectionError')
    isStartingCorrection.value = false
  } catch (err) {
    console.error('Error starting correction:', err)
    startCorrectionError.value = t('assessment.startCorrectionError')
    isStartingCorrection.value = false
  }
}

const handleCheckoutReturn = async () => {
  const id = assessmentId.value
  if (!id) return
  const checkout = String(route.query.checkout ?? '')
  const sessionId = typeof route.query.session_id === 'string' ? route.query.session_id : ''
  if (checkout === 'cancel') {
    startCorrectionError.value = t('assessment.startCorrectionCancelled')
    showStartCorrection.value = true
    await router.replace({ name: 'assessment', params: { id }, query: {} })
    return
  }
  if (sessionId === '') return

  isStartingCorrection.value = true
  startCorrectionError.value = ''
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>('POST', '/assessment_checkout_confirm', {
      id,
      session_id: sessionId,
      locale: String(locale.value),
    })
    if (response?.files) applyUpdate(response.files, response.students)
    toast.success(t('assessment.startCorrectionSuccess'), {
      position: toast.POSITION.TOP_CENTER,
    })
  } catch (err) {
    console.error('Error confirming correction payment:', err)
    startCorrectionError.value = t('assessment.startCorrectionPaymentError')
    showStartCorrection.value = true
  } finally {
    isStartingCorrection.value = false
    await router.replace({ name: 'assessment', params: { id }, query: {} })
  }
}

watch(isLoading, (loading) => {
  if (loading || checkoutReturnHandled.value) return
  const checkout = String(route.query.checkout ?? '')
  const sessionId = typeof route.query.session_id === 'string' ? route.query.session_id : ''
  if (checkout !== 'cancel' && sessionId === '') return
  checkoutReturnHandled.value = true
  void handleCheckoutReturn()
})

const startRenameStudent = (student: AssessmentStudent) => {
  renameStudent.value = student
  renameStudentDraft.value = student.name
  studentActionError.value = ''
  void nextTick(() => {
    renameStudentInput.value?.focus()
    renameStudentInput.value?.select()
  })
}

const closeRenameStudent = () => {
  if (isUpdatingStudent.value) return
  renameStudent.value = null
  studentActionError.value = ''
}

const startDeleteStudent = (student: AssessmentStudent) => {
  deleteStudentTarget.value = student
  studentActionError.value = ''
}

const closeDeleteStudent = () => {
  if (isUpdatingStudent.value) return
  deleteStudentTarget.value = null
  studentActionError.value = ''
}

const submitRenameStudent = async () => {
  if (!assessment.value?.id || !renameStudent.value) return
  const name = renameStudentDraft.value.trim()
  if (!name) return
  studentActionError.value = ''
  isUpdatingStudent.value = true
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    } & StateLocales>('PUT', '/student', { id: assessment.value.id, student: renameStudent.value.id, locale: String(locale.value) }, { name })
    sessionStore.applyStateLocales(response)
    if (response?.files) {
      applyUpdate(response.files, response.students)
    }
    renameStudent.value = null
  } catch (err) {
    console.error('Error renaming student:', err)
    studentActionError.value = t('assessment.studentRenameError')
  } finally {
    isUpdatingStudent.value = false
  }
}

const submitDeleteStudent = async () => {
  if (!assessment.value?.id || !deleteStudentTarget.value) return
  studentActionError.value = ''
  isUpdatingStudent.value = true
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    } & StateLocales>('DELETE', '/student', { id: assessment.value.id, student: deleteStudentTarget.value.id, locale: String(locale.value) })
    sessionStore.applyStateLocales(response)
    if (response?.files) {
      applyUpdate(response.files, response.students)
    }
    deleteStudentTarget.value = null
  } catch (err) {
    console.error('Error deleting student:', err)
    studentActionError.value = t('assessment.studentDeleteError')
  } finally {
    isUpdatingStudent.value = false
  }
}

const transcribeStudent = async (student: AssessmentStudent) => {
  if (!assessment.value?.id || !student.id) return
  studentActionError.value = ''
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    } & StateLocales>('POST', '/student_transcribe', {
      id: assessment.value.id,
      student: student.id,
      locale: String(locale.value),
    })
    sessionStore.applyStateLocales(response)
    if (response?.files) {
      applyUpdate(response.files, response.students)
    }
  } catch (err) {
    console.error('Error transcribing student:', err)
  }
}

const correctStudent = async (student: AssessmentStudent) => {
  if (!assessment.value?.id || !student.id) return
  studentActionError.value = ''
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    } & StateLocales>('POST', '/student_correct', {
      id: assessment.value.id,
      student: student.id,
      locale: String(locale.value),
    })
    sessionStore.applyStateLocales(response)
    if (response?.files) {
      applyUpdate(response.files, response.students)
    }
  } catch (err) {
    console.error('Error correcting student:', err)
  }
}

const confirmDelete = async () => {
  if (!assessment.value?.id) return
  if (!confirm(t('assessment.deleteConfirm'))) return

  isDeleting.value = true
  error.value = ''
  try {
    const wsClient = sessionStore.getWsClient()
    await wsClient.queryWs('DELETE', '/assessment', { hash: assessment.value.id })
    sessionStore.remove_assessment(assessment.value.id)
    router.push({ name: 'assessment-list' })
  } catch (err) {
    console.error('Error deleting assessment:', err)
    error.value = t('assessment.deleteError')
  } finally {
    isDeleting.value = false
  }
}

const toggleDebugMenu = () => {
  debugMenuOpen.value = !debugMenuOpen.value
  if (debugMenuOpen.value) {
    void loadDebugMenu()
  }
}

const loadDebugMenu = async () => {
  if (!assessment.value?.id) return
  debugLoading.value = true
  try {
    const data = await sessionStore.getWsClient().queryWs<{
      objects?: string[]
      events?: AssessmentEvent[]
    }>('GET', '/assessment_debug', {
      hash: assessment.value.id,
      locale: String(locale.value),
    })
    if (!debugMenuOpen.value) return
    rootObjects.value = (data.objects ?? []).filter((name) => isDebugObjectName(name))
    assessmentEvents.value = data.events ?? []
  } catch (err) {
    console.error('Error loading assessment debug menu:', err)
    if (debugMenuOpen.value) {
      rootObjects.value = []
      assessmentEvents.value = []
    }
  } finally {
    if (debugMenuOpen.value) {
      debugLoading.value = false
    }
  }
}

const isDebugObjectName = (name: string) => {
  const parts = name.split('/')
  if (parts.some((part) => part === '' || part === '.' || part === '..')) return false
  return parts.length === 1 || (parts.length === 2 && parts[0] === 'subject')
}

const rootObjectUrl = (name: string) =>
  sessionStore.getWsClient().getWsUrl('/assessment_object', {
    hash: assessment.value?.id ?? '',
    object: name,
  })

const eventUrl = (eventId: string) =>
  sessionStore.getWsClient().getWsUrl('/assessment_object', {
    hash: assessment.value?.id ?? '',
    event: eventId,
  })

const eventLabel = (event: AssessmentEvent) => {
  const name = event.name === 'Stored' || event.name === 'Loaded'
    ? t('assessment.fileStored')
    : event.name
  if (!event.timestamp) return name
  const when = new Intl.DateTimeFormat(String(locale.value || 'fr'), {
    dateStyle: 'short',
    timeStyle: 'medium',
  }).format(new Date(event.timestamp * 1000))
  return `${name} — ${when}`
}

const closeDebugMenuOnOutside = (event: MouseEvent) => {
  if (!debugMenuOpen.value) return
  const target = event.target
  if (!(target instanceof Node) || !debugMenuRoot.value?.contains(target)) {
    debugMenuOpen.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', closeDebugMenuOnOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', closeDebugMenuOnOutside)
})

loadSubjects()
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}

.header h1 {
  margin: 0;
}

.title-line {
  display: flex;
  align-items: flex-start;
  gap: 0.25rem;
}

.debug-menu {
  position: relative;
  flex-shrink: 0;
}

.debug-menu-button {
  width: 1.7rem;
  height: 1.7rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: var(--text-muted);
  border-radius: var(--radius-sm);
  cursor: pointer;
  padding: 0;
}

.debug-menu-button:hover,
.debug-menu-button:focus-visible {
  background: var(--hover-bg);
  color: var(--text);
}

.debug-menu-button :deep(svg) {
  width: 1.1rem;
  height: 1.1rem;
}

.debug-menu-list {
  position: absolute;
  top: calc(100% + 0.15rem);
  left: 0;
  z-index: 6;
  list-style: none;
  margin: 0;
  padding: 0.25rem;
  min-width: 16rem;
  max-width: 24rem;
  max-height: 24rem;
  overflow: auto;
  background: var(--surface, var(--bg, #fff));
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  box-shadow: 0 8px 24px rgb(20 24 40 / 12%);
}

.debug-menu-heading {
  padding: 0.35rem 0.55rem 0.15rem;
  color: var(--text-muted);
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.debug-menu-status {
  padding: 0.35rem 0.55rem;
  color: var(--text-muted);
  font-size: 0.85rem;
}

.assessment-meta,
.assessment-stats {
  margin: 0.35rem 0 0;
  color: var(--text-muted);
  font-size: 0.85rem;
  line-height: 1.4;
}

.header-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.page-actions {
  display: flex;
  flex-direction: row;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.75rem;
  margin-top: 1.25rem;
}

.section {
  margin-top: 1.75rem;
}

.section h2 {
  margin: 0 0 0.75rem;
  font-size: 1.05rem;
}

.zone-empty {
  color: var(--text-muted);
  margin: 0;
}

.entity-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--border);
  border-radius: 15px;
  overflow: visible;
}

.entity-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.45rem 0.65rem;
  border-bottom: 1px solid var(--border);
}

.entity-list li:last-child {
  border-bottom: none;
}

.student-row {
  cursor: pointer;
  transition: background-color 0.15s ease;
}

.student-row:hover,
.student-row:focus-visible {
  background-color: var(--pale);
}

.student-row:first-child {
  border-top-left-radius: 14px;
  border-top-right-radius: 14px;
}

.student-row:last-child {
  border-bottom-left-radius: 14px;
  border-bottom-right-radius: 14px;
}

.entity-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.student-status {
  flex-shrink: 0;
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--info) 15%, transparent);
  color: var(--info);
  font-size: 0.85rem;
}

.student-mark {
  flex-shrink: 0;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
}

.row-actions {
  display: flex;
  align-items: center;
  gap: 0.1rem;
  flex-shrink: 0;
}

.file-action-popup {
  width: min(440px, calc(100vw - 2rem));
}

.file-action-popup h2 {
  margin: 0;
  font-size: 1.15rem;
}

.file-action-label {
  display: block;
  margin-bottom: 0.4rem;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.error-message {
  color: var(--danger);
  margin-top: 1rem;
}

.assessment-danger-zone {
  display: flex;
  justify-content: flex-end;
  margin-top: 3rem;
  padding-top: 1.5rem;
  border-top: 1px solid var(--border);
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
