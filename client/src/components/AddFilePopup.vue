<template>
  <div
    class="popup-overlay"
    data-testid="add-file-popup"
    @click.self="emit('close')"
  >
    <div class="popup-content add-file-popup">
      <div class="popup-header">
        <h2>{{ t('exam.addFilesTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="add-file-close"
          :aria-label="t('common.cancel')"
          @click="emit('close')"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <div
          class="dropzone"
          :class="{ 'dropzone-active': isDragging, 'dropzone-disabled': isUploading }"
          data-testid="add-file-dropzone"
          @click="openFilePicker"
          @dragenter.prevent="onDragEnter"
          @dragover.prevent="onDragOver"
          @dragleave.prevent="onDragLeave"
          @drop.prevent="onDrop"
        >
          <p class="dropzone-hint">{{ t('exam.dropzoneHint') }}</p>
          <p v-if="isUploading" class="dropzone-status">{{ t('exam.uploading') }}</p>
        </div>
        <input
          ref="fileInput"
          type="file"
          multiple
          class="file-input"
          data-testid="add-file-input"
          @change="onFileInputChange"
        />
        <ul v-if="uploadItems.length > 0" class="upload-list" data-testid="add-file-status-list">
          <li
            v-for="item in uploadItems"
            :key="item.id"
            class="upload-item"
            :class="item.status"
          >
            <span class="upload-name">{{ item.name }}</span>
            <span class="upload-status">{{ statusLabel(item) }}</span>
          </li>
        </ul>
        <p v-if="error" class="error-message" data-testid="add-file-error">{{ error }}</p>
      </div>
      <div class="popup-footer">
        <button
          type="button"
          class="button secondary"
          data-testid="add-file-cancel"
          :disabled="isUploading"
          @click="emit('close')"
        >
          {{ t('common.cancel') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import type { ExamFile } from '@/types/types'

const props = defineProps<{
  examId: string
}>()

const emit = defineEmits<{
  close: []
  uploaded: [files: ExamFile[]]
}>()

const { t } = useI18n()
const sessionStore = useSessionStore()

interface UploadItem {
  id: string
  name: string
  status: 'pending' | 'uploading' | 'done' | 'error'
  error?: string
}

const fileInput = ref<HTMLInputElement | null>(null)
const isDragging = ref(false)
const isUploading = ref(false)
const error = ref('')
const uploadItems = ref<UploadItem[]>([])
let dragCounter = 0

const statusLabel = (item: UploadItem) => {
  if (item.status === 'uploading') return t('exam.uploading')
  if (item.status === 'done') return t('exam.uploadDone')
  if (item.status === 'error') return item.error || t('exam.uploadError')
  return ''
}

const openFilePicker = () => {
  if (isUploading.value) return
  fileInput.value?.click()
}

const onDragEnter = () => {
  dragCounter += 1
  isDragging.value = true
}

const onDragOver = () => {
  isDragging.value = true
}

const onDragLeave = () => {
  dragCounter -= 1
  if (dragCounter <= 0) {
    dragCounter = 0
    isDragging.value = false
  }
}

const onDrop = (event: DragEvent) => {
  dragCounter = 0
  isDragging.value = false
  if (isUploading.value) return
  const files = event.dataTransfer?.files
  if (files && files.length > 0) {
    void uploadFiles(Array.from(files))
  }
}

const onFileInputChange = (event: Event) => {
  const input = event.target as HTMLInputElement
  const files = input.files
  if (files && files.length > 0) {
    void uploadFiles(Array.from(files))
  }
  input.value = ''
}

const uploadFiles = async (files: File[]) => {
  if (!props.examId || files.length === 0) return

  error.value = ''
  isUploading.value = true
  uploadItems.value = files.map((file, index) => ({
    id: `${file.name}-${index}-${Date.now()}`,
    name: file.name,
    status: 'pending'
  }))

  const wsClient = sessionStore.getWsClient()
  let latestFiles: ExamFile[] | null = null

  try {
    for (let i = 0; i < files.length; i++) {
      const file = files[i]
      const item = uploadItems.value[i]
      item.status = 'uploading'

      try {
        const formData = new FormData()
        formData.append('file', file)
        const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
          'POST',
          '/file',
          { id: props.examId },
          formData,
          'form'
        )
        item.status = 'done'
        if (response?.files) {
          latestFiles = response.files
          emit('uploaded', response.files)
        }
      } catch (err) {
        console.error('Error uploading file:', err)
        item.status = 'error'
        item.error = t('exam.uploadError')
        error.value = t('exam.uploadError')
      }
    }

    if (latestFiles) {
      const existingIndex = sessionStore.own_exams.findIndex(e => e.id === props.examId)
      if (existingIndex !== -1) {
        sessionStore.own_exams[existingIndex] = {
          ...sessionStore.own_exams[existingIndex],
          files: latestFiles
        }
      }
    }
  } finally {
    isUploading.value = false
  }
}
</script>

<style scoped>
.add-file-popup {
  width: min(480px, calc(100vw - 2rem));
  max-width: 100%;
}

.popup-header h2 {
  margin: 0;
  font-size: 1.25rem;
}

.dropzone {
  border: 2px dashed #c5d4f4;
  border-radius: 15px;
  padding: 2rem 1.5rem;
  text-align: center;
  cursor: pointer;
  background: var(--surface-2);
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.dropzone:hover:not(.dropzone-disabled) {
  border-color: var(--accent);
}

.dropzone-active {
  border-color: var(--accent);
  background: color-mix(in srgb, var(--accent) 12%, var(--surface-2));
}

.dropzone-disabled {
  opacity: 0.7;
  cursor: not-allowed;
}

.dropzone-hint {
  margin: 0;
  color: var(--text);
}

.dropzone-status {
  margin: 0.75rem 0 0;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.file-input {
  display: none;
}

.upload-list {
  list-style: none;
  margin: 1rem 0 0;
  padding: 0;
}

.upload-item {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  padding: 0.5rem 0;
  border-bottom: 1px solid var(--border);
  font-size: 0.9rem;
}

.upload-item:last-child {
  border-bottom: none;
}

.upload-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.upload-item.done .upload-status {
  color: var(--success);
}

.upload-item.error .upload-status {
  color: var(--danger);
}

.upload-item.uploading .upload-status,
.upload-item.pending .upload-status {
  color: var(--text-muted);
}

.error-message {
  color: var(--danger);
  margin: 1rem 0 0;
}

.button.secondary {
  padding: 0.5rem 1rem;
  background: transparent;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  color: var(--text);
  cursor: pointer;
}

.button.secondary:hover:not(:disabled) {
  background: var(--hover-bg);
}

.button.secondary:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
