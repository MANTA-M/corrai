<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('exam.loading') }}</p>
      </div>

      <div v-else-if="error && isEditMode && !formLoaded" class="error">
        <h1>{{ t('exam.error') }}</h1>
        <p>{{ error }}</p>
        <button class="back-button" @click="goBack">{{ t('exam.back') }}</button>
      </div>

      <template v-else>
        <div class="header">
          <h1 data-testid="exam-form-heading">{{ pageTitle }}</h1>
          <button class="back-button" @click="goBack">{{ t('exam.back') }}</button>
        </div>
        <div class="content">
          <p class="subtitle">{{ pageSubtitle }}</p>
          <form class="exam-form" @submit.prevent="submitExam">
            <div class="form-group">
              <label for="exam-name">{{ t('exam.name') }}</label>
              <input
                id="exam-name"
                v-model="form.name"
                type="text"
                data-testid="exam-name"
                :placeholder="t('exam.namePlaceholder')"
                required
              />
            </div>
            <div class="form-group">
              <label for="exam-subject">{{ t('exam.subject') }}</label>
              <select
                id="exam-subject"
                v-model="form.subject"
                data-testid="exam-subject"
                required
              >
                <option value="" disabled>{{ t('exam.subjectPlaceholder') }}</option>
                <option
                  v-for="node in subjects"
                  :key="node.subject"
                  :value="node.subject"
                >
                  {{ node.name }}
                </option>
              </select>
            </div>
            <div v-if="countryOptions.length" class="form-group">
              <label for="exam-country">{{ t('exam.country') }}</label>
              <select
                id="exam-country"
                v-model="form.country"
                data-testid="exam-country"
                required
              >
                <option value="" disabled>{{ t('exam.countryPlaceholder') }}</option>
                <option
                  v-for="country in countryOptions"
                  :key="country.country"
                  :value="country.country"
                >
                  {{ country.name }}
                </option>
              </select>
            </div>
            <div v-if="levelOptions.length" class="form-group">
              <label for="exam-level">{{ t('exam.level') }}</label>
              <select
                id="exam-level"
                v-model="form.level"
                data-testid="exam-level"
                required
              >
                <option value="" disabled>{{ t('exam.levelPlaceholder') }}</option>
                <option
                  v-for="level in levelOptions"
                  :key="level.level"
                  :value="level.level"
                >
                  {{ level.name }}
                </option>
              </select>
            </div>
            <div class="form-group">
              <label for="exam-date">{{ t('exam.date') }}</label>
              <input
                id="exam-date"
                v-model="form.date"
                type="date"
                data-testid="exam-date"
                required
              />
            </div>
            <p v-if="error" class="error-message">{{ error }}</p>
            <button
              type="submit"
              class="submit-button"
              :data-testid="isEditMode ? 'exam-save' : 'exam-submit'"
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
import type { Exam, SubjectCountryNode, SubjectLevelNode, SubjectNode } from '@/types/types'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const error = ref('')
const isSubmitting = ref(false)
const isLoading = ref(false)
const formLoaded = ref(false)

const subjects = ref<SubjectNode[]>([])

const form = reactive({
  name: '',
  subject: '',
  country: '',
  level: '',
  date: ''
})

const selectedSubject = computed(() =>
  subjects.value.find(node => node.subject === form.subject) ?? null
)
const countryOptions = computed<SubjectCountryNode[]>(() => selectedSubject.value?.countries ?? [])
const levelOptions = computed<SubjectLevelNode[]>(() => {
  if (form.country) {
    return countryOptions.value.find(country => country.country === form.country)?.levels ?? []
  }
  return selectedSubject.value?.levels ?? []
})

const examId = computed(() => (route.params.id as string | undefined) ?? '')
const isEditMode = computed(() => route.name === 'exam-edit' && !!examId.value)

const pageTitle = computed(() =>
  isEditMode.value ? t('createExam.editTitle') : t('createExam.title')
)
const pageSubtitle = computed(() =>
  isEditMode.value ? t('createExam.editSubtitle') : t('createExam.subtitle')
)
const submitLabel = computed(() => {
  if (isSubmitting.value) {
    return isEditMode.value ? t('exam.saving') : t('exam.creating')
  }
  return isEditMode.value ? t('exam.save') : t('exam.create')
})

