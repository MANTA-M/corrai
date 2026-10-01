<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('assessment.loading') }}</p>
      </div>

      <div v-else-if="error && !assessment" class="error">
        <h1>{{ t('assessment.error') }}</h1>
        <p>{{ error }}</p>
        <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>

      <div v-else-if="assessment" class="assessment-view">
        <div class="header">
          <h1 data-testid="assessment-details-heading">{{ assessment.name || t('assessment.details') }}</h1>
          <div class="header-actions">
            <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
            <button
              type="button"
              class="button edit-button"
              data-testid="assessment-edit"
              @click="goEdit"
            >
              {{ t('assessment.edit') }}
            </button>
            <button
              type="button"
              class="button delete-button"
              data-testid="assessment-delete"
              :disabled="isDeleting"
              @click="confirmDelete"
            >
              {{ isDeleting ? t('assessment.deleting') : t('assessment.delete') }}
            </button>
          </div>
        </div>

        <div class="content">
          <dl class="assessment-details" data-testid="assessment-details">
            <div class="detail-row">
              <dt>{{ t('assessment.name') }}</dt>
              <dd data-testid="assessment-name-value">{{ assessment.name || '—' }}</dd>
            </div>
            <div class="detail-row">
              <dt>{{ t('assessment.subject') }}</dt>
              <dd data-testid="assessment-subject-value">{{ subjectLabel(assessment.subject) }}</dd>
            </div>
            <div v-if="assessment.country" class="detail-row">
              <dt>{{ t('assessment.country') }}</dt>
              <dd data-testid="assessment-country-value">{{ countryLabel(assessment.subject, assessment.country) }}</dd>
            </div>
            <div v-if="assessment.level" class="detail-row">
              <dt>{{ t('assessment.level') }}</dt>
              <dd data-testid="assessment-level-value">{{ levelLabel(assessment.subject, assessment.country, assessment.level) }}</dd>
            </div>
            <div class="detail-row">
              <dt>{{ t('assessment.date') }}</dt>
              <dd data-testid="assessment-date-value">{{ assessment.date || '—' }}</dd>
            </div>
          </dl>

          <p v-if="error" class="error-message">{{ error }}</p>

          <AssessmentFilesSection
            v-if="assessment.id"
            :assessment-id="assessment.id"
            :files="files"
            :students="students"
            @updated="onFilesUpdated"
          />
        </div>
      </div>

      <div v-else class="error">
        <h1>{{ t('assessment.notFound') }}</h1>
        <p>{{ t('assessment.notFoundMessage', { hash: assessmentId }) }}</p>
        <button class="back-button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import AssessmentFilesSection from '@/components/AssessmentFilesSection.vue'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { educationLevelName } from '@/data/levels'
import { localizedCountries } from '@/data/countries'
import { isAssessmentSubject, type Assessment, type AssessmentFile, type AssessmentStudent } from '@/types/types'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const assessment = ref<Assessment | null>(null)
const isLoading = ref(true)
const isDeleting = ref(false)
const error = ref('')

const { subjects, load: loadSubjects, countryName, levelName } = useSubjectCatalog()

const subjectLabel = (subject: string) => {
  const node = subjects.value.find(item => item.subject === subject)
  if (node?.name) return node.name
  if (isAssessmentSubject(subject)) return t(`assessment.subjects.${subject}`)
  return subject || '—'
}

const countryLabel = (subject: string, country: string | null | undefined) => {
  if (!country) return '—'
  const fromCatalog = countryName(subject, country)
  if (fromCatalog && fromCatalog !== country) return fromCatalog
  const localized = localizedCountries(String(locale.value)).find(item => item.code === country)
  return localized?.name || fromCatalog || '—'
}

const levelLabel = (subject: string, country: string | null | undefined, level: string | null | undefined) => {
  if (!level) return '—'
  const fromEducation = country ? educationLevelName(country, level) : null
  if (fromEducation) return fromEducation
  return levelName(subject, country, level) || '—'
}

const assessmentId = computed(() => route.params.id as string)
const files = computed(() => assessment.value?.files ?? [])
const students = computed(() => assessment.value?.students ?? [])

const goBack = () => {
  router.push({ name: 'assessment-list' })
}

const goEdit = () => {
  if (!assessment.value?.id) return
  router.push(`/assessment/${assessment.value.id}/edit`)
}

const onFilesUpdated = (payload: { files: AssessmentFile[]; students?: AssessmentStudent[] }) => {
  if (!assessment.value) return
  assessment.value = {
    ...assessment.value,
    files: payload.files,
    students: payload.students ?? assessment.value.students ?? [],
  }
}

const loadAssessment = async (hash: string) => {
  isLoading.value = true
  error.value = ''
  try {
    const cached = sessionStore.get_assessment(hash)
    if (cached) {
      assessment.value = cached
    }
    const loaded = await sessionStore.load_assessment(hash)
    if (loaded) {
      assessment.value = loaded
    } else if (!cached) {
      assessment.value = null
      error.value = t('assessment.notFoundMessage', { hash })
    }
  } catch (err) {
    console.error('Error loading assessment:', err)
    if (!assessment.value) {
      error.value = t('assessment.error')
    }
  } finally {
    isLoading.value = false
  }
}

const confirmDelete = async () => {
  if (!assessment.value?.id) return
  if (!confirm(t('assessment.deleteConfirm'))) return

  isDeleting.value = true
  error.value = ''
  try {
    const wsClient = sessionStore.getWsClient()
    await wsClient.queryWs('DELETE', '/assessment', { hash: assessment.value.id })
    sessionStore.remove_assessment(assessment.value.id)
    router.push({ name: 'assessment-list' })
  } catch (err) {
    console.error('Error deleting assessment:', err)
    error.value = t('assessment.deleteError')
  } finally {
    isDeleting.value = false
  }
}

onMounted(() => {
  loadSubjects()
  if (assessmentId.value) {
    loadAssessment(assessmentId.value)
  }
})

watch(assessmentId, (newId) => {
  if (newId) {
    loadAssessment(newId)
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
  padding: 12px 18px;
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: var(--type-label);
}

.back-button {
  background: transparent;
  border: 1px solid var(--border-color);
  color: var(--text);
}

.back-button:hover {
  background: var(--hover-bg);
}

.edit-button {
  background-color: var(--accent);
  color: white;
  border: none;
}

.edit-button:hover {
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

.assessment-details {
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

.error-message {
  color: var(--danger);
  margin-bottom: 1rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
