<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('assessment.loading') }}</p>
      </div>

      <div v-else-if="error && isEditMode && !formLoaded" class="error">
        <h1>{{ t('assessment.error') }}</h1>
        <p>{{ error }}</p>
        <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>

      <template v-else-if="showSubjectStep">
        <div class="header">
          <h1 data-testid="assessment-form-heading">{{ t('createAssessment.title') }}</h1>
          <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
        </div>
        <div class="content">
          <p class="subtitle">{{ t('createAssessment.subjectSubtitle') }}</p>
          <div
            class="dropzone"
            :class="{ 'dropzone-active': isDragging, 'dropzone-disabled': isAnalyzing }"
            data-testid="assessment-subject-dropzone"
            @click="openSubjectPicker"
            @dragenter.prevent="onDragEnter"
            @dragover.prevent="onDragOver"
            @dragleave.prevent="onDragLeave"
            @drop.prevent="onSubjectDrop"
          >
            <p class="dropzone-hint">{{ t('assessment.dropzoneHint') }}</p>
            <p v-if="isAnalyzing" class="dropzone-status">{{ dropzoneStatus }}</p>
          </div>
          <input
            ref="subjectInput"
            type="file"
            multiple
            accept="application/pdf,image/*,text/plain,.txt,.text,.md,.odt,.docx,application/vnd.oasis.opendocument.text,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
            class="file-input"
            data-testid="assessment-subject-file"
            :disabled="isAnalyzing"
            @change="onSubjectFiles"
          />
          <ul
            v-if="uploadItems.length > 0"
            class="upload-list"
            data-testid="assessment-subject-status-list"
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
          <div
            v-if="showUploadProgress"
            class="upload-progress"
            data-testid="assessment-subject-progress"
            role="progressbar"
            :aria-valuemin="0"
            :aria-valuemax="uploadItems.length"
            :aria-valuenow="uploadProgressCurrent"
            :aria-label="t('assessment.uploadProgress', {
              current: uploadProgressCurrent,
              total: uploadItems.length
            })"
          >
            <div class="upload-progress-track">
              <div
                class="upload-progress-fill"
                :style="{ width: `${uploadProgressPercent}%` }"
              />
            </div>
            <span class="upload-progress-label">
              {{ t('assessment.uploadProgress', {
                current: uploadProgressCurrent,
                total: uploadItems.length
              }) }}
            </span>
          </div>
          <div class="file-picker">
            <button
              v-if="draftId"
              type="button"
              class="back-button"
              data-testid="assessment-subject-continue"
              :disabled="isAnalyzing"
              @click="step = 'details'"
            >
              {{ t('createAssessment.continue') }}
            </button>
            <button
              v-else
              type="button"
              class="back-button"
              data-testid="assessment-no-subject"
              :disabled="isAnalyzing"
              @click="skipSubject"
            >
              {{ t('createAssessment.noSubject') }}
            </button>
          </div>
          <p v-if="error" class="error-message">{{ error }}</p>
        </div>
      </template>

      <template v-else>
        <div class="header">
          <h1 data-testid="assessment-form-heading">{{ pageTitle }}</h1>
          <button v-if="isEditMode" class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
        </div>
        <div class="content">
          <p class="subtitle">{{ pageSubtitle }}</p>
          <form class="assessment-form" @submit.prevent="submitAssessment">
            <div class="form-group">
              <label for="assessment-subject">{{ t('assessment.subject') }}</label>
              <select
                id="assessment-subject"
                v-model="form.subject"
                data-testid="assessment-subject"
                required
              >
                <option value="" disabled>{{ t('assessment.subjectPlaceholder') }}</option>
                <option
                  v-for="node in subjects"
                  :key="node.subject"
                  :value="node.subject"
                >
                  {{ node.name }}
                </option>
              </select>
            </div>
            <div v-if="possibleLevels.length || unknownLevel" class="form-group">
              <label for="assessment-level">{{ t('assessment.level') }}</label>
              <select
                id="assessment-level"
                v-model="form.level"
                data-testid="assessment-level"
              >
                <option value="">{{ t('assessment.autoDetect') }}</option>
                <option
                  v-if="unknownLevel"
                  :value="form.level"
                >
                  {{ unknownLevel }}
                </option>
                <option
                  v-for="level in possibleLevels"
                  :key="level.level"
                  :value="level.level"
                >
                  {{ level.name }}
                </option>
              </select>
            </div>
            <div class="form-group">
              <label for="assessment-name">
                {{ t('assessment.name') }}
                <span class="optional">({{ t('assessment.optional') }})</span>
              </label>
              <input
                id="assessment-name"
                v-model="form.name"
                type="text"
                data-testid="assessment-name"
                :placeholder="t('assessment.namePlaceholder')"
              />
            </div>
            <div class="form-group">
              <label for="assessment-date">
                {{ t('assessment.date') }}
                <span class="optional">({{ t('assessment.optional') }})</span>
              </label>
              <input
                id="assessment-date"
                v-model="form.date"
                type="date"
                data-testid="assessment-date"
              />
            </div>
            <p v-if="error" class="error-message">{{ error }}</p>
            <div class="form-actions">
              <button
                type="submit"
                class="submit-button"
                :data-testid="isEditMode ? 'assessment-save' : 'assessment-submit'"
                :disabled="isSubmitting"
              >
                {{ submitLabel }}
              </button>
              <button
                v-if="!isEditMode"
                type="button"
                class="back-button"
                data-testid="assessment-cancel"
                :disabled="isSubmitting"
                @click="cancelCreate"
              >
                {{ t('common.cancel') }}
              </button>
            </div>
          </form>
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import type { Assessment } from '@/types/types'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()

