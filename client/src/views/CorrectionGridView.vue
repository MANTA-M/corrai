<template>
  <div class="app">
    <div v-if="showLoading" class="loading">
      <p>{{ t('assessment.loading') }}</p>
    </div>

    <div v-else-if="error && !assessment" class="error">
      <h1>{{ t('assessment.error') }}</h1>
      <p>{{ error }}</p>
      <button class="button" @click="goBack">{{ t('assessment.back') }}</button>
    </div>

    <div v-else class="grid-page" data-testid="correction-grid-page">
      <div class="header">
        <h1 data-testid="correction-grid-title">{{ pageTitle }}</h1>
        <button type="button" class="button" data-testid="correction-grid-back" @click="goBack">
          {{ t('assessment.back') }}
        </button>
      </div>

      <p v-if="documentError" class="error-message" data-testid="correction-grid-error">
        {{ documentError }}
      </p>

      <article
        v-else-if="documentData"
        class="grid-document"
        data-testid="correction-grid-document"
      >
        <p v-if="documentData.mark != null" class="grid-mark" data-testid="correction-grid-mark">
          <span class="result-label">{{ t('assessment.studentMark') }}</span>
          {{ formatMark(documentData.mark) }}
        </p>

        <section v-if="appreciation" data-testid="correction-grid-appreciation">
          <h2>{{ t('assessment.studentAppreciation') }}</h2>
          <div class="appreciation" v-html="appreciationHtml"></div>
        </section>

        <section v-if="instructions.length" data-testid="correction-grid-instructions">
          <h2>Instructions générales</h2>
          <ul>
            <li v-for="(item, index) in instructions" :key="index">{{ item }}</li>
          </ul>
        </section>

        <section v-if="modifiers.length" data-testid="correction-grid-modifiers">
          <h2>Modificateurs généraux</h2>
          <CorrectionCriteriaTable :criteria="modifiers" :show-application="isStudent" />
        </section>

        <section
          v-for="(part, partIndex) in parts"
          :key="partIndex"
          class="grid-part"
          :data-testid="`correction-grid-part-${partIndex}`"
        >
          <h2>{{ part.titre || `Partie ${partIndex + 1}` }}</h2>
          <div v-if="notes(part).length" class="grid-notes">
            <h3>Nota bene</h3>
            <ul>
              <li v-for="(note, noteIndex) in notes(part)" :key="noteIndex">{{ note }}</li>
            </ul>
          </div>

          <article
            v-for="(question, questionIndex) in questions(part)"
            :key="questionIndex"
            class="grid-question"
          >
            <h3>
              {{ question.titre || `Question ${questionIndex + 1}` }}
              <span v-if="question.points != null" class="grid-points">
                <template v-if="question.points_obtenus != null">
                  {{ question.points_obtenus }} / {{ question.points }}
                </template>
                <template v-else>{{ question.points }} pts</template>
              </span>
            </h3>
            <p v-if="question.contenu" class="grid-content">{{ question.contenu }}</p>
            <div v-if="asked(question).length">
              <h4>Questions posées</h4>
              <ul>
                <li v-for="(item, itemIndex) in asked(question)" :key="itemIndex">{{ item }}</li>
              </ul>
            </div>
            <CorrectionCriteriaTable :criteria="criteria(question)" :show-application="isStudent" />
          </article>
        </section>

        <section
          v-if="!isStudent && legislativeReferences.length"
          data-testid="correction-grid-references"
        >
          <h2>Références législatives</h2>
          <article
            v-for="(item, index) in legislativeReferences"
            :key="index"
            class="grid-reference"
          >
            <h3>{{ item.reference }}</h3>
            <p class="grid-content">{{ item.texte }}</p>
          </article>
        </section>

        <section v-if="remarks.length" data-testid="correction-grid-remarks">
          <h2>Remarques</h2>
          <ul class="grid-remarks">
            <li v-for="(remark, index) in remarks" :key="index">
              <span v-if="remark.page != null" class="remark-page">Page {{ remark.page }}</span>
              {{ remark.text }}
            </li>
          </ul>
        </section>
      </article>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { useAssessment } from '@/composables/useAssessment'
