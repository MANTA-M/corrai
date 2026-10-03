<template>
  <div
    class="popup-overlay"
    data-testid="add-file-popup"
    @click.self="emit('close')"
  >
    <div class="popup-content add-file-popup">
      <div class="popup-header">
        <h2>{{ title || t('assessment.addFilesTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="add-file-close"
          :aria-label="t('common.cancel')"
          :disabled="isUploading"
          @click="emit('close')"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <label v-if="!fixedType" class="type-field" for="add-file-type">
          {{ t('assessment.fileUploadType') }}
          <select
            id="add-file-type"
            v-model="fileType"
            data-testid="add-file-type"
            :disabled="isUploading"
          >
            <option value="">{{ t('assessment.fileTypeUnknown') }}</option>
            <option value="subject">{{ t('assessment.fileTypeSubject') }}</option>
            <option value="solution">{{ t('assessment.fileTypeSolution') }}</option>
            <option value="submission">{{ t('assessment.fileTypeSubmission') }}</option>
            <option value="instructions">{{ t('assessment.fileTypeInstructions') }}</option>
            <option value="correction">{{ t('assessment.fileTypeCorrection') }}</option>
            <option value="debug">{{ t('assessment.fileTypeDebug') }}</option>
          </select>
        </label>
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
          <p class="dropzone-hint">{{ t('assessment.dropzoneHint') }}</p>
          <p v-if="isUploading" class="dropzone-status">{{ t('assessment.uploading') }}</p>
        </div>
        <input
          ref="fileInput"
          type="file"
          multiple
          class="file-input"
          data-testid="add-file-input"
          @change="onFileInputChange"
        />
        <ul
          v-if="uploadItems.length > 0"
          ref="uploadList"
          class="upload-list"
          data-testid="add-file-status-list"
        >
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
        <div
          v-if="showUploadProgress"
          class="upload-progress"
          data-testid="add-file-progress"
          role="progressbar"
          :aria-valuemin="0"
          :aria-valuemax="uploadProgressTotal"
          :aria-valuenow="uploadProgressCurrent"
          :aria-label="t('assessment.uploadProgress', {
            current: uploadProgressCurrent,
            total: uploadProgressTotal
          })"
        >
          <div class="upload-progress-track">
            <div
              class="upload-progress-fill"
              :style="{ width: `${uploadProgressPercent}%` }"
            />
          </div>
          <span class="upload-progress-label">
            {{
              t('assessment.uploadProgress', {
                current: uploadProgressCurrent,
                total: uploadProgressTotal
              })
            }}
          </span>
        </div>
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
import { computed, nextTick, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import type { Assessment, AssessmentFile } from '@/types/types'

const props = defineProps<{
  assessmentId: string
  fixedType?: string
  title?: string
}>()

const emit = defineEmits<{
  close: []
  uploaded: [files: AssessmentFile[]]
}>()

const { t, locale } = useI18n()
const sessionStore = useSessionStore()

interface UploadItem {
  id: string
  name: string
  status: 'pending' | 'uploading' | 'done' | 'error'
  error?: string
}

const fileInput = ref<HTMLInputElement | null>(null)
const uploadList = ref<HTMLUListElement | null>(null)
const isDragging = ref(false)
const isUploading = ref(false)
const error = ref('')
const fileType = ref(props.fixedType ?? '')
const uploadItems = ref<UploadItem[]>([])
let dragCounter = 0

const showUploadProgress = computed(() => uploadItems.value.length > 5)
const uploadProgressTotal = computed(() => uploadItems.value.length)
const uploadProgressCurrent = computed(
  () => uploadItems.value.filter((item) => item.status === 'done' || item.status === 'error').length
)
const uploadProgressPercent = computed(() => {
  if (uploadProgressTotal.value === 0) return 0
  return Math.round((uploadProgressCurrent.value / uploadProgressTotal.value) * 100)
})

const scrollItemIntoView = async (index: number) => {
  await nextTick()
  const list = uploadList.value
  const item = list?.children[index] as HTMLElement | undefined
  if (!list || !item) return

  const listRect = list.getBoundingClientRect()
  const itemRect = item.getBoundingClientRect()
  const itemCenter = list.scrollTop + (itemRect.top - listRect.top) + itemRect.height / 2
  list.scrollTo({
    top: Math.max(0, itemCenter - listRect.height / 2),
    behavior: 'smooth'
  })
}

const statusLabel = (item: UploadItem) => {
  if (item.status === 'uploading') return t('assessment.uploading')
  if (item.status === 'done') return t('assessment.uploadDone')
  if (item.status === 'error') return item.error || t('assessment.uploadError')
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
  if (!props.assessmentId || files.length === 0) return

  error.value = ''
  isUploading.value = true
  uploadItems.value = files.map((file, index) => ({
    id: `${file.name}-${index}-${Date.now()}`,
    name: file.name,
    status: 'pending'
  }))

  const wsClient = sessionStore.getWsClient()
  let latestFiles: AssessmentFile[] | null = null

  try {
    for (let i = 0; i < files.length; i++) {
      const file = files[i]
      const item = uploadItems.value[i]
      item.status = 'uploading'
      await scrollItemIntoView(i)

      try {
        const formData = new FormData()
        formData.append('file', file)
        const params: Record<string, string> = { id: props.assessmentId, locale: String(locale.value) }
        if (fileType.value) params.type = fileType.value
        const response = await wsClient.queryWs<{ files?: AssessmentFile[] }>(
          'POST',
          '/file',
          params,
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
        item.error = t('assessment.uploadError')
        error.value = t('assessment.uploadError')
      }
    }

    if (latestFiles) {
      const existingIndex = sessionStore.own_assessments.findIndex((e: Assessment) => e.id === props.assessmentId)
      if (existingIndex !== -1) {
        sessionStore.own_assessments[existingIndex] = {
          ...sessionStore.own_assessments[existingIndex],
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
  max-height: calc(100vh - 2rem);
  display: flex;
  flex-direction: column;
}

.add-file-popup .popup-header,
.add-file-popup .popup-footer {
  flex-shrink: 0;
}

.add-file-popup .popup-footer {
  margin-top: 0;
  align-items: center;
}

.add-file-popup .popup-body {
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.type-field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  margin-bottom: 1rem;
  font-size: 0.9rem;
  flex-shrink: 0;
}

.type-field select {
  padding: 0.45rem 0.6rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
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
  flex-shrink: 0;
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
  overflow-y: auto;
  min-height: 0;
  flex: 1 1 auto;
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
  flex-shrink: 0;
}

.upload-progress {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  gap: 0.6rem;
}

.upload-progress-track {
  flex: 1;
  min-width: 0;
  height: 0.5rem;
  border-radius: 999px;
  background: var(--surface-2);
  border: 1px solid var(--border);
  overflow: hidden;
}

.upload-progress-fill {
  height: 100%;
  background: var(--accent);
  border-radius: inherit;
  transition: width 0.2s ease;
}

.upload-progress-label {
  flex-shrink: 0;
  font-size: 0.85rem;
  color: var(--text-muted);
  font-variant-numeric: tabular-nums;
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