interface UploadItem {
  id: string
  name: string
  status: 'pending' | 'uploading' | 'done' | 'error'
  kind: 'intake' | 'extra'
}

const error = ref('')
const isSubmitting = ref(false)
const isLoading = ref(false)
const isAnalyzing = ref(false)
const isDragging = ref(false)
const formLoaded = ref(false)
const step = ref<'subject' | 'details'>('subject')
const draftId = ref('')
const analyzedCountry = ref<string | null>(null)
const subjectInput = ref<HTMLInputElement | null>(null)
const uploadItems = ref<UploadItem[]>([])
let dragCounter = 0

const dropzoneStatus = computed(() => {
  const current = uploadItems.value.find((item) => item.status === 'uploading')
  return current?.kind === 'extra' ? t('assessment.uploading') : t('createAssessment.analyzing')
})
const showUploadProgress = computed(() => uploadItems.value.length > 5)
const uploadProgressCurrent = computed(
  () => uploadItems.value.filter((item) => item.status === 'done' || item.status === 'error').length
)
const uploadProgressPercent = computed(() => {
  if (uploadItems.value.length === 0) return 0
  return Math.round((uploadProgressCurrent.value / uploadItems.value.length) * 100)
})

const { subjects, load: loadSubjects, levelsFor } = useSubjectCatalog()

const form = reactive({
  name: '',
  subject: '',
  level: '',
  date: ''
})

const userCountry = computed(() => sessionStore.country.trim())
const possibleLevels = computed(() => {
  const subject = form.subject.trim()
  if (!subject) return []
  if (!userCountry.value) {
    return subjects.value.find(node => node.subject === subject)?.levels ?? []
  }
  return levelsFor(subject, userCountry.value)
})
const unknownLevel = computed(() => {
  const code = form.level.trim()
  if (!code || possibleLevels.value.some(item => item.level === code)) return ''
  return code
})

watch(
  () => form.subject,
  (subject, previous) => {
    if (!previous || subject === previous) return
    const code = form.level.trim()
    if (!code || possibleLevels.value.some(item => item.level === code)) return
    form.level = ''
  }
)

