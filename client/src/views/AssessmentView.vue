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
            <h1 data-testid="assessment-details-heading">{{ assessment.name || t('assessment.details') }}</h1>
            <p class="assessment-meta" data-testid="assessment-meta">
              <span data-testid="assessment-date-value">{{ assessment.date || '—' }}</span>,
              <span data-testid="assessment-subject-value">{{ subjectLabel(assessment.subject) }}</span><template
                v-if="assessment.level"
                >, <span data-testid="assessment-level-value">{{ levelLabel(assessment.subject, assessment.country, assessment.level) }}</span></template>
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
            <li v-for="student in studentRows" :key="student.id" class="entity-row" data-testid="student-item">
              <span class="entity-name">{{ student.name }}</span>
              <div class="row-actions">
                <MenuIconButton
                  v-for="item in student.menu ?? studentMenuFallback"
                  :key="item.key"
                  :item="item"
                  :test-id="`student-${item.key}`"
                  @click="onStudentAction(student, item.key)"
                />
              </div>
            </li>
          </ul>
        </section>

        <section class="section" data-testid="unassigned-files">
          <h2>{{ t('assessment.unassignedFiles') }}</h2>
          <AssessmentFileList
            :assessment-id="assessment.id || ''"
            :files="unassignedFiles"
            :students="students"
            :empty-text="t('assessment.unassignedEmpty')"
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
        <p data-testid="start-correction-price">
          {{ t('assessment.correctionPrice', { count: copyFiles.length }) }}
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
    @close="showAddCopies = false"
    @uploaded="onCopiesUploaded"
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
          <label class="file-action-label" for="rename-student-input">{{ t('assessment.studentRenamePlaceholder') }}</label>
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
          {{ isUpdatingStudent ? t('assessment.studentRenaming') : t('assessment.studentRenameSave') }}
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
          {{ isUpdatingStudent ? t('assessment.studentDeleting') : t('assessment.studentDelete') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import AddFilePopup from '@/components/AddFilePopup.vue'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import MenuIconButton from '@/components/MenuIconButton.vue'
import { useAssessment } from '@/composables/useAssessment'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { educationLevelName } from '@/data/levels'
import { isAssessmentSubject, type AssessmentFile, type AssessmentStudent, type MenuItem } from '@/types/types'
import { isDebugFile, isUnassignedFile } from '@/utils/assessmentFiles'

const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()
const { subjects, load: loadSubjects, levelName } = useSubjectCatalog()

const isDeleting = ref(false)
const isUpdatingStudent = ref(false)
const showAddCopies = ref(false)
const showStartCorrection = ref(false)
const isStartingCorrection = ref(false)
const startCorrectionError = ref('')
const studentActionError = ref('')
const renameStudent = ref<AssessmentStudent | null>(null)
const renameStudentDraft = ref('')
const renameStudentInput = ref<HTMLInputElement | null>(null)
const deleteStudentTarget = ref<AssessmentStudent | null>(null)

const subjectLabel = (subject: string) => {
  const node = subjects.value.find(item => item.subject === subject)
  if (node?.name) return node.name
  if (isAssessmentSubject(subject)) return t(`assessment.subjects.${subject}`)
  return subject || '—'
}

const levelLabel = (subject: string, country: string | null | undefined, level: string | null | undefined) => {
  if (!level) return '—'
  const fromEducation = country ? educationLevelName(country, level) : null
  if (fromEducation) return fromEducation
  return levelName(subject, country, level) || level
}

const unassignedFiles = computed(() =>
  files.value.filter((file) => isUnassignedFile(file, sessionStore.debugMode))
)

const copyFiles = computed(() =>
  files.value.filter((file) => (file.type ?? '') === 'submission')
)

const headerMenu = computed(() =>
  (assessment.value?.menu ?? []).filter((item) => false)
)

const deleteMenuItem = computed(() =>
  (assessment.value?.menu ?? []).find((item) => item.key === 'delete')
)

const pageMenuOrder = ['edit_subject', 'add_copies', 'start_correction']

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
  () => students.value.find((student) => student.menu?.length)?.menu ?? []
)

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
}

const onStudentAction = (student: AssessmentStudent, key: string) => {
  if (key === 'view') goStudent(student.id)
  else if (key === 'rename') startRenameStudent(student)
  else if (key === 'delete') startDeleteStudent(student)
}

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

const onFilesUpdated = (payload: { files: AssessmentFile[]; students?: AssessmentStudent[] }) => {
  applyUpdate(payload.files, payload.students)
}

const onCopiesUploaded = (updatedFiles: AssessmentFile[]) => {
  applyUpdate(updatedFiles)
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

const launchCorrection = async () => {
  if (!assessment.value?.id) return
  startCorrectionError.value = ''
  isStartingCorrection.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{ files?: AssessmentFile[]; students?: AssessmentStudent[] }>(
      'POST',
      '/assessment_correction',
      { id: assessment.value.id, locale: String(locale.value) }
    )
    if (response?.files) applyUpdate(response.files, response.students)
    showStartCorrection.value = false
  } catch (err) {
    console.error('Error starting correction:', err)
    startCorrectionError.value = t('assessment.startCorrectionError')
  } finally {
    isStartingCorrection.value = false
  }
}

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
    }>('PUT', '/student', { id: assessment.value.id, student: renameStudent.value.id, locale: String(locale.value) }, { name })
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
    }>('DELETE', '/student', { id: assessment.value.id, student: deleteStudentTarget.value.id, locale: String(locale.value) })
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

.assessment-meta {
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

.entity-list li:last-child .entity-row {
  border-bottom: none;
}

.entity-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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
