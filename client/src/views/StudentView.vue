<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('assessment.loading') }}</p>
      </div>

      <div v-else-if="error && !assessment" class="error">
        <h1>{{ t('assessment.error') }}</h1>
        <p>{{ error }}</p>
        <button class="button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>

      <div v-else-if="assessment && studentName" class="student-page" data-testid="student-page">
        <div class="header">
          <div>
            <h1 data-testid="student-name">{{ studentName }}</h1>
            <p class="student-state" data-testid="student-state">
              <span class="result-label">{{ t('assessment.status') }}</span>
              <span class="student-state-value">
                <span
                  v-if="isActive"
                  class="student-activity"
                  data-testid="student-loading"
                  role="status"
                  aria-label="Loading"
                >
                  <span class="activity-bar"></span>
                  <span class="activity-bar"></span>
                  <span class="activity-bar"></span>
                </span>
                <span v-if="statusLabel" class="student-status" data-testid="student-status">
                  {{ statusLabel }}
                </span>
              </span>
            </p>
          </div>
          <div class="header-actions">
            <button
              v-for="item in studentMenu"
              :key="item.key"
              type="button"
              :class="textButtonClass(item)"
              :data-testid="`student-action-${item.key}`"
              :disabled="isUpdatingStudent && (item.key === 'rename' || item.key === 'delete')"
              @click="onStudentAction(item.key)"
            >
              {{ item.label }}
            </button>
            <button type="button" class="button" data-testid="student-back" @click="goBack">
              {{ t('assessment.back') }}
            </button>
          </div>
        </div>

        <section v-if="hasResult" class="section student-result" data-testid="student-result">
          <p v-if="studentMark != null" class="student-mark" data-testid="student-mark">
            <span class="result-label">{{ t('assessment.studentMark') }}</span>
            {{ formatMark(studentMark) }}
          </p>
          <div v-if="studentAppreciation" data-testid="student-appreciation">
            <h2>{{ t('assessment.studentAppreciation') }}</h2>
            <div class="appreciation" v-html="appreciationHtml"></div>
          </div>
        </section>

        <section class="section" data-testid="student-copies">
          <h2>{{ t('assessment.copies') }}</h2>
          <AssessmentFileList
            cards
            :assessment-id="assessment.id || ''"
            :files="copyFiles"
            :students="students"
            :empty-text="t('assessment.copiesEmpty')"
            @updated="onFilesUpdated"
          />
        </section>

        <section class="section" data-testid="student-results">
          <h2>{{ t('assessment.results') }}</h2>
          <p v-if="!resultFiles.length" class="zone-empty">{{ t('assessment.resultsEmpty') }}</p>
          <ul v-else class="result-files" data-testid="student-result-files">
            <li v-for="file in resultFiles" :key="file.id">
              <S3File
                :label="file.name"
                :href="resultFileUrl(file)"
                :test-id="`student-result-${file.id}`"
              />
            </li>
          </ul>
        </section>
      </div>

      <div v-else class="error">
        <h1>{{ t('assessment.studentNotFound') }}</h1>
        <button class="button" data-testid="student-back" @click="goBack">{{ t('assessment.back') }}</button>
      </div>
    </div>
  </div>

  <div
    v-if="renameOpen"
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
    v-if="deleteOpen"
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
          {{ t('assessment.studentDeleteConfirm', { name: studentName }) }}
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
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import S3File from '@/components/S3File.vue'
import { useAssessment } from '@/composables/useAssessment'
import { useAssessmentStream } from '@/composables/useAssessmentStream'
import { applyStudentStream } from '@/utils/assessmentStream'
import type { AssessmentFile, AssessmentStudent, MenuItem, StateLocales } from '@/types/types'
import { isDebugFile, isDirectStudentFile } from '@/utils/assessmentFiles'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()
const studentId = computed(() => route.params.studentId as string)
const streamEnabled = computed(() => Boolean(assessment.value?.id))

useAssessmentStream({
  assessmentId,
  studentId,
  locale,
  enabled: streamEnabled,
  onEvent: (event) => {
    if (event.scope !== 'student' || !assessment.value) return
    const merged = applyStudentStream(files.value, students.value, studentId.value, event)
    applyUpdate(merged.files, merged.students)
  },
})