function optionalText(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

const assessmentId = computed(() => (route.params.id as string | undefined) ?? '')
const isEditMode = computed(() => route.name === 'assessment-edit' && !!assessmentId.value)
const showSubjectStep = computed(() => !isEditMode.value && step.value === 'subject')

const pageTitle = computed(() =>
  isEditMode.value ? t('createAssessment.editTitle') : t('createAssessment.title')
)
const pageSubtitle = computed(() =>
  isEditMode.value ? t('createAssessment.editSubtitle') : t('createAssessment.reviewSubtitle')
)
const submitLabel = computed(() => {
  if (isSubmitting.value) {
    return isEditMode.value || draftId.value ? t('assessment.saving') : t('assessment.creating')
  }
  return isEditMode.value ? t('assessment.save') : t('createAssessment.finish')
})

const goBack = () => {
  if (isEditMode.value && assessmentId.value) {
    router.push(`/assessment/${assessmentId.value}`)
  } else {
    router.push({ name: 'assessment-list' })
  }
}

const syncForm = (value: Assessment) => {
  form.name = value.name || ''
  form.subject = value.subject || ''
  form.level = value.level || ''
  form.date = value.date || ''
}

const refreshSubjects = async () => {
  await loadSubjects()
}

let editLoadSeq = 0

const loadAssessmentForEdit = async (hash: string) => {
  const seq = ++editLoadSeq
  error.value = ''
  formLoaded.value = false
  try {
    const cached = sessionStore.get_assessment(hash)
    if (cached) {
      formLoaded.value = true
      syncForm(cached)
    } else {
      isLoading.value = true
    }
    const loaded = await sessionStore.load_assessment(hash)
    if (seq !== editLoadSeq) return
    if (loaded) {
      formLoaded.value = true
      const untouched = !cached || (
        form.name === (cached.name || '') &&
        form.subject === (cached.subject || '') &&
        form.level === (cached.level || '') &&
        form.date === (cached.date || '')
      )
      if (untouched) syncForm(loaded)
    } else if (!cached) {
      error.value = t('assessment.notFoundMessage', { hash })
    }
  } catch (err) {
    console.error('Error loading assessment for edit:', err)
    if (!formLoaded.value) {
      error.value = t('assessment.error')
    }
  } finally {
    isLoading.value = false
  }
}

const submitAssessment = async () => {
  error.value = ''
  if (isEditMode.value) {
    await saveAssessment()
  } else {
    await createAssessment()
  }
}

const skipSubject = () => {
  draftId.value = ''
  analyzedCountry.value = null
  error.value = ''
  form.name = ''
  form.subject = ''
  form.level = ''
  form.date = ''
  step.value = 'details'
}

const statusLabel = (item: UploadItem) => {
  if (item.status === 'uploading') {
    return item.kind === 'intake' ? t('createAssessment.analyzing') : t('assessment.uploading')
  }
  if (item.status === 'done') return t('assessment.uploadDone')
  if (item.status === 'error') {
    return item.kind === 'intake' ? t('createAssessment.analyzeError') : t('assessment.uploadError')
  }
  return ''
}

const openSubjectPicker = () => {
  if (isAnalyzing.value) return
  subjectInput.value?.click()
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

const onSubjectDrop = (event: DragEvent) => {
  dragCounter = 0
  isDragging.value = false
  if (isAnalyzing.value) return
  const files = event.dataTransfer?.files
  if (files && files.length > 0) {
    void uploadSubjectFiles(Array.from(files))
  }
}

const onSubjectFiles = (event: Event) => {
  const input = event.target as HTMLInputElement
  const files = input.files
  if (files && files.length > 0) {
    void uploadSubjectFiles(Array.from(files))
  }
  input.value = ''
}

const uploadSubjectFiles = async (files: File[]) => {
  if (files.length === 0 || isAnalyzing.value) return

  const userId = sessionStore.hasValidUserId ? sessionStore.user_id : null
  if (!userId) {
    error.value = t('createAssessment.analyzeError')
    return
  }

  error.value = ''
  isAnalyzing.value = true
  uploadItems.value = files.map((file, index) => ({
    id: `${file.name}-${index}-${Date.now()}`,
    name: file.name,
    status: 'pending' as const,
    kind: 'extra' as const
  }))

  const wsClient = sessionStore.getWsClient()
  const addingToDraft = draftId.value !== ''
  try {
    if (!addingToDraft) {
      for (const item of uploadItems.value) {
        item.kind = 'intake'
        item.status = 'uploading'
      }
      const formData = new FormData()
      for (const file of files) {
        formData.append('files[]', file)
      }
      const response = await wsClient.queryWs<{
        hash?: string
        name?: string
        subject?: string
        level?: string | null
        country?: string | null
        date?: string
      }>('POST', '/assessment_subject', { locale: String(locale.value) }, formData, 'form')

      const id = response?.hash
      if (!id) {
        for (const item of uploadItems.value) item.status = 'error'
        error.value = t('createAssessment.analyzeError')
        return
      }

      draftId.value = id
      analyzedCountry.value = response.country ?? null
      form.name = response.name || ''
      form.subject = response.subject || ''
      form.level = response.level || ''
      form.date = response.date || ''
      sessionStore.own_assessments.push({
        id,
        author: userId,
        name: form.name,
        subject: form.subject,
        country: analyzedCountry.value,
        level: form.level || null,
        date: form.date,
        files: []
      })
      for (const item of uploadItems.value) item.status = 'done'
      step.value = 'details'
      return
    }

    for (let i = 0; i < files.length; i++) {
      const file = files[i]
      const item = uploadItems.value[i]
      item.kind = 'extra'
      item.status = 'uploading'
      try {
        const formData = new FormData()
        formData.append('file', file)
        await wsClient.queryWs(
          'POST',
          '/file',
          { assessment: draftId.value, locale: String(locale.value), type: 'subject' },
          formData,
          'form'
        )
        item.status = 'done'
      } catch (err) {
        console.error('Error uploading subject file:', err)
        item.status = 'error'
        error.value = t('assessment.uploadError')
      }
    }

    const allSucceeded = uploadItems.value.every((item) => item.status === 'done')
    if (allSucceeded && draftId.value) {
      step.value = 'details'
    }
  } catch (err) {
    console.error('Error uploading subject file:', err)
    for (const item of uploadItems.value) {
      if (item.status === 'uploading' || item.status === 'pending') {
        item.status = 'error'
      }
    }
    if (!error.value) {
      error.value = t('createAssessment.analyzeError')
    }
  } finally {
    isAnalyzing.value = false
  }
}

const cancelCreate = async () => {
  error.value = ''
  if (!draftId.value) {
    await router.push({ name: 'assessment-list' })
    return
  }

  isSubmitting.value = true
  try {
    await sessionStore.getWsClient().queryWs('DELETE', '/assessment', { hash: draftId.value })
    sessionStore.remove_assessment(draftId.value)
    draftId.value = ''
    await router.push({ name: 'assessment-list' })
  } catch (err) {
    console.error('Error cancelling assessment:', err)
    error.value = t('assessment.deleteError')
  } finally {
    isSubmitting.value = false
  }
}

const createAssessment = async () => {
  const userId = sessionStore.hasValidUserId ? sessionStore.user_id : null
  if (!userId) {
    error.value = t('assessment.createError')
    return
  }

  isSubmitting.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const payload = assessmentPayload()
    if (draftId.value) {
      await wsClient.queryWs('PUT', '/assessment', { hash: draftId.value }, payload)
      const existingIndex = sessionStore.own_assessments.findIndex((e: Assessment) => e.id === draftId.value)
      if (existingIndex !== -1) {
        sessionStore.own_assessments[existingIndex] = {
          ...sessionStore.own_assessments[existingIndex],
          ...payload
        }
      }
      await router.push(`/assessment/${draftId.value}`)
      return
    }

    const response = await wsClient.queryWs<{ hash?: string }>(
      'POST',
      '/assessment',
      { locale: String(locale.value) },
      payload
    )

    const id = response?.hash
    if (!id) {
      error.value = t('assessment.createError')
      return
    }

    const createdAssessment: Assessment = {
      id,
      author: userId,
      name: payload.name,
      subject: payload.subject,
      country: payload.country,
      level: payload.level,
      date: payload.date,
      files: []
    }
    sessionStore.own_assessments.push(createdAssessment)
    await router.push(`/assessment/${id}`)
  } catch (err) {
    console.error('Error creating assessment:', err)
    error.value = t('assessment.createError')
  } finally {
    isSubmitting.value = false
  }
}

const saveAssessment = async () => {
  if (!assessmentId.value) return

  isSubmitting.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const payload = assessmentPayload()
    await wsClient.queryWs('PUT', '/assessment', { hash: assessmentId.value }, payload)

    const existingIndex = sessionStore.own_assessments.findIndex((e: Assessment) => e.id === assessmentId.value)
    if (existingIndex !== -1) {
      sessionStore.own_assessments[existingIndex] = {
        ...sessionStore.own_assessments[existingIndex],
        ...payload
      }
    }

    await router.push(`/assessment/${assessmentId.value}`)
  } catch (err) {
    console.error('Error saving assessment:', err)
    error.value = t('assessment.saveError')
  } finally {
    isSubmitting.value = false
  }
}