import CorrectionCriteriaTable, {
  type CorrectionCriterion,
} from '@/components/CorrectionCriteriaTable.vue'

interface Question {
  titre?: string
  points?: number
  contenu?: string
  questions_posées?: string[]
  points_obtenus?: number
  critères_proposés?: CorrectionCriterion[]
}

interface Part {
  titre?: string
  questions?: Question[]
  nota_bene?: string[]
}

interface Remark {
  page?: number
  text?: string
}

interface LegislativeReference {
  reference?: string
  texte?: string
}

interface CorrectionDocument {
  instructions_générales?: string[]
  modificateurs_généraux?: CorrectionCriterion[]
  parties?: Part[]
  references?: LegislativeReference[]
  mark?: number
  appreciation?: string
  remarks?: Remark[]
}

const route = useRoute()
const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students } = useAssessment()

const isStudent = computed(() => route.name === 'student-correction-grid')
const studentId = computed(() =>
  typeof route.params.studentId === 'string' ? route.params.studentId : '',
)
const documentData = ref<CorrectionDocument | null>(null)
const documentError = ref('')
const documentLoading = ref(false)

const student = computed(() => students.value.find((item) => item.id === studentId.value) ?? null)

const correctionFileId = computed(
  () =>
    files.value.find(
      (file) => (file.student ?? '') === studentId.value && file.name === 'correction.json',
    )?.id ?? '',
)

const showLoading = computed(() => isLoading.value || documentLoading.value)

const pageTitle = computed(() => {
  const title = t('assessment.correctionGrid')
  if (isStudent.value && student.value?.name) return `${title} — ${student.value.name}`
  if (assessment.value?.name) return `${title} — ${assessment.value.name}`
  return title
})

const instructions = computed(() => strings(documentData.value?.instructions_générales))
const modifiers = computed(() => documentData.value?.modificateurs_généraux ?? [])
const parts = computed(() => documentData.value?.parties ?? [])
const appreciation = computed(() => (documentData.value?.appreciation ?? '').trim())
const appreciationHtml = computed(() => markdownToHtml(appreciation.value))
const remarks = computed(() =>
  (documentData.value?.remarks ?? []).filter((remark) => (remark.text ?? '').trim() !== ''),
)
const legislativeReferences = computed(() =>
  (documentData.value?.references ?? []).filter(
    (item) => (item.reference ?? '').trim() !== '' || (item.texte ?? '').trim() !== '',
  ),
)

const strings = (values: string[] | undefined) =>
  (values ?? []).map((item) => item.trim()).filter((item) => item !== '')
const notes = (part: Part) => strings(part.nota_bene)
const questions = (part: Part) => part.questions ?? []
const asked = (question: Question) => strings(question.questions_posées)
const criteria = (question: Question) => question.critères_proposés ?? []

const formatMark = (mark: number) =>
  new Intl.NumberFormat(String(locale.value), { maximumFractionDigits: 2 }).format(mark)

const goBack = () => {
  if (isStudent.value && studentId.value) {
    router.push({
      name: 'assessment-student',
      params: { id: assessmentId.value, studentId: studentId.value },
    })
    return
  }
  router.push({ name: 'assessment-subject', params: { id: assessmentId.value } })
}

const readJson = async (response: Response): Promise<CorrectionDocument> => {
  const parsed = JSON.parse(await response.text()) as unknown
  if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
    throw new Error('Correction document is not an object')
  }
  return parsed as CorrectionDocument
}

let requestId = 0