const student = computed(() => students.value.find((item) => item.id === studentId.value) ?? null)

const statusLabel = computed(() =>
  sessionStore.stateLabel(sessionStore.studentStates, student.value?.status, student.value?.status_label),
)

const isActive = computed(
  () =>
    Boolean(student.value?.loading) ||
    files.value.some((file) => (file.student ?? '') === studentId.value && file.loading),
)

const studentMenu = computed(() =>
  (student.value?.menu ?? []).filter((item) => item.key !== 'view')
)

const isUpdatingStudent = ref(false)
const studentActionError = ref('')
const renameOpen = ref(false)
const renameStudentDraft = ref('')
const renameStudentInput = ref<HTMLInputElement | null>(null)
const deleteOpen = ref(false)

const textButtonClass = (item: MenuItem) => {
  if (item.key === 'delete' || item.color === '#c93b45') return 'button delete'
  if (item.color === '#1a55e8') return 'button primary'
  return 'button secondary'
}

const correctStudent = async () => {
  if (!assessment.value?.id || !studentId.value) return
  studentActionError.value = ''
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    } & StateLocales>('POST', '/student_correct', {
      id: assessment.value.id,
      student: studentId.value,
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

const onStudentAction = (key: string) => {
  if (key === 'correct') void correctStudent()
  else if (key === 'rename') startRenameStudent()
  else if (key === 'delete') startDeleteStudent()
}

const studentName = computed(() => {
  if (student.value?.name) return student.value.name
  const fromFile = files.value.find((file) => (file.student ?? '') === studentId.value)
  return (fromFile?.student_name ?? '').trim()
})

const studentMark = computed(() => student.value?.mark ?? null)

const studentAppreciation = computed(() => (student.value?.appreciation ?? '').trim())

const hasResult = computed(() => studentMark.value != null || studentAppreciation.value !== '')

const formatMark = (mark: number) =>
  new Intl.NumberFormat(String(locale.value), { maximumFractionDigits: 2 }).format(mark)

const appreciationHtml = computed(() => markdownToHtml(studentAppreciation.value))

const studentFiles = computed(() =>
  files.value.filter((file) => {
    if ((file.student ?? '') !== studentId.value) return false
    if (isDebugFile(file) && !sessionStore.debugMode) return false
    return true
  })
)

const copyFiles = computed(() => studentFiles.value.filter((file) => !isDirectStudentFile(file)))

const resultFiles = computed(() => studentFiles.value.filter((file) => isDirectStudentFile(file)))

const resultFileUrl = (file: AssessmentFile) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: assessment.value?.id || '',
    file: file.id,
  })

const goBack = () => {
  router.push({ name: 'assessment', params: { id: assessmentId.value } })
}

const startRenameStudent = () => {
  renameStudentDraft.value = studentName.value
  studentActionError.value = ''
  renameOpen.value = true
  void nextTick(() => {
    renameStudentInput.value?.focus()
    renameStudentInput.value?.select()
  })
}

const closeRenameStudent = () => {
  if (isUpdatingStudent.value) return
  renameOpen.value = false
  studentActionError.value = ''
}

const startDeleteStudent = () => {
  studentActionError.value = ''
  deleteOpen.value = true
}

const closeDeleteStudent = () => {
  if (isUpdatingStudent.value) return
  deleteOpen.value = false
  studentActionError.value = ''
}

const submitRenameStudent = async () => {
  if (!assessment.value?.id || !student.value) return
  const name = renameStudentDraft.value.trim()
  if (!name) return
  studentActionError.value = ''
  isUpdatingStudent.value = true
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    } & StateLocales>('PUT', '/student', {
      id: assessment.value.id,
      student: student.value.id,
      locale: String(locale.value),
    }, { name })
    sessionStore.applyStateLocales(response)
    if (response?.files) {
      applyUpdate(response.files, response.students)
    }
    renameOpen.value = false
  } catch (err) {
    console.error('Error renaming student:', err)
    studentActionError.value = t('assessment.studentRenameError')
  } finally {
    isUpdatingStudent.value = false
  }
}

