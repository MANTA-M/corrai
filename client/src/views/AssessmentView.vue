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
          <div class="header-actions">
            <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
            <button
              type="button"
              class="button edit-button"
              data-testid="assessment-edit"
              @click="goEdit"
            >
              {{ t('assessment.edit') }}
            </button>
            <button
              type="button"
              class="button delete-button"
              data-testid="assessment-delete"
              :disabled="isDeleting"
              @click="confirmDelete"
            >
              {{ isDeleting ? t('assessment.deleting') : t('assessment.delete') }}
            </button>
          </div>
        </div>

        <div class="page-actions">
          <button
            type="button"
            class="button secondary"
            data-testid="assessment-edit-subject"
            @click="goSubject"
          >
            {{ t('assessment.editSubject') }}
          </button>
          <button
            type="button"
            class="button primary"
            data-testid="assessment-add-file"
            @click="showAddCopies = true"
          >
            {{ t('assessment.addCopies') }}
          </button>
          <button
            v-if="copyFiles.length"
            type="button"
            class="button primary"
            data-testid="assessment-start-correction"
            @click="openStartCorrection"
          >
            {{ t('assessment.startCorrection') }}
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
                <button
                  type="button"
                  class="icon-button"
                  data-testid="student-view"
                  :aria-label="t('assessment.studentOpen')"
                  :title="t('assessment.studentOpen')"
                  @click="goStudent(student.id)"
                >
                  <ActionIcon name="eye" />
                </button>
                <button
                  type="button"
                  class="icon-button"
                  data-testid="student-rename"
                  :aria-label="t('assessment.studentRename')"
                  :title="t('assessment.studentRename')"
                  @click="startRenameStudent(student)"
                >
                  <ActionIcon name="pencil" />
                </button>
                <button
                  type="button"
                  class="icon-button danger"
                  data-testid="student-delete"
                  :aria-label="t('assessment.studentDelete')"
                  :title="t('assessment.studentDelete')"
                  @click="startDeleteStudent(student)"
                >
                  <ActionIcon name="trash" />
                </button>
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
import ActionIcon from '@/components/ActionIcon.vue'
import AddFilePopup from '@/components/AddFilePopup.vue'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import { useAssessment } from '@/composables/useAssessment'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { educationLevelName } from '@/data/levels'
import { isAssessmentSubject, type AssessmentFile, type AssessmentStudent } from '@/types/types'
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

const goEdit = () => {
  if (!assessment.value?.id) return
  router.push(`/assessment/${assessment.value.id}/edit`)
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
  if (!assessment.value?.id || !copyFiles.value.length) return
  startCorrectionError.value = ''
  isStartingCorrection.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    let latest: AssessmentFile[] | null = null
    for (const file of copyFiles.value) {
      const response = await wsClient.queryWs<{ files?: AssessmentFile[] }>(
        'POST',
        '/correction',
        { id: assessment.value.id, file: file.id }
      )
      if (response?.files) latest = response.files
    }
    if (latest) applyUpdate(latest)
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
    }>('PUT', '/student', { id: assessment.value.id, student: renameStudent.value.id }, { name })
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
    }>('DELETE', '/student', { id: assessment.value.id, student: deleteStudentTarget.value.id })
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
  flex-direction: column;
  align-items: flex-start;
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
  overflow: hidden;
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

.icon-button {
  width: 2rem;
  height: 2rem;
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

.icon-button:hover {
  background: var(--hover-bg);
  color: var(--text);
}

.icon-button.danger:hover {
  color: var(--danger);
}

.icon-button :deep(svg) {
  width: 1.15rem;
  height: 1.15rem;
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

.loading,
.error {
  padding: 1rem 0;
}
</style>
