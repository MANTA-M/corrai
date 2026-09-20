<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('exam.loading') }}</p>
      </div>

      <div v-else-if="error && !exam" class="error">
        <h1>{{ t('exam.error') }}</h1>
        <p>{{ error }}</p>
        <button class="back-button" @click="goBack">{{ t('exam.back') }}</button>
      </div>

      <div v-else-if="exam" class="exam-view">
        <div class="header">
          <h1 data-testid="exam-details-heading">{{ exam.name || t('exam.details') }}</h1>
          <div class="header-actions">
            <button class="back-button" @click="goBack">{{ t('exam.back') }}</button>
            <button
              type="button"
              class="button edit-button"
              data-testid="exam-edit"
              @click="goEdit"
            >
              {{ t('exam.edit') }}
            </button>
            <button
              type="button"
              class="button delete-button"
              data-testid="exam-delete"
              :disabled="isDeleting"
              @click="confirmDelete"
            >
              {{ isDeleting ? t('exam.deleting') : t('exam.delete') }}
            </button>
          </div>
        </div>

        <div class="content">
          <dl class="exam-details" data-testid="exam-details">
            <div class="detail-row">
              <dt>{{ t('exam.name') }}</dt>
              <dd data-testid="exam-name-value">{{ exam.name || '—' }}</dd>
            </div>
            <div class="detail-row">
              <dt>{{ t('exam.subject') }}</dt>
              <dd data-testid="exam-subject-value">{{ exam.subject || '—' }}</dd>
            </div>
            <div class="detail-row">
              <dt>{{ t('exam.date') }}</dt>
              <dd data-testid="exam-date-value">{{ exam.date || '—' }}</dd>
            </div>
          </dl>

          <p v-if="error" class="error-message">{{ error }}</p>

          <section class="files-section" data-testid="exam-files-section">
            <div class="files-header">
              <h2>{{ t('exam.files') }}</h2>
              <button
                type="button"
                class="button add-file-button"
                data-testid="exam-add-file"
                @click="showAddFilePopup = true"
              >
                {{ t('exam.addFiles') }}
              </button>
            </div>

            <p
              v-if="!files.length"
              class="empty-files"
              data-testid="exam-files-empty"
            >
              {{ t('exam.filesEmpty') }}
            </p>
            <ul v-else class="file-list" data-testid="exam-file-list">
              <li
                v-for="file in files"
                :key="file.name"
                class="file-item"
                data-testid="exam-file-item"
              >
                <span class="file-name">{{ file.name }}</span>
                <span class="file-meta">{{ formatFileSize(file.size) }}</span>
              </li>
            </ul>
          </section>
        </div>
      </div>

      <div v-else class="error">
        <h1>{{ t('exam.notFound') }}</h1>
        <p>{{ t('exam.notFoundMessage', { hash: examId }) }}</p>
        <button class="back-button" @click="goBack">{{ t('exam.back') }}</button>
      </div>
    </div>

    <AddFilePopup
      v-if="showAddFilePopup && exam?.id"
      :exam-id="exam.id"
      @close="showAddFilePopup = false"
      @uploaded="onFilesUploaded"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import AddFilePopup from '@/components/AddFilePopup.vue'
import type { Exam, ExamFile } from '@/types/types'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const sessionStore = useSessionStore()

const exam = ref<Exam | null>(null)
const isLoading = ref(true)
const isDeleting = ref(false)
const error = ref('')
const showAddFilePopup = ref(false)

const examId = computed(() => route.params.id as string)
const files = computed(() => exam.value?.files ?? [])

const goBack = () => {
  router.push({ name: 'exam-list' })
}

const goEdit = () => {
  if (!exam.value?.id) return
  router.push(`/exam/${exam.value.id}/edit`)
}

const formatFileSize = (size: number) => {
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

const onFilesUploaded = (updatedFiles: ExamFile[]) => {
  if (!exam.value) return
  exam.value = {
    ...exam.value,
    files: updatedFiles
  }
}

const loadExam = async (hash: string) => {
  isLoading.value = true
  error.value = ''
  try {
    const cached = sessionStore.get_exam(hash)
    if (cached) {
      exam.value = cached
    }
    const loaded = await sessionStore.load_exam(hash)
    if (loaded) {
      exam.value = loaded
    } else if (!cached) {
      exam.value = null
      error.value = t('exam.notFoundMessage', { hash })
    }
  } catch (err) {
    console.error('Error loading exam:', err)
    if (!exam.value) {
      error.value = t('exam.error')
    }
  } finally {
    isLoading.value = false
  }
}

const confirmDelete = async () => {
  if (!exam.value?.id) return
  if (!confirm(t('exam.deleteConfirm'))) return

  isDeleting.value = true
  error.value = ''
  try {
    const wsClient = sessionStore.getWsClient()
    await wsClient.queryWs('DELETE', '/exam', { hash: exam.value.id })
    sessionStore.remove_exam(exam.value.id)
    router.push({ name: 'exam-list' })
  } catch (err) {
    console.error('Error deleting exam:', err)
    error.value = t('exam.deleteError')
  } finally {
    isDeleting.value = false
  }
}

onMounted(() => {
  if (examId.value) {
    loadExam(examId.value)
  }
})

watch(examId, (newId) => {
  if (newId) {
    loadExam(newId)
  }
})
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1.5rem;
  flex-wrap: wrap;
}

.header h1 {
  margin: 0;
}

.header-actions {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex-wrap: wrap;
}

.back-button,
.button {
  padding: 0.5rem 1rem;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.95rem;
}

.back-button {
  background: transparent;
  border: 1px solid var(--border-color);
  color: var(--text);
}

.back-button:hover {
  background: var(--hover-bg);
}

.edit-button,
.add-file-button {
  background-color: var(--accent);
  color: white;
  border: none;
}

.edit-button:hover,
.add-file-button:hover {
  background-color: var(--accent-600);
}

.delete-button {
  background-color: var(--danger);
  color: white;
  border: none;
}

.delete-button:hover:not(:disabled) {
  background-color: var(--danger-600);
  opacity: 0.9;
}

.delete-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.exam-details {
  margin: 0 0 2rem;
  padding: 0;
}

.detail-row {
  display: grid;
  grid-template-columns: 8rem 1fr;
  gap: 0.75rem;
  padding: 0.75rem 0;
  border-bottom: 1px solid var(--border);
}

.detail-row dt {
  margin: 0;
  color: var(--text-muted);
  font-weight: 500;
}

.detail-row dd {
  margin: 0;
  color: var(--text);
}

.files-section {
  margin-top: 0.5rem;
}

.files-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}

.files-header h2 {
  margin: 0;
  font-size: 1.15rem;
}

.empty-files {
  color: var(--text-muted);
  margin: 0;
}

.file-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--border);
  border-radius: 8px;
  overflow: hidden;
}

.file-item {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border);
}

.file-item:last-child {
  border-bottom: none;
}

.file-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-meta {
  color: var(--text-muted);
  font-size: 0.9rem;
  flex-shrink: 0;
}

.error-message {
  color: var(--danger);
  margin-bottom: 1rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