const loadDocument = async () => {
  if (!assessmentId.value || (isStudent.value && isLoading.value)) return
  const current = ++requestId
  documentLoading.value = true
  documentError.value = ''
  try {
    const url = isStudent.value
      ? correctionFileId.value
        ? sessionStore.getWsClient().getWsUrl('/file', {
            assessment: assessmentId.value,
            file: correctionFileId.value,
          })
        : ''
      : sessionStore.getWsClient().getWsUrl('/assessment_object', {
          hash: assessmentId.value,
          object: 'subject/correction_grid.json',
        })
    if (!url) {
      documentData.value = null
      documentError.value = t('assessment.studentCorrectionMissing')
      return
    }
    const response = await fetch(url, { credentials: 'include' })
    if (current !== requestId) return
    if (!response.ok) {
      documentData.value = null
      documentError.value = isStudent.value
        ? t('assessment.studentCorrectionMissing')
        : t('assessment.correctionGridMissing')
      return
    }
    documentData.value = await readJson(response)
  } catch (err) {
    if (current !== requestId) return
    console.error('Error loading correction document:', err)
    documentData.value = null
    documentError.value = isStudent.value
      ? t('assessment.studentCorrectionMissing')
      : t('assessment.correctionGridMissing')
  } finally {
    if (current === requestId) documentLoading.value = false
  }
}

watch(
  [assessmentId, studentId, isStudent, isLoading, correctionFileId],
  () => {
    void loadDocument()
  },
  { immediate: true },
)

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
  let paragraph: string[] = []
  const closeList = () => {
    if (list) {
      html.push(list === 'ul' ? '</ul>' : '</ol>')
      list = null
    }
  }
  const closeParagraph = () => {
    if (paragraph.length === 0) return
    html.push(`<p>${paragraph.join('<br>')}</p>`)
    paragraph = []
  }
  for (const line of source.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n')) {
    const heading = /^(#{1,3})\s+(.*)$/.exec(line)
    const bullet = /^[-*]\s+(.*)$/.exec(line)
    const ordered = /^\d+\.\s+(.*)$/.exec(line)
    if (heading) {
      closeParagraph()
      closeList()
      const level = Math.min(heading[1].length + 2, 6)
      html.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`)
      continue
    }
    if (bullet) {
      closeParagraph()
      if (list !== 'ul') {
        closeList()
        html.push('<ul>')
        list = 'ul'
      }
      html.push(`<li>${inlineMarkdown(bullet[1])}</li>`)
      continue
    }
    if (ordered) {
      closeParagraph()
      if (list !== 'ol') {
        closeList()
        html.push('<ol>')
        list = 'ol'
      }
      html.push(`<li>${inlineMarkdown(ordered[1])}</li>`)
      continue
    }
    if (line.trim() === '') {
      closeList()
      closeParagraph()
      continue
    }
    closeList()
    paragraph.push(inlineMarkdown(line))
  }
  closeList()
  closeParagraph()
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

.grid-document {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
  margin-top: 1.5rem;
}

.grid-document h2 {
  margin: 0 0 0.75rem;
  font-size: 1.15rem;
}

.grid-document h3 {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
  align-items: baseline;
  margin: 0 0 0.5rem;
  font-size: 1.02rem;
}

.grid-document h4 {
  margin: 0.75rem 0 0.35rem;
  font-size: 0.95rem;
}

.grid-document ul {
  margin: 0;
  padding-left: 1.25rem;
}

.grid-mark {
  margin: 0;
  font-size: 1.35rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.result-label {
  display: block;
  margin-bottom: 0.2rem;
  color: var(--text-muted);
  font-size: 0.85rem;
  font-weight: 500;
}

.grid-part,
.grid-question {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.grid-question {
  padding-top: 0.25rem;
}

.grid-points {
  flex-shrink: 0;
  color: var(--text-muted);
  font-size: 0.92rem;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}

.grid-content {
  margin: 0;
  white-space: pre-wrap;
}

.grid-reference + .grid-reference {
  margin-top: 1rem;
}

.grid-remarks {
  list-style: none;
  padding: 0;
}

.grid-remarks li {
  margin: 0 0 0.6rem;
}

.remark-page {
  display: inline-block;
  margin-right: 0.45rem;
  color: var(--text-muted);
  font-weight: 600;
}

.appreciation :deep(p) {
  margin: 0 0 0.6rem;
}

.appreciation :deep(ul),
.appreciation :deep(ol) {
  margin: 0.2rem 0 0.6rem;
  padding-left: 1.25rem;
}

.error-message {
  color: var(--danger);
  margin-top: 1rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
