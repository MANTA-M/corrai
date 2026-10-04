<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('assessment.loading') }}</p>
      </div>

      <div v-else-if="error && !assessment" class="error">
        <h1>{{ t('assessment.error') }}</h1>
        <p>{{ error }}</p>
        <button class="button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>

      <div v-else-if="assessment && studentName" class="student-page" data-testid="student-page">
        <div class="header">
          <h1 data-testid="student-name">{{ studentName }}</h1>
          <button type="button" class="button" data-testid="student-back" @click="goBack">
            {{ t('assessment.back') }}
          </button>
        </div>

        <section v-if="hasResult" class="section student-result" data-testid="student-result">
          <p v-if="studentMark != null" class="student-mark" data-testid="student-mark">
            <span class="result-label">{{ t('assessment.studentMark') }}</span>
            {{ formatMark(studentMark) }}
          </p>
          <div v-if="studentAppreciation" data-testid="student-appreciation">
            <h2>{{ t('assessment.studentAppreciation') }}</h2>
            <div class="appreciation" v-html="appreciationHtml"></div>
          </div>
        </section>

        <section class="section">
          <h2>{{ t('assessment.files') }}</h2>
          <AssessmentFileList
            :assessment-id="assessment.id || ''"
            :files="studentFiles"
            :students="students"
            :empty-text="t('assessment.studentFilesEmpty')"
            @updated="onFilesUpdated"
          />
        </section>
      </div>

      <div v-else class="error">
        <h1>{{ t('assessment.studentNotFound') }}</h1>
        <button class="button" data-testid="student-back" @click="goBack">{{ t('assessment.back') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import { useAssessment } from '@/composables/useAssessment'
import type { AssessmentFile, AssessmentStudent } from '@/types/types'
import { isDebugFile } from '@/utils/assessmentFiles'

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()

const studentId = computed(() => route.params.studentId as string)

const student = computed(() => students.value.find((item) => item.id === studentId.value) ?? null)

const studentName = computed(() => {
  if (student.value?.name) return student.value.name
  const fromFile = files.value.find((file) => (file.student ?? '') === studentId.value)
  return (fromFile?.student_name ?? '').trim()
})

const studentMark = computed(() => student.value?.mark ?? null)

const studentAppreciation = computed(() => (student.value?.appreciation ?? '').trim())

const hasResult = computed(() => studentMark.value != null || studentAppreciation.value !== '')

const formatMark = (mark: number) =>
  new Intl.NumberFormat(String(locale.value), { maximumFractionDigits: 2 }).format(mark)

const appreciationHtml = computed(() => markdownToHtml(studentAppreciation.value))

const studentFiles = computed(() =>
  files.value.filter((file) => {
    if ((file.student ?? '') !== studentId.value) return false
    if (isDebugFile(file) && !sessionStore.debugMode) return false
    return true
  })
)

const goBack = () => {
  router.push({ name: 'assessment', params: { id: assessmentId.value } })
}

const onFilesUpdated = (payload: { files: AssessmentFile[]; students?: AssessmentStudent[] }) => {
  applyUpdate(payload.files, payload.students)
}

const escapeHtml = (value: string) =>
  value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

const inlineMarkdown = (value: string) =>
  escapeHtml(value)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')

const markdownToHtml = (source: string): string => {
  const html: string[] = []
  let list: 'ul' | 'ol' | null = null
  const closeList = () => {
    if (list) {
      html.push(list === 'ul' ? '</ul>' : '</ol>')
      list = null
    }
  }
  for (const line of source.replace(/\r\n/g, '\n').split('\n')) {
    const heading = /^(#{1,3})\s+(.*)$/.exec(line)
    const bullet = /^[-*]\s+(.*)$/.exec(line)
    const ordered = /^\d+\.\s+(.*)$/.exec(line)
    if (heading) {
      closeList()
      const level = Math.min(heading[1].length + 2, 6)
      html.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`)
      continue
    }
    if (bullet) {
      if (list !== 'ul') {
        closeList()
        html.push('<ul>')
        list = 'ul'
      }
      html.push(`<li>${inlineMarkdown(bullet[1])}</li>`)
      continue
    }
    if (ordered) {
      if (list !== 'ol') {
        closeList()
        html.push('<ol>')
        list = 'ol'
      }
      html.push(`<li>${inlineMarkdown(ordered[1])}</li>`)
      continue
    }
    closeList()
    if (line.trim() !== '') html.push(`<p>${inlineMarkdown(line)}</p>`)
  }
  closeList()
  return html.join('')
}
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}

.header h1 {
  margin: 0;
}

.section {
  margin-top: 1.5rem;
}

.section h2 {
  margin: 0 0 0.75rem;
  font-size: 1.05rem;
}

.student-result {
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.result-label {
  display: block;
  margin-bottom: 0.2rem;
  color: var(--text-muted);
  font-size: 0.85rem;
  font-weight: 500;
}

.student-mark {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.appreciation :deep(p) {
  margin: 0 0 0.6rem;
}

.appreciation :deep(p:last-child) {
  margin-bottom: 0;
}

.appreciation :deep(ul),
.appreciation :deep(ol) {
  margin: 0.2rem 0 0.6rem;
  padding-left: 1.25rem;
}

.appreciation :deep(h3),
.appreciation :deep(h4),
.appreciation :deep(h5) {
  margin: 0.8rem 0 0.35rem;
  font-size: 1rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