const assessmentPayload = () => ({
  name: form.name.trim(),
  subject: form.subject.trim(),
  country: !isEditMode.value && analyzedCountry.value
    ? analyzedCountry.value
    : (userCountry.value || null),
  level: optionalText(form.level),
  date: form.date
})

const resetCreateForm = () => {
  form.name = ''
  form.subject = ''
  form.level = ''
  form.date = ''
  error.value = ''
  formLoaded.value = false
  isLoading.value = false
  isAnalyzing.value = false
  step.value = 'subject'
  draftId.value = ''
  analyzedCountry.value = null
  uploadItems.value = []
  isDragging.value = false
  dragCounter = 0
}

watch(locale, () => {
  refreshSubjects()
})

onMounted(() => {
  refreshSubjects()
})

watch(
  () => [route.name, assessmentId.value] as const,
  ([name, id]) => {
    if (name === 'assessment-edit' && id) {
      loadAssessmentForEdit(id)
    } else if (name === 'create-assessment') {
      resetCreateForm()
    }
  },
  { immediate: true }
)
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.header h1 {
  margin: 0;
}

.back-button {
  padding: 12px 18px;
  background: var(--white);
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: var(--type-label);
  color: var(--text);
}

.back-button:hover {
  background: var(--hover-bg);
}

