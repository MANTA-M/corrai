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
          <div class="file-picker">
            <label class="file-button" :class="{ disabled: isAnalyzing }">
              {{ t('createAssessment.chooseSubject') }}
              <input
                type="file"
                accept="application/pdf,image/*,text/plain,.txt,.text,.md,.odt,.docx,application/vnd.oasis.opendocument.text,application/vnd.openxmlformats-officedocument.wordprocessingml.document"
                data-testid="assessment-subject-file"
                :disabled="isAnalyzing"
                @change="onSubjectFile"
              />
            </label>
            <button
              type="button"
              class="back-button"
              data-testid="assessment-no-subject"
              :disabled="isAnalyzing"
              @click="skipSubject"
            >
              {{ t('createAssessment.noSubject') }}
            </button>
          </div>
          <p v-if="isAnalyzing" class="analyzing">{{ t('createAssessment.analyzing') }}</p>
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
            <div v-if="educationCycles.length" class="form-group">
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
                <optgroup
                  v-for="cycle in educationCycles"
                  :key="cycle.code"
                  :label="cycle.name"
                >
                  <option
                    v-for="level in cycle.levels"
                    :key="level.code"
                    :value="level.code"
                  >
                    {{ level.name }}
                  </option>
                </optgroup>
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
import { EDUCATION_LEVELS, educationLevelName } from '@/data/levels'
import type { Assessment } from '@/types/types'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const error = ref('')
const isSubmitting = ref(false)
const isLoading = ref(false)
const isAnalyzing = ref(false)
const formLoaded = ref(false)
const step = ref<'subject' | 'details'>('subject')
const draftId = ref('')
const analyzedCountry = ref<string | null>(null)

const { subjects, load: loadSubjects } = useSubjectCatalog()

const form = reactive({
  name: '',
  subject: '',
  level: '',
  date: ''
})

const userCountry = computed(() => sessionStore.country.trim())
const educationCycles = computed(() => {
  if (!userCountry.value) return []
  return (EDUCATION_LEVELS[userCountry.value] ?? []).filter(cycle => cycle.levels.length > 0)
})
const unknownLevel = computed(() => {
  const code = form.level.trim()
  if (!code || educationLevelName(userCountry.value, code)) return ''
  return code
})

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

const onSubjectFile = async (event: Event) => {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return

  const userId = sessionStore.hasValidUserId ? sessionStore.user_id : null
  if (!userId) {
    error.value = t('createAssessment.analyzeError')
    return
  }

  error.value = ''
  isAnalyzing.value = true
  try {
    const formData = new FormData()
    formData.append('file', file)
    const response = await sessionStore.getWsClient().queryWs<{
      hash?: string
      name?: string
      subject?: string
      level?: string | null
      country?: string | null
      date?: string
    }>('POST', '/assessment_subject', { locale: String(locale.value) }, formData, 'form')

    const id = response?.hash
    if (!id) {
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
    step.value = 'details'
  } catch (err) {
    console.error('Error analyzing subject:', err)
    error.value = t('createAssessment.analyzeError')
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
}

.file-button {
  display: inline-block;
  padding: 12px 18px;
  background-color: var(--accent);
  color: white;
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: var(--type-label);
}

.file-button input {
  display: none;
}

.file-button.disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.analyzing {
  color: var(--text-muted);
  margin-top: 1rem;
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
