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

      <template v-else>
        <div class="header">
          <h1 data-testid="assessment-form-heading">{{ pageTitle }}</h1>
          <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
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
            <button
              type="submit"
              class="submit-button"
              :data-testid="isEditMode ? 'assessment-save' : 'assessment-submit'"
              :disabled="isSubmitting"
            >
              {{ submitLabel }}
            </button>
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
const formLoaded = ref(false)

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

const pageTitle = computed(() =>
  isEditMode.value ? t('createAssessment.editTitle') : t('createAssessment.title')
)
const pageSubtitle = computed(() =>
  isEditMode.value ? t('createAssessment.editSubtitle') : t('createAssessment.subtitle')
)
const submitLabel = computed(() => {
  if (isSubmitting.value) {
    return isEditMode.value ? t('assessment.saving') : t('assessment.creating')
  }
  return isEditMode.value ? t('assessment.save') : t('assessment.create')
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

const loadAssessmentForEdit = async (hash: string) => {
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
    if (loaded) {
      formLoaded.value = true
      syncForm(loaded)
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
    const response = await wsClient.queryWs<{ hash?: string }>(
      'POST',
      '/assessment',
      undefined,
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

    const existingIndex = sessionStore.own_assessments.findIndex(e => e.id === assessmentId.value)
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
  country: userCountry.value || null,
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
}

watch(locale, () => {
  refreshSubjects()
})

onMounted(() => {
  refreshSubjects()
  if (isEditMode.value && assessmentId.value) {
    loadAssessmentForEdit(assessmentId.value)
  }
})

watch(
  () => [route.name, assessmentId.value] as const,
  ([name, id]) => {
    if (name === 'assessment-edit' && id) {
      loadAssessmentForEdit(id)
    } else if (name === 'create-assessment') {
      resetCreateForm()
    }
  }
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

.error-message {
  color: var(--danger);
  margin-bottom: 1rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
