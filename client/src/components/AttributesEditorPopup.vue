<template>
  <div class="popup-overlay" data-testid="attributes-editor-popup" @click.self="close">
    <div class="popup-content attributes-editor">
      <div class="popup-header">
        <h2>{{ title || t('assessment.editAttributes') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="attributes-editor-close"
          :aria-label="t('common.cancel')"
          :disabled="isSaving"
          @click="close"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <p v-if="isLoading" class="attributes-status">…</p>
        <textarea
          v-else
          v-model="draft"
          class="attributes-json"
          data-testid="attributes-json"
          spellcheck="false"
          :disabled="isSaving"
          @input="jsonError = ''"
        />
        <p v-if="jsonError" class="error-message" data-testid="attributes-error">{{ jsonError }}</p>
      </div>
      <div class="popup-footer">
        <button
          type="button"
          class="button secondary"
          data-testid="attributes-cancel"
          :disabled="isSaving"
          @click="close"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button primary"
          data-testid="attributes-save"
          :disabled="isLoading || isSaving || draft.trim() === ''"
          @click="save"
        >
          {{ isSaving ? t('assessment.attributesSaving') : t('assessment.attributesSave') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'

const props = defineProps<{
  assessmentId: string
  studentId?: string
  fileId?: string
  title?: string
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const { t } = useI18n()
const sessionStore = useSessionStore()

const draft = ref('')
const etag = ref<string | null>(null)
const isLoading = ref(true)
const isSaving = ref(false)
const jsonError = ref('')

const query = () => {
  const params: Record<string, string> = { assessment: props.assessmentId }
  if (props.studentId) params.student = props.studentId
  if (props.fileId) params.file = props.fileId
  return params
}

const close = () => {
  if (isSaving.value) return
  emit('close')
}

const parsedAttributes = (): Record<string, unknown> | null => {
  try {
    const value = JSON.parse(draft.value) as unknown
    if (value === null || typeof value !== 'object' || Array.isArray(value)) {
      jsonError.value = t('assessment.attributesInvalid')
      return null
    }
    return value as Record<string, unknown>
  } catch {
    jsonError.value = t('assessment.attributesInvalid')
    return null
  }
}

const load = async () => {
  isLoading.value = true
  jsonError.value = ''
  try {
    const data = await sessionStore.getWsClient().queryWs<{
      attributes?: Record<string, unknown>
      etag?: string | null
    }>('GET', '/attributes', query())
    draft.value = JSON.stringify(data.attributes ?? {}, null, 2)
    etag.value = data.etag ?? null
  } catch (err) {
    console.error('Error loading attributes:', err)
    jsonError.value = t('assessment.attributesLoadError')
    draft.value = ''
  } finally {
    isLoading.value = false
  }
}

const save = async () => {
  const attributes = parsedAttributes()
  if (!attributes) return
  jsonError.value = ''
  isSaving.value = true
  try {
    await sessionStore.getWsClient().queryWs('PUT', '/attributes', query(), {
      attributes,
      etag: etag.value,
    })
    emit('saved')
    emit('close')
  } catch (err) {
    console.error('Error saving attributes:', err)
    jsonError.value = t('assessment.attributesSaveError')
  } finally {
    isSaving.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<style scoped>
.attributes-editor {
  width: min(40rem, calc(100vw - 2rem));
  max-height: calc(100vh - 2rem);
  display: flex;
  flex-direction: column;
}

.attributes-editor .popup-body {
  overflow: auto;
}

.attributes-json {
  width: 100%;
  min-height: 18rem;
  resize: vertical;
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.85rem;
  line-height: 1.4;
}

.attributes-status {
  margin: 0;
}
</style>
