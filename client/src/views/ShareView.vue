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

        <section class="assessment-picker">
          <h2>{{ t('share.chooseAssessment') }}</h2>
          <div v-if="isLoadingAssessments" class="loading">
            <p>{{ t('assessment.loading') }}</p>
          </div>
          <template v-else>
            <p v-if="assessments.length === 0" class="empty-message" data-testid="share-assessments-empty">
              {{ t('share.noAssessments') }}
            </p>
            <ul v-else class="assessments-list" data-testid="share-assessment-list">
              <li v-for="assessment in assessments" :key="assessment.id || assessment.name">
                <button
                  type="button"
                  class="assessment-item"
                  data-testid="share-assessment-item"
                  :disabled="!canUpload || isUploading"
                  @click="uploadToAssessment(assessment.id!)"
                >
                  <span class="assessment-name">{{ assessment.name || '—' }}</span>
                  <span class="assessment-subject">{{ subjectLabel(assessment.subject) }}</span>
                  <span class="assessment-date">{{ assessment.date || '—' }}</span>
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
import { isAssessmentSubject, type Assessment, type AssessmentFile } from '@/types/types'

const { t, locale } = useI18n()
const router = useRouter()
const sessionStore = useSessionStore()
const { subjects, load: loadSubjects } = useSubjectCatalog()

const sharedFiles = ref<File[]>([])
const isLoadingFiles = ref(true)
const isLoadingAssessments = ref(false)
const isUploading = ref(false)
const error = ref('')

const assessments = computed(() => {
  return [...sessionStore.own_assessments]
    .filter((assessment) => !!assessment.id)
    .sort((a, b) => (a.date || '').localeCompare(b.date || ''))
})

const canUpload = computed(
  () => sharedFiles.value.length > 0 && !isUploading.value && !isLoadingFiles.value
)

const subjectLabel = (subject: string) => {
  const node = subjects.value.find((item) => item.subject === subject)
  if (node?.name) return node.name
  if (isAssessmentSubject(subject)) return t(`assessment.subjects.${subject}`)
  return subject || '—'
}

const formatFileSize = (size: number) => {
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

const uploadToAssessment = async (assessmentId: string) => {
  if (!canUpload.value || !assessmentId) return

  error.value = ''
  isUploading.value = true
  const wsClient = sessionStore.getWsClient()
  let latestFiles: AssessmentFile[] | null = null

  try {
    for (const file of sharedFiles.value) {
      const formData = new FormData()
      formData.append('file', file)
      const response = await wsClient.queryWs<{ files?: AssessmentFile[] }>(
        'POST',
        '/file',
        { id: assessmentId, locale: String(locale.value) },
        formData,
        'form'
      )
      if (response?.files) {
        latestFiles = response.files
      }
    }

    if (latestFiles) {
      const existingIndex = sessionStore.own_assessments.findIndex((e: Assessment) => e.id === assessmentId)
      if (existingIndex !== -1) {
        sessionStore.own_assessments[existingIndex] = {
          ...sessionStore.own_assessments[existingIndex],
          files: latestFiles,
        }
      }
    }

    await clearSharedFiles()
    sharedFiles.value = []
    await router.push(`/assessment/${assessmentId}`)
  } catch (err) {
    console.error('Error uploading shared files:', err)
    error.value = t('share.uploadError')
  } finally {
    isUploading.value = false
  }
}

onMounted(async () => {
  isLoadingFiles.value = true
  isLoadingAssessments.value = true
  try {
    await Promise.all([loadSubjects(), sessionStore.load_assessments()])
    sharedFiles.value = await loadSharedFiles()
  } catch (err) {
    console.error('Error loading share target data:', err)
    error.value = t('share.loadError')
  } finally {
    isLoadingFiles.value = false
    isLoadingAssessments.value = false
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
.assessment-picker h2 {
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

.assessments-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.assessment-item {
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

.assessment-item:hover:not(:disabled) {
  border-color: var(--hover-border);
  box-shadow: var(--shadow-2);
  transform: translateY(-2px);
}

.assessment-item:disabled {
  opacity: 0.55;
  cursor: not-allowed;
}

.assessment-name {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--navy);
}

.assessment-subject,
.assessment-date {
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
  .assessment-item {
    grid-template-columns: 1fr;
    gap: 0.25rem;
  }
}
</style>