const submitDeleteStudent = async () => {
  if (!assessment.value?.id || !student.value) return
  studentActionError.value = ''
  isUpdatingStudent.value = true
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    } & StateLocales>('DELETE', '/student', {
      id: assessment.value.id,
      student: student.value.id,
      locale: String(locale.value),
    })
    sessionStore.applyStateLocales(response)
    if (response?.files) {
      applyUpdate(response.files, response.students)
    }
    deleteOpen.value = false
    goBack()
  } catch (err) {
    console.error('Error deleting student:', err)
    studentActionError.value = t('assessment.studentDeleteError')
  } finally {
    isUpdatingStudent.value = false
  }
}

const onFilesUpdated = (payload: { files: AssessmentFile[]; students?: AssessmentStudent[] }) => {
  applyUpdate(payload.files, payload.students)
}

const escapeHtml = (value: string) =>
  value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

const inlineMarkdown = (value: string) =>
  escapeHtml(value)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')

const markdownToHtml = (source: string): string => {
  const html: string[] = []
  let list: 'ul' | 'ol' | null = null
  const closeList = () => {
    if (list) {
      html.push(list === 'ul' ? '</ul>' : '</ol>')
      list = null
    }
  }
  for (const line of source.replace(/\r\n/g, '\n').split('\n')) {
    const heading = /^(#{1,3})\s+(.*)$/.exec(line)
    const bullet = /^[-*]\s+(.*)$/.exec(line)
    const ordered = /^\d+\.\s+(.*)$/.exec(line)
    if (heading) {
      closeList()
      const level = Math.min(heading[1].length + 2, 6)
      html.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`)
      continue
    }
    if (bullet) {
      if (list !== 'ul') {
        closeList()
        html.push('<ul>')
        list = 'ul'
      }
      html.push(`<li>${inlineMarkdown(bullet[1])}</li>`)
      continue
    }
    if (ordered) {
      if (list !== 'ol') {
        closeList()
        html.push('<ol>')
        list = 'ol'
      }
      html.push(`<li>${inlineMarkdown(ordered[1])}</li>`)
      continue
    }
    closeList()
    if (line.trim() !== '') html.push(`<p>${inlineMarkdown(line)}</p>`)
  }
  closeList()
  return html.join('')
}
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}

.header-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.header h1 {
  margin: 0;
}

.student-state {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  margin: 0.45rem 0 0;
}

.student-state-value {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  min-height: 1.25rem;
}

.student-activity {
  display: inline-flex;
  align-items: flex-end;
  gap: 3px;
  height: 14px;
}

.activity-bar {
  width: 3px;
  height: 100%;
  border-radius: 1px;
  background: var(--blue);
  transform-origin: bottom;
  animation: student-activity 0.9s ease-in-out infinite;
}

.activity-bar:nth-child(2) {
  animation-delay: 0.15s;
}

.activity-bar:nth-child(3) {
  animation-delay: 0.3s;
}

@keyframes student-activity {
  0%,
  100% {
    transform: scaleY(0.35);
  }
  50% {
    transform: scaleY(1);
  }
}

.student-status {
  color: var(--info);
  font-size: 0.95rem;
  font-weight: 600;
}

.section {
  margin-top: 1.5rem;
}

.section h2 {
  margin: 0 0 0.75rem;
  font-size: 1.05rem;
}

.zone-empty {
  margin: 0;
  color: var(--text-muted);
}

.result-files {
  list-style: none;
  margin: 0;
  padding: 0;
}

.student-result {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.result-label {
  display: block;
  margin-bottom: 0.2rem;
  color: var(--text-muted);
  font-size: 0.85rem;
  font-weight: 500;
}

.student-mark {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.appreciation :deep(p) {
  margin: 0 0 0.6rem;
}

.appreciation :deep(p:last-child) {
  margin-bottom: 0;
}

.appreciation :deep(ul),
.appreciation :deep(ol) {
  margin: 0.2rem 0 0.6rem;
  padding-left: 1.25rem;
}

.appreciation :deep(h3),
.appreciation :deep(h4),
.appreciation :deep(h5) {
  margin: 0.8rem 0 0.35rem;
  font-size: 1rem;
}

.loading,
.error {
  padding: 1rem 0;
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
</style>
