<template>
  <div class="app">
    <div class="card">
      <div class="header">
        <div>
          <h1 data-testid="share-heading">{{ t('share.title') }}</h1>
          <p class="muted">{{ t('share.subtitle') }}</p>
        </div>
      </div>

      <div class="content">
        <section v-if="sharedFiles.length" class="shared-files" data-testid="share-files">
          <h2>{{ t('share.filesHeading') }}</h2>
          <ul class="file-list">
            <li v-for="file in sharedFiles" :key="file.name + file.size">
              <span class="file-name">{{ file.name }}</span>
              <span class="file-meta">{{ formatFileSize(file.size) }}</span>
            </li>
          </ul>
        </section>

        <p v-else-if="!isLoadingFiles" class="empty-message" data-testid="share-files-empty">
          {{ t('share.noFiles') }}
        </p>

        <p v-if="error" class="error-message" data-testid="share-error">{{ error }}</p>
        <p v-if="isUploading" class="status-message" data-testid="share-uploading">
          {{ t('share.uploading') }}
        </p>

        <section class="exam-picker">
          <h2>{{ t('share.chooseExam') }}</h2>
          <div v-if="isLoadingExams" class="loading">
            <p>{{ t('exam.loading') }}</p>
          </div>
          <template v-else>
            <p v-if="exams.length === 0" class="empty-message" data-testid="share-exams-empty">
              {{ t('share.noExams') }}
            </p>
            <ul v-else class="exams-list" data-testid="share-exam-list">
              <li v-for="exam in exams" :key="exam.id || exam.name">
                <button
                  type="button"
                  class="exam-item"
                  data-testid="share-exam-item"
                  :disabled="!canUpload || isUploading"
                  @click="uploadToExam(exam.id!)"
                >
                  <span class="exam-name">{{ exam.name || '—' }}</span>
                  <span class="exam-subject">{{ subjectLabel(exam.subject) }}</span>
                  <span class="exam-date">{{ exam.date || '—' }}</span>
                </button>
              </li>
            </ul>
          </template>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { clearSharedFiles, loadSharedFiles } from '@/services/shareTarget'
import { isExamSubject, type ExamFile } from '@/types/types'

const { t } = useI18n()
const router = useRouter()
const sessionStore = useSessionStore()
const { subjects, load: loadSubjects } = useSubjectCatalog()

const sharedFiles = ref<File[]>([])
const isLoadingFiles = ref(true)
const isLoadingExams = ref(false)
const isUploading = ref(false)
const error = ref('')

const exams = computed(() => {
  return [...sessionStore.own_exams]
    .filter((exam) => !!exam.id)
    .sort((a, b) => (a.date || '').localeCompare(b.date || ''))
})

const canUpload = computed(
  () => sharedFiles.value.length > 0 && !isUploading.value && !isLoadingFiles.value
)

const subjectLabel = (subject: string) => {
  const node = subjects.value.find((item) => item.subject === subject)
  if (node?.name) return node.name
  if (isExamSubject(subject)) return t(`exam.subjects.${subject}`)
  return subject || '—'
}

const formatFileSize = (size: number) => {
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

const uploadToExam = async (examId: string) => {
  if (!canUpload.value || !examId) return

  error.value = ''
  isUploading.value = true
  const wsClient = sessionStore.getWsClient()
  let latestFiles: ExamFile[] | null = null

  try {
    for (const file of sharedFiles.value) {
      const formData = new FormData()
      formData.append('file', file)
      const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
        'POST',
        '/file',
        { id: examId },
        formData,
        'form'
      )
      if (response?.files) {
        latestFiles = response.files
      }
    }

    if (latestFiles) {
      const existingIndex = sessionStore.own_exams.findIndex((e) => e.id === examId)
      if (existingIndex !== -1) {
        sessionStore.own_exams[existingIndex] = {
          ...sessionStore.own_exams[existingIndex],
          files: latestFiles,
        }
      }
    }

    await clearSharedFiles()
    sharedFiles.value = []
    await router.push(`/exam/${examId}`)
  } catch (err) {
    console.error('Error uploading shared files:', err)
    error.value = t('share.uploadError')
  } finally {
    isUploading.value = false
  }
}

onMounted(async () => {
  isLoadingFiles.value = true
  isLoadingExams.value = true
  try {
    await Promise.all([loadSubjects(), sessionStore.load_exams()])
    sharedFiles.value = await loadSharedFiles()
  } catch (err) {
    console.error('Error loading share target data:', err)
    error.value = t('share.loadError')
  } finally {
    isLoadingFiles.value = false
    isLoadingExams.value = false
  }
})
</script>

<style scoped>
.header {
  margin-bottom: 1.5rem;
}

.header h1 {
  margin: 0 0 0.5rem 0;
}

.muted {
  color: var(--text-muted);
  margin: 0;
}

.content {
  padding: 1rem 0;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.shared-files h2,
.exam-picker h2 {
  margin: 0 0 0.75rem 0;
  font-size: 1.1rem;
}

.file-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.file-list li {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1rem;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  margin-bottom: 0.5rem;
  background: var(--white);
}

.file-name {
  font-weight: 600;
  color: var(--navy);
  word-break: break-word;
}

.file-meta {
  color: var(--text-muted);
  white-space: nowrap;
}

.exams-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.exam-item {
  display: grid;
  grid-template-columns: 1fr 1fr 120px;
  gap: 1rem;
  width: 100%;
  padding: 1rem;
  border: 1px solid var(--border-color);
  border-radius: 15px;
  background: var(--white);
  margin-bottom: 0.75rem;
  align-items: center;
  cursor: pointer;
  transition: background-color 0.2s, border-color 0.2s;
  text-align: left;
  color: inherit;
  font: inherit;
}

.exam-item:hover:not(:disabled) {
  border-color: var(--hover-border);
  box-shadow: var(--shadow-2);
  transform: translateY(-2px);
}

.exam-item:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.exam-name {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--navy);
}

.exam-subject,
.exam-date {
  color: var(--text-muted);
  font-size: 0.95rem;
}

.empty-message,
.loading,
.status-message {
  color: var(--text-muted);
  margin: 0;
}

.error-message {
  color: #c62828;
  margin: 0;
}

@media (max-width: 600px) {
  .exam-item {
    grid-template-columns: 1fr;
    gap: 0.25rem;
  }
}
</style>