const goBack = () => {
  if (isEditMode.value && examId.value) {
    router.push(`/exam/${examId.value}`)
  } else {
    router.push({ name: 'exam-list' })
  }
}

const syncForm = (value: Exam) => {
  form.name = value.name || ''
  form.subject = value.subject || ''
  form.country = value.country || ''
  form.level = value.level || ''
  form.date = value.date || ''
}

const loadSubjects = async () => {
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{ subjects?: SubjectNode[] }>(
      'GET',
      '/subject',
      { locale: locale.value }
    )
    subjects.value = response?.subjects ?? []
  } catch (err) {
    console.error('Error loading subjects:', err)
    subjects.value = []
  }
}

const loadExamForEdit = async (hash: string) => {
  error.value = ''
  formLoaded.value = false
  try {
    const cached = sessionStore.get_exam(hash)
    if (cached) {
      syncForm(cached)
      formLoaded.value = true
    } else {
      isLoading.value = true
    }
    const loaded = await sessionStore.load_exam(hash)
    if (loaded) {
      syncForm(loaded)
      formLoaded.value = true
    } else if (!cached) {
      error.value = t('exam.notFoundMessage', { hash })
    }
  } catch (err) {
    console.error('Error loading exam for edit:', err)
    if (!formLoaded.value) {
      error.value = t('exam.error')
    }
  } finally {
    isLoading.value = false
  }
}

const submitExam = async () => {
  error.value = ''
  if (isEditMode.value) {
    await saveExam()
  } else {
    await createExam()
  }
}

const createExam = async () => {
  const userId = sessionStore.hasValidUserId ? sessionStore.user_id : null
  if (!userId) {
    error.value = t('exam.createError')
    return
  }

  isSubmitting.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const payload = {
      name: form.name.trim(),
      subject: form.subject.trim(),
      country: form.country.trim(),
      level: form.level.trim(),
      date: form.date
    }
    const response = await wsClient.queryWs<{ hash?: string }>(
      'POST',
      '/exam',
      undefined,
      payload
    )

    const id = response?.hash
    if (!id) {
      error.value = t('exam.createError')
      return
    }

    const createdExam: Exam = {
      id,
      author: userId,
      name: payload.name,
      subject: payload.subject,
      country: payload.country,
      level: payload.level,
      date: payload.date,
      files: []
    }
    sessionStore.own_exams.push(createdExam)
    await router.push(`/exam/${id}`)
  } catch (err) {
    console.error('Error creating exam:', err)
    error.value = t('exam.createError')
  } finally {
    isSubmitting.value = false
  }
}

const saveExam = async () => {
  if (!examId.value) return

  isSubmitting.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const payload = {
      name: form.name.trim(),
      subject: form.subject.trim(),
      country: form.country.trim(),
      level: form.level.trim(),
      date: form.date
    }
    await wsClient.queryWs('PUT', '/exam', { hash: examId.value }, payload)

    const existingIndex = sessionStore.own_exams.findIndex(e => e.id === examId.value)
    if (existingIndex !== -1) {
      sessionStore.own_exams[existingIndex] = {
        ...sessionStore.own_exams[existingIndex],
        ...payload
      }
    }

    await router.push(`/exam/${examId.value}`)
  } catch (err) {
    console.error('Error saving exam:', err)
    error.value = t('exam.saveError')
  } finally {
    isSubmitting.value = false
  }
}

const resetCreateForm = () => {
  form.name = ''
  form.subject = ''
  form.country = ''
  form.level = ''
  form.date = ''
  error.value = ''
  formLoaded.value = false
  isLoading.value = false
}

watch(
  () => form.subject,
  () => {
    if (!subjects.value.length) return
    if (form.country && !countryOptions.value.some(country => country.country === form.country)) {
      form.country = ''
    }
    if (form.level && !levelOptions.value.some(level => level.level === form.level)) {
      form.level = ''
    }
  }
)

watch(
  () => form.country,
  () => {
    if (form.level && !levelOptions.value.some(level => level.level === form.level)) {
      form.level = ''
    }
  }
)

watch(locale, () => {
  loadSubjects()
})

onMounted(() => {
  loadSubjects()
  if (isEditMode.value && examId.value) {
    loadExamForEdit(examId.value)
  }
})

watch(
  () => [route.name, examId.value] as const,
  ([name, id]) => {
    if (name === 'exam-edit' && id) {
      loadExamForEdit(id)
    } else if (name === 'create-exam') {
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
