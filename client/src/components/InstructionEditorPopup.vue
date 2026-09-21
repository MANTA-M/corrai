<template>
  <Teleport to="body">
    <div
      class="popup-overlay instruction-editor-overlay"
      data-testid="instruction-editor-popup"
      @click.self="emit('close')"
    >
      <div class="popup-content instruction-editor">
      <div class="popup-header">
        <h2>{{ existingFile ? t('exam.instructionEditTitle') : t('exam.instructionCreateTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="instruction-editor-close"
          :aria-label="t('common.cancel')"
          @click="emit('close')"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <label class="instruction-label" for="instruction-title">{{ t('exam.instructionTitle') }}</label>
        <input
          id="instruction-title"
          v-model="title"
          type="text"
          class="instruction-title-input"
          data-testid="instruction-title"
          :disabled="isSaving || isLoading"
        />
        <label class="instruction-label" for="instruction-body">{{ t('exam.instructionBody') }}</label>
        <textarea
          id="instruction-body"
          v-model="body"
          class="instruction-body-input"
          data-testid="instruction-body"
          :disabled="isSaving || isLoading"
        />
        <p v-if="error" class="error-message" data-testid="instruction-editor-error">{{ error }}</p>
      </div>
      <div class="popup-footer">
        <button
          type="button"
          class="button secondary"
          data-testid="instruction-editor-cancel"
          :disabled="isSaving"
          @click="emit('close')"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button add-file-button"
          data-testid="instruction-editor-save"
          :disabled="isSaving || isLoading || !title.trim()"
          @click="save"
        >
          {{ isSaving ? t('exam.instructionSaving') : t('exam.instructionSave') }}
        </button>
      </div>
    </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { EXAM_FILE_TYPES, type ExamFile, type ExamFileTypeZone } from '@/types/types'

const props = defineProps<{
  examId: string
  files: ExamFile[]
  existingFile: ExamFile | null
}>()

const emit = defineEmits<{
  close: []
  saved: [files: ExamFile[]]
}>()

const { t } = useI18n()
const sessionStore = useSessionStore()

const title = ref('')
const body = ref('')
const error = ref('')
const isSaving = ref(false)
const isLoading = ref(false)

const fileZone = (file: ExamFile): ExamFileTypeZone => {
  const type = file.type ?? ''
  return (EXAM_FILE_TYPES as readonly string[]).includes(type)
    ? (type as ExamFileTypeZone)
    : 'unknown'
}

const titleFromFilename = (name: string) => name.replace(/\.txt$/i, '')

const filenameFromTitle = (value: string) => {
  const cleaned = value.trim().replace(/[\/\\]/g, '-')
  if (/\.[A-Za-z0-9]+$/.test(cleaned)) {
    return cleaned
  }
  return `${cleaned}.txt`
}

const nextInstructionTitle = () => {
  const prefix = t('exam.instructionTitlePrefix')
  const escaped = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
  const pattern = new RegExp(`^${escaped}\\s*(\\d+)`, 'i')
  let max = 0
  for (const file of props.files) {
    if (fileZone(file) !== 'instructions') continue
    const match = titleFromFilename(file.name).match(pattern)
    if (match) {
      max = Math.max(max, Number.parseInt(match[1], 10))
    }
  }
  let next = max + 1
  let candidate = `${prefix}${next}`
  while (props.files.some((file) => file.name === filenameFromTitle(candidate))) {
    next += 1
    candidate = `${prefix}${next}`
  }
  return candidate
}

const fileViewUrl = (file: ExamFile) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    id: props.examId,
    filename: file.name,
  })

onMounted(async () => {
  if (!props.existingFile) {
    title.value = nextInstructionTitle()
    return
  }

  title.value = titleFromFilename(props.existingFile.name)
  isLoading.value = true
  try {
    const response = await fetch(fileViewUrl(props.existingFile))
    if (!response.ok) {
      throw new Error('Failed to load instruction')
    }
    body.value = await response.text()
  } catch (err) {
    console.error('Error loading instruction:', err)
    error.value = t('exam.instructionLoadError')
  } finally {
    isLoading.value = false
  }
})

const save = async () => {
  const trimmedTitle = title.value.trim()
  if (!trimmedTitle) return

  const filename = filenameFromTitle(trimmedTitle)
  error.value = ''
  isSaving.value = true

  try {
    const wsClient = sessionStore.getWsClient()
    let files: ExamFile[] | undefined

    if (!props.existingFile) {
      if (props.files.some((file) => file.name === filename)) {
        error.value = t('exam.instructionSaveError')
        return
      }
      const blob = new Blob([body.value], { type: 'text/plain;charset=utf-8' })
      const file = new File([blob], filename, { type: 'text/plain' })
      const formData = new FormData()
      formData.append('file', file)
      formData.append('type', 'instructions')
      const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
        'POST',
        '/file',
        { id: props.examId },
        formData,
        'form'
      )
      files = response?.files
    } else {
      const patch: { content: string; name?: string } = { content: body.value }
      if (filename !== props.existingFile.name) {
        patch.name = filename
      }
      const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
        'PUT',
        '/file',
        { id: props.examId, filename: props.existingFile.name },
        patch
      )
      files = response?.files
    }

    if (files) {
      emit('saved', files)
    }
    emit('close')
  } catch (err) {
    console.error('Error saving instruction:', err)
    error.value = t('exam.instructionSaveError')
  } finally {
    isSaving.value = false
  }
}
</script>

<style scoped>
.instruction-editor-overlay {
  z-index: 1200;
}

.instruction-editor {
  width: min(920px, calc(100vw - 2rem));
  height: min(85vh, 860px);
  max-height: calc(100vh - 2rem);
  display: flex;
  flex-direction: column;
}

.instruction-editor .popup-header,
.instruction-editor .popup-footer {
  flex-shrink: 0;
}

.instruction-editor .popup-footer {
  margin-top: 0;
}

.instruction-editor h2 {
  margin: 0;
  font-size: 1.2rem;
}

.instruction-editor .popup-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;
  overflow: hidden;
}

.instruction-label {
  display: block;
  margin-bottom: 0.35rem;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.instruction-title-input,
.instruction-body-input {
  width: 100%;
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--surface);
  color: var(--text);
  font: inherit;
}

.instruction-title-input {
  margin-bottom: 0.9rem;
}

.instruction-body-input {
  flex: 1;
  min-height: 12rem;
  overflow: auto;
  resize: none;
  line-height: 1.5;
}

.instruction-editor .error-message {
  color: var(--danger);
  margin: 0.75rem 0 0;
}

.add-file-button {
  padding: 0.5rem 1rem;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.95rem;
  background-color: var(--accent);
  color: white;
  border: none;
}

.add-file-button:hover:not(:disabled) {
  background-color: var(--accent-600);
}

.add-file-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