.subtitle {
  color: var(--text-muted);
  margin: 0 0 1.5rem 0;
}

.form-group {
  margin-bottom: 1.5rem;
}

.form-group label {
  display: block;
  margin-bottom: 0.5rem;
  font-size: var(--type-label);
  font-weight: 700;
  color: var(--navy);
}

.optional {
  font-weight: 500;
  color: var(--text-muted);
}

.form-group input,
.form-group select {
  width: 100%;
  padding: 12px 14px;
  border: 1px solid var(--border-color);
  border-radius: var(--radius-md);
  font-size: 1rem;
  box-sizing: border-box;
  background: var(--surface);
  color: var(--text);
}

.form-group input:focus,
.form-group select:focus {
  outline: none;
  border-color: var(--blue);
  box-shadow: 0 0 0 3px rgba(26, 85, 232, 0.18);
}

.submit-button {
  padding: 12px 18px;
  background-color: var(--accent);
  color: white;
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: var(--type-label);
}

.submit-button:hover:not(:disabled) {
  background-color: var(--accent-600);
}

.submit-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.form-actions {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.file-picker {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.75rem;
  margin-top: 1rem;
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

.upload-progress {
  margin-top: 1rem;
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

.error-message {
  color: var(--danger);
  margin-bottom: 1rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
