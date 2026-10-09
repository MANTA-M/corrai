<template>
  <div class="app correction-grid-app">
    <div v-if="showLoading" class="loading-state">
      <div class="spinner"></div>
      <p>{{ t('assessment.loading') }}</p>
    </div>

    <div v-else-if="error && !assessment" class="error-state">
      <div class="error-card">
        <h1>{{ t('assessment.error') }}</h1>
        <p>{{ error }}</p>
        <button class="button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>
    </div>

    <div v-else class="grid-page" data-testid="correction-grid-page">
      <!-- Barre supérieure d'action et navigation -->
      <nav class="top-nav no-print">
        <button
          type="button"
          class="button button-back"
          data-testid="correction-grid-back"
          @click="goBack"
        >
          <svg
            class="icon"
            viewBox="0 0 24 24"
            width="18"
            height="18"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
          >
            <line x1="19" y1="12" x2="5" y2="12"></line>
            <polyline points="12 19 5 12 12 5"></polyline>
          </svg>
          {{ isStudent ? 'Retour à la copie' : 'Retour au sujet' }}
        </button>

        <div class="nav-actions">
          <button
            type="button"
            class="button button-print"
            title="Imprimer ou enregistrer en PDF"
            @click="printPage"
          >
            <svg
              class="icon"
              viewBox="0 0 24 24"
              width="18"
              height="18"
              fill="none"
              stroke="currentColor"
              stroke-width="2"
              stroke-linecap="round"
              stroke-linejoin="round"
            >
              <polyline points="6 9 6 2 18 2 18 9"></polyline>
              <path
                d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"
              ></path>
              <rect x="6" y="14" width="12" height="8"></rect>
            </svg>
            Imprimer
          </button>
        </div>
      </nav>

      <!-- En-tête du document -->
      <header class="document-header">
        <div class="header-badge-row">
          <span v-if="isStudent" class="doc-badge badge-student">
            Copie d'examen • Correction individuelle
          </span>
          <span v-else class="doc-badge badge-subject">
            Sujet d'examen • Grille officielle de correction
          </span>
        </div>

        <h1 class="document-title" data-testid="correction-grid-title">{{ pageTitle }}</h1>

        <p v-if="assessment?.name" class="document-subtitle">
          Évaluation : <strong>{{ assessment.name }}</strong>
        </p>
      </header>

      <p v-if="documentError" class="error-message" data-testid="correction-grid-error">
        {{ documentError }}
      </p>

      <article
        v-else-if="documentData"
        class="grid-document"
        data-testid="correction-grid-document"
      >
        <!-- Bilan de synthèse (Hero Card) -->
        <section v-if="isStudent && documentData.mark != null" class="summary-card hero-student">
          <div class="summary-mark-block">
            <span class="summary-label">{{ t('assessment.studentMark') }} finale</span>
            <div class="summary-mark-display" data-testid="correction-grid-mark">
              <span class="mark-number" :class="studentMarkClass(documentData.mark)">
                {{ formatMark(documentData.mark) }}
              </span>
              <span class="mark-denom">/ 20</span>
            </div>
            <span
              v-if="studentMarkLabel(documentData.mark)"
              class="mention-badge"
              :class="studentMarkClass(documentData.mark)"
            >
              {{ studentMarkLabel(documentData.mark) }}
            </span>
          </div>

          <div class="summary-stats-grid">
            <div class="stat-item">
              <span class="stat-label">Total des questions</span>
              <span class="stat-value">
                {{ formatNumber(totalStudentQuestionsScore) }} /
                {{ formatNumber(totalSubjectPoints) }} pts
              </span>
            </div>
            <div class="stat-item">
              <span class="stat-label">Modificateurs appliqués</span>
              <span
                class="stat-value"
                :class="{
                  'text-danger': totalModifiersImpact < 0,
                  'text-success': totalModifiersImpact > 0,
                }"
              >
                {{ formatModifierImpact(totalModifiersImpact) }}
              </span>
            </div>
            <div class="stat-item">
              <span class="stat-label">Parties évaluées</span>
              <span class="stat-value">{{ parts.length }}</span>
            </div>
          </div>
        </section>

        <!-- Bilan de synthèse pour le sujet -->
        <section v-else-if="!isStudent" class="summary-card hero-subject">
          <div class="subject-stat-card">
            <span class="stat-label">Barème total</span>
            <span class="stat-value stat-primary">
              {{ formatNumber(totalSubjectPoints) }} points
            </span>
          </div>
          <div class="subject-stat-card">
            <span class="stat-label">Nombre de parties</span>
            <span class="stat-value">{{ parts.length }}</span>
          </div>
          <div class="subject-stat-card">
            <span class="stat-label">Questions formulées</span>
            <span class="stat-value">{{ totalQuestionsCount }}</span>
          </div>
          <div v-if="legislativeReferences.length" class="subject-stat-card">
            <span class="stat-label">Références législatives</span>
            <span class="stat-value">{{ legislativeReferences.length }} textes</span>
          </div>
        </section>

        <!-- Appréciation générale de la copie (Étudiant) -->
        <section
          v-if="appreciation"
          class="content-card appreciation-card"
          data-testid="correction-grid-appreciation"
        >
          <div class="card-header">
            <div class="header-title-group">
              <span class="header-icon appreciation-icon">
                <svg
                  viewBox="0 0 24 24"
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"
                  ></path>
                </svg>
              </span>
              <h2>{{ t('assessment.studentAppreciation') }} du correcteur</h2>
            </div>
          </div>
          <div class="appreciation-body" v-html="appreciationHtml"></div>
        </section>

        <!-- Instructions générales (Sujet) -->
        <section
          v-if="instructions.length"
          class="content-card instructions-card"
          data-testid="correction-grid-instructions"
        >
          <div class="card-header">
            <div class="header-title-group">
              <span class="header-icon instructions-icon">
                <svg
                  viewBox="0 0 24 24"
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <circle cx="12" cy="12" r="10"></circle>
                  <line x1="12" y1="16" x2="12" y2="12"></line>
                  <line x1="12" y1="8" x2="12.01" y2="8"></line>
                </svg>
              </span>
              <h2>Instructions générales et barème de correction</h2>
            </div>
          </div>
          <div class="instructions-list">
            <div
              v-for="(item, index) in instructions"
              :key="index"
              class="instruction-item"
            >
              <span class="instruction-index">{{ index + 1 }}</span>
              <p class="instruction-text">{{ item }}</p>
            </div>
          </div>
        </section>

        <!-- Modificateurs généraux -->
        <section
          v-if="modifiers.length"
          class="content-card modifiers-card"
          data-testid="correction-grid-modifiers"
        >
          <div class="card-header">
            <div class="header-title-group">
              <span class="header-icon modifiers-icon">
                <svg
                  viewBox="0 0 24 24"
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <line x1="4" y1="21" x2="4" y2="14"></line>
                  <line x1="4" y1="10" x2="4" y2="3"></line>
                  <line x1="12" y1="21" x2="12" y2="12"></line>
                  <line x1="12" y1="8" x2="12" y2="3"></line>
                  <line x1="20" y1="21" x2="20" y2="16"></line>
                  <line x1="20" y1="12" x2="20" y2="3"></line>
                  <line x1="1" y1="14" x2="7" y2="14"></line>
                  <line x1="9" y1="8" x2="15" y2="8"></line>
                  <line x1="17" y1="16" x2="23" y2="16"></line>
                </svg>
              </span>
              <div>
                <h2>Modificateurs généraux transversaux</h2>
                <p class="card-subtitle">
                  Règles formelles globales applicables à la copie (présentation, lisibilité, orthographe).
                </p>
              </div>
            </div>
          </div>
          <CorrectionCriteriaTable :criteria="modifiers" :show-application="isStudent" />
        </section>

        <!-- Parties et Questions -->
        <section
          v-for="(part, partIndex) in parts"
          :key="partIndex"
          class="content-card part-card"
          :data-testid="`correction-grid-part-${partIndex}`"
        >
          <div class="part-header">
            <div class="part-title-wrapper">
              <span class="part-badge">Partie {{ partIndex + 1 }}</span>
              <h2 class="part-title">{{ part.titre || `Partie ${partIndex + 1}` }}</h2>
            </div>
            <div class="part-points-badge">
              <template v-if="isStudent">
                <span class="part-points-label">Total partie :</span>
                <span class="part-points-value">
                  {{ getPartScore(part) }} / {{ getPartTotal(part) }} pts
                </span>
              </template>
              <template v-else>
                <span class="part-points-label">Barème partie :</span>
                <span class="part-points-value">{{ getPartTotal(part) }} pts</span>
              </template>
            </div>
          </div>

          <!-- Nota bene -->
          <div v-if="notes(part).length" class="grid-notes">
            <div class="notes-header">
              <svg
                class="icon notes-icon"
                viewBox="0 0 24 24"
                width="18"
                height="18"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
              >
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
              </svg>
              <h3>Nota bene</h3>
            </div>
            <ul class="notes-list">
              <li v-for="(note, noteIndex) in notes(part)" :key="noteIndex">{{ note }}</li>
            </ul>
          </div>

          <!-- Questions de la partie -->
          <div class="questions-list">
            <article
              v-for="(question, questionIndex) in questions(part)"
              :key="questionIndex"
              class="question-card"
            >
              <div class="question-header">
                <div class="question-title-wrapper">
                  <span class="question-number">Q{{ questionIndex + 1 }}</span>
                  <h3 class="question-title">
                    {{ question.titre || `Question ${questionIndex + 1}` }}
                  </h3>
                </div>
                <div
                  class="question-points-badge"
                  :class="getQuestionBadgeClass(question)"
                >
                  <template v-if="isStudent && question.points_obtenus != null">
                    <span class="obtained-points">{{ formatNumber(question.points_obtenus) }}</span>
                    <span class="denom-points">/ {{ formatNumber(question.points) }} pts</span>
                  </template>
                  <template v-else-if="question.points != null">
                    <span class="bareme-points">{{ formatNumber(question.points) }} pts</span>
                  </template>
                </div>
              </div>

              <!-- Questions posées -->
              <div v-if="asked(question).length" class="asked-questions-box">
                <span class="box-label">Questions posées au candidat :</span>
                <ul class="asked-list">
                  <li v-for="(item, itemIndex) in asked(question)" :key="itemIndex">
                    {{ item }}
                  </li>
                </ul>
              </div>

              <!-- Solution attendue / Corrigé type pour le sujet -->
              <div v-if="!isStudent && question.contenu" class="solution-box">
                <div class="solution-header">
                  <svg
                    viewBox="0 0 24 24"
                    width="16"
                    height="16"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                  >
                    <polyline points="20 6 9 17 4 12"></polyline>
                  </svg>
                  <span class="solution-title">Corrigé type & Démarche attendue</span>
                </div>
                <div
                  class="solution-content"
                  v-html="formatSolutionHtml(question.contenu)"
                ></div>
              </div>

              <!-- Critères d'évaluation de la question (Étudiant) -->
              <div v-if="isStudent" class="criteria-container">
                <div v-if="question.critères && question.critères.length">
                  <span class="box-label">Détail de l'évaluation par critères :</span>
                  <QuestionCriteriaTable :criteria="question.critères" />
                </div>
                <div
                  v-else-if="
                    question.critères_proposés && question.critères_proposés.length
                  "
                >
                  <span class="box-label">Critères évalués :</span>
                  <CorrectionCriteriaTable
                    :criteria="question.critères_proposés"
                    :show-application="true"
                  />
                </div>
              </div>

              <!-- Si l'étudiant a aussi du contenu de correction disponible -->
              <details v-if="isStudent && question.contenu" class="student-solution-toggle">
                <summary class="toggle-summary">Consulter le corrigé type officiel</summary>
                <div
                  class="solution-content solution-drawer"
                  v-html="formatSolutionHtml(question.contenu)"
                ></div>
              </details>
            </article>
          </div>
        </section>

        <!-- Références législatives (Sujet) -->
        <section
          v-if="!isStudent && legislativeReferences.length"
          class="content-card references-card"
          data-testid="correction-grid-references"
        >
          <div class="card-header">
            <div class="header-title-group">
              <span class="header-icon references-icon">
                <svg
                  viewBox="0 0 24 24"
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"></path>
                  <path
                    d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"
                  ></path>
                </svg>
              </span>
              <div>
                <h2>Références législatives & Textes applicables</h2>
                <p class="card-subtitle">
                  Articles et dispositions textuelles en vigueur mobilisés pour le corrigé.
                </p>
              </div>
            </div>
          </div>

          <div class="references-grid">
            <article
              v-for="(item, index) in legislativeReferences"
              :key="index"
              class="reference-card"
            >
              <div class="reference-header">
                <span class="reference-tag">Article</span>
                <h3 class="reference-title">{{ item.reference }}</h3>
              </div>
              <div class="reference-body">
                <p class="reference-text">{{ item.texte }}</p>
              </div>
            </article>
          </div>
        </section>

        <!-- Remarques sur la copie (Étudiant) -->
        <section
          v-if="remarks.length"
          class="content-card remarks-card"
          data-testid="correction-grid-remarks"
        >
          <div class="card-header">
            <div class="header-title-group">
              <span class="header-icon remarks-icon">
                <svg
                  viewBox="0 0 24 24"
                  width="20"
                  height="20"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path
                    d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"
                  ></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                  <line x1="16" y1="13" x2="8" y2="13"></line>
                  <line x1="16" y1="17" x2="8" y2="17"></line>
                  <polyline points="10 9 9 9 8 9"></polyline>
                </svg>
              </span>
              <div>
                <h2>Remarques et annotations détaillées sur la copie</h2>
                <p class="card-subtitle">
                  Observations relevées au fil des pages lors de l'examen de la copie.
                </p>
              </div>
            </div>
          </div>

          <div class="remarks-list">
            <div v-for="(remark, index) in remarks" :key="index" class="remark-item">
              <div class="remark-page-badge" :class="{ 'page-known': remark.page != null }">
                <svg
                  viewBox="0 0 24 24"
                  width="14"
                  height="14"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                >
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
                <span>{{ remark.page != null ? `Page ${remark.page}` : 'Général' }}</span>
              </div>
              <p class="remark-text">{{ remark.text }}</p>
            </div>
          </div>
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
import QuestionCriteriaTable, {
  type QuestionCriterion,
} from '@/components/QuestionCriteriaTable.vue'

interface Question {
  titre?: string
  points?: number
  contenu?: string
  questions_posées?: string[]
  points_obtenus?: number
  critères?: QuestionCriterion[]
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

const formatMark = (mark: number) =>
  new Intl.NumberFormat(String(locale.value), {
    maximumFractionDigits: 2,
    minimumFractionDigits: 1,
  }).format(mark)

const formatNumber = (val: number | undefined) => {
  if (val == null || Number.isNaN(val)) return '0'
  return new Intl.NumberFormat(String(locale.value), {
    maximumFractionDigits: 2,
    minimumFractionDigits: 0,
  }).format(val)
}

const totalSubjectPoints = computed(() => {
  let total = 0
  for (const part of parts.value) {
    for (const q of questions(part)) {
      total += q.points ?? 0
    }
  }
  return total
})

const totalStudentQuestionsScore = computed(() => {
  let total = 0
  for (const part of parts.value) {
    for (const q of questions(part)) {
      total += q.points_obtenus ?? 0
    }
  }
  return total
})

const totalQuestionsCount = computed(() => {
  let count = 0
  for (const part of parts.value) {
    count += (part.questions ?? []).length
  }
  return count
})

const totalModifiersImpact = computed(() => {
  let impact = 0
  for (const mod of modifiers.value) {
    if (mod.retenu === true && mod.modificateur != null) {
      impact += mod.modificateur
    }
  }
  return impact
})

const formatModifierImpact = (val: number) => {
  if (val === 0) return '0 pt (aucun impact)'
  if (val > 0) return `+${val} pt${Math.abs(val) > 1 ? 's' : ''}`
  return `${val} pt${Math.abs(val) > 1 ? 's' : ''}`
}

const getPartTotal = (part: Part): string => {
  const sum = (part.questions ?? []).reduce((acc, q) => acc + (q.points ?? 0), 0)
  return formatNumber(sum)
}

const getPartScore = (part: Part): string => {
  const sum = (part.questions ?? []).reduce((acc, q) => acc + (q.points_obtenus ?? 0), 0)
  return formatNumber(sum)
}

const getQuestionBadgeClass = (question: Question) => {
  if (!isStudent.value || question.points_obtenus == null) return 'badge-neutral'
  const obtained = question.points_obtenus
  const total = question.points ?? 0
  if (total > 0 && obtained >= total) return 'badge-success'
  if (obtained > 0) return 'badge-warning'
  return 'badge-danger'
}

const studentMarkClass = (mark: number) => {
  if (mark >= 14) return 'mark-success'
  if (mark >= 10) return 'mark-pass'
  if (mark >= 8) return 'mark-warning'
  return 'mark-danger'
}

const studentMarkLabel = (mark: number) => {
  if (mark >= 16) return 'Très bien'
  if (mark >= 14) return 'Bien'
  if (mark >= 12) return 'Assez bien'
  if (mark >= 10) return 'Moyenne atteinte'
  if (mark >= 8) return 'Insuffisant'
  return 'Très insuffisant'
}

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

const printPage = () => {
  window.print()
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

const formatSolutionHtml = (text: string): string => {
  if (!text) return ''
  const escaped = escapeHtml(text)
  // Découpage et mise en valeur élégante des étapes du raisonnement juridique
  return escaped
    .replace(/(Majeure\s*:)/g, '<strong class="syllogism-tag tag-majeure">$1</strong>')
    .replace(/(Mineure\s*:)/g, '<br><br><strong class="syllogism-tag tag-mineure">$1</strong>')
    .replace(
      /(Conclusion\s*:)/g,
      '<br><br><strong class="syllogism-tag tag-conclusion">$1</strong>',
    )
    .replace(/(Problème\s*:)/g, '<strong class="syllogism-tag tag-probleme">$1</strong>')
}
</script>

<style scoped>
.correction-grid-app {
  max-width: 1040px;
  margin: 0 auto;
  padding: 1.5rem 1rem 4rem;
}

/* Navigation supérieure */
.top-nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 1.5rem;
}

.button-back {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #dfe5f0);
  color: var(--text, #111c38);
  font-weight: 500;
  padding: 0.5rem 0.9rem;
  border-radius: var(--radius-sm, 8px);
  cursor: pointer;
  transition: all 0.15s ease;
}

.button-back:hover {
  background: var(--surface-2, #f7f9fd);
  border-color: var(--blue, #1a55e8);
  color: var(--blue, #1a55e8);
}

.button-print {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #dfe5f0);
  color: var(--muted, #5d6680);
  font-weight: 500;
  padding: 0.5rem 0.85rem;
  border-radius: var(--radius-sm, 8px);
  cursor: pointer;
  transition: all 0.15s ease;
}

.button-print:hover {
  color: var(--text, #111c38);
  border-color: var(--border, #c5d4f4);
  background: var(--surface-2, #f7f9fd);
}

/* En-tête du document */
.document-header {
  margin-bottom: 2rem;
  padding-bottom: 1.25rem;
  border-bottom: 1px solid var(--border, #dfe5f0);
}

.header-badge-row {
  margin-bottom: 0.6rem;
}

.doc-badge {
  display: inline-block;
  padding: 0.25rem 0.75rem;
  border-radius: 9999px;
  font-size: 0.78rem;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
}

.badge-student {
  background: #eff6ff;
  color: var(--blue, #1a55e8);
  border: 1px solid #bfdbfe;
}

.badge-subject {
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #bbf7d0;
}

.document-title {
  margin: 0 0 0.4rem;
  font-size: 1.75rem;
  font-weight: 800;
  color: var(--text, #111c38);
  line-height: 1.25;
}

.document-subtitle {
  margin: 0;
  font-size: 1rem;
  color: var(--muted, #5d6680);
}

.document-subtitle strong {
  color: var(--text, #111c38);
}

/* Document body */
.grid-document {
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

/* Cartes génériques */
.content-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #dfe5f0);
  border-radius: var(--radius-md, 10px);
  padding: 1.5rem;
  box-shadow: 0 2px 6px rgba(11, 23, 53, 0.04);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1.25rem;
  padding-bottom: 0.85rem;
  border-bottom: 1px solid var(--border, #dfe5f0);
}

.header-title-group {
  display: flex;
  align-items: flex-start;
  gap: 0.75rem;
}

.header-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  flex-shrink: 0;
}

.appreciation-icon {
  background: #eff6ff;
  color: var(--blue, #1a55e8);
}

.instructions-icon {
  background: #fef3c7;
  color: #b45309;
}

.modifiers-icon {
  background: #f1f5f9;
  color: #475569;
}

.references-icon {
  background: #f0fdf4;
  color: #166534;
}

.remarks-icon {
  background: #fae8ff;
  color: #86198f;
}

.card-header h2 {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--text, #111c38);
}

.card-subtitle {
  margin: 0.25rem 0 0;
  font-size: 0.875rem;
  color: var(--muted, #5d6680);
}

/* Cartes Bilan (Hero cards) */
.summary-card {
  border-radius: var(--radius-md, 10px);
  padding: 1.5rem;
  box-shadow: 0 4px 12px rgba(11, 23, 53, 0.06);
}

.hero-student {
  background: linear-gradient(135deg, #f8fafc 0%, #eff6ff 100%);
  border: 1px solid #bfdbfe;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 2rem;
  flex-wrap: wrap;
}

.summary-mark-block {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.summary-label {
  font-size: 0.82rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted, #5d6680);
}

.summary-mark-display {
  display: flex;
  align-items: baseline;
  gap: 0.35rem;
}

.mark-number {
  font-size: 3rem;
  font-weight: 800;
  line-height: 1;
  font-variant-numeric: tabular-nums;
}

.mark-denom {
  font-size: 1.35rem;
  font-weight: 600;
  color: var(--muted, #5d6680);
}

.mark-success {
  color: #15803d;
}

.mark-pass {
  color: #1a55e8;
}

.mark-warning {
  color: #b45309;
}

.mark-danger {
  color: #b91c1c;
}

.mention-badge {
  display: inline-block;
  padding: 0.25rem 0.65rem;
  border-radius: 9999px;
  font-size: 0.82rem;
  font-weight: 700;
  width: fit-content;
}

.mention-badge.mark-success {
  background: #dcfce7;
  color: #15803d;
}

.mention-badge.mark-pass {
  background: #dbeafe;
  color: #1e40af;
}

.mention-badge.mark-warning {
  background: #fef3c7;
  color: #92400e;
}

.mention-badge.mark-danger {
  background: #fee2e2;
  color: #b91c1c;
}

.summary-stats-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 1.25rem;
  flex: 1;
  max-width: 500px;
}

.stat-item {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #dfe5f0);
  padding: 0.85rem 1rem;
  border-radius: var(--radius-sm, 8px);
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.stat-label {
  font-size: 0.8rem;
  color: var(--muted, #5d6680);
  font-weight: 500;
}

.stat-value {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--text, #111c38);
  font-variant-numeric: tabular-nums;
}

.text-danger {
  color: #b91c1c;
}

.text-success {
  color: #15803d;
}

.hero-subject {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #dfe5f0);
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 1rem;
}

.subject-stat-card {
  padding: 1rem;
  border-radius: var(--radius-sm, 8px);
  background: var(--surface-2, #f7f9fd);
  border: 1px solid var(--border, #dfe5f0);
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.stat-primary {
  color: var(--blue, #1a55e8);
}

/* Appréciation */
.appreciation-card {
  border-left: 4px solid var(--blue, #1a55e8);
}

.appreciation-body {
  font-size: 1rem;
  line-height: 1.65;
  color: var(--text, #111c38);
}

.appreciation-body :deep(p) {
  margin: 0 0 0.85rem;
}

.appreciation-body :deep(p:last-child) {
  margin-bottom: 0;
}

.appreciation-body :deep(ul),
.appreciation-body :deep(ol) {
  margin: 0.5rem 0 0.85rem;
  padding-left: 1.5rem;
}

.appreciation-body :deep(li) {
  margin-bottom: 0.35rem;
}

/* Instructions */
.instructions-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.instruction-item {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.75rem 1rem;
  background: var(--surface-2, #f7f9fd);
  border-radius: var(--radius-sm, 8px);
  border: 1px solid var(--border, #dfe5f0);
}

.instruction-index {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #fef3c7;
  color: #b45309;
  font-weight: 700;
  font-size: 0.8rem;
  flex-shrink: 0;
}

.instruction-text {
  margin: 0;
  font-size: 0.95rem;
  line-height: 1.55;
  color: var(--text, #111c38);
}

/* Parties */
.part-card {
  padding: 0;
  overflow: hidden;
}

.part-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  flex-wrap: wrap;
  padding: 1.25rem 1.5rem;
  background: var(--surface-2, #f7f9fd);
  border-bottom: 1px solid var(--border, #dfe5f0);
}

.part-title-wrapper {
  display: flex;
  align-items: baseline;
  gap: 0.85rem;
  flex-wrap: wrap;
}

.part-badge {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border-radius: 6px;
  background: var(--text, #111c38);
  color: #fff;
  font-weight: 700;
  font-size: 0.8rem;
  letter-spacing: 0.02em;
}

.part-title {
  margin: 0;
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--text, #111c38);
}

.part-points-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.45rem;
  background: var(--surface, #fff);
  border: 1px solid var(--border, #dfe5f0);
  padding: 0.35rem 0.85rem;
  border-radius: 9999px;
  font-size: 0.9rem;
}

.part-points-label {
  color: var(--muted, #5d6680);
  font-weight: 500;
}

.part-points-value {
  font-weight: 700;
  color: var(--text, #111c38);
  font-variant-numeric: tabular-nums;
}

/* Nota Bene */
.grid-notes {
  margin: 1.25rem 1.5rem;
  padding: 1rem 1.25rem;
  background: #eff6ff;
  border-left: 4px solid var(--blue, #1a55e8);
  border-radius: 0 var(--radius-sm, 8px) var(--radius-sm, 8px) 0;
}

.notes-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.5rem;
}

.notes-icon {
  color: var(--blue, #1a55e8);
}

.grid-notes h3 {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: #1e40af;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.notes-list {
  margin: 0;
  padding-left: 1.25rem;
  color: #1e3a8a;
  font-size: 0.92rem;
  line-height: 1.55;
}

.notes-list li {
  margin-bottom: 0.3rem;
}

.notes-list li:last-child {
  margin-bottom: 0;
}

/* Questions */
.questions-list {
  padding: 1.25rem 1.5rem;
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.question-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #dfe5f0);
  border-radius: var(--radius-sm, 8px);
  padding: 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}

.question-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
}

.question-title-wrapper {
  display: flex;
  align-items: baseline;
  gap: 0.65rem;
}

.question-number {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: var(--surface-2, #f1f5f9);
  color: var(--text, #111c38);
  font-size: 0.78rem;
  font-weight: 700;
  padding: 0.2rem 0.5rem;
  border-radius: 4px;
}

.question-title {
  margin: 0;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--text, #111c38);
  line-height: 1.4;
}

.question-points-badge {
  display: inline-flex;
  align-items: baseline;
  gap: 0.25rem;
  padding: 0.25rem 0.65rem;
  border-radius: 9999px;
  font-size: 0.9rem;
  font-weight: 700;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.badge-neutral {
  background: var(--surface-2, #f1f5f9);
  color: var(--text, #111c38);
}

.badge-success {
  background: #dcfce7;
  color: #15803d;
}

.badge-warning {
  background: #fef3c7;
  color: #b45309;
}

.badge-danger {
  background: #fee2e2;
  color: #b91c1c;
}

.obtained-points {
  font-size: 1.05rem;
  font-weight: 800;
}

.denom-points {
  font-size: 0.85rem;
  opacity: 0.8;
}

.box-label {
  display: block;
  font-size: 0.82rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted, #5d6680);
  margin-bottom: 0.4rem;
}

.asked-questions-box {
  background: var(--surface-2, #f8fafc);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: var(--radius-sm, 8px);
  padding: 0.85rem 1rem;
}

.asked-list {
  margin: 0;
  padding-left: 1.25rem;
  color: var(--text, #111c38);
  font-size: 0.92rem;
  line-height: 1.5;
}

.asked-list li {
  margin-bottom: 0.3rem;
}

.asked-list li:last-child {
  margin-bottom: 0;
}

/* Solution / Corrigé type */
.solution-box {
  background: #fbfcfe;
  border: 1px solid #dbeafe;
  border-radius: var(--radius-sm, 8px);
  padding: 1rem 1.15rem;
}

.solution-header {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin-bottom: 0.65rem;
  color: var(--blue, #1a55e8);
}

.solution-title {
  font-size: 0.85rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.solution-content {
  font-size: 0.92rem;
  line-height: 1.65;
  color: var(--text, #111c38);
}

.solution-content :deep(.syllogism-tag) {
  display: inline-block;
  padding: 0.1rem 0.45rem;
  border-radius: 4px;
  font-size: 0.82rem;
  letter-spacing: 0.02em;
  margin-right: 0.4rem;
}

.solution-content :deep(.tag-majeure) {
  background: #eff6ff;
  color: #1e40af;
}

.solution-content :deep(.tag-mineure) {
  background: #fef3c7;
  color: #92400e;
}

.solution-content :deep(.tag-conclusion) {
  background: #dcfce7;
  color: #166534;
}

.solution-content :deep(.tag-probleme) {
  background: #fae8ff;
  color: #86198f;
}

.student-solution-toggle {
  margin-top: 0.5rem;
  border: 1px dashed var(--border, #dfe5f0);
  border-radius: var(--radius-sm, 8px);
  padding: 0.6rem 1rem;
}

.toggle-summary {
  font-size: 0.88rem;
  font-weight: 600;
  color: var(--blue, #1a55e8);
  cursor: pointer;
}

.solution-drawer {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--border, #dfe5f0);
}

/* Références législatives */
.references-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 1rem;
}

.reference-card {
  background: var(--surface-2, #f8fafc);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: var(--radius-sm, 8px);
  padding: 1rem 1.15rem;
  display: flex;
  flex-direction: column;
  gap: 0.65rem;
}

.reference-header {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.reference-tag {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  background: #dcfce7;
  color: #166534;
  border-radius: 4px;
  font-size: 0.75rem;
  font-weight: 700;
  text-transform: uppercase;
}

.reference-title {
  margin: 0;
  font-size: 0.95rem;
  font-weight: 700;
  color: var(--text, #111c38);
}

.reference-body {
  border-left: 3px solid #bbf7d0;
  padding-left: 0.75rem;
}

.reference-text {
  margin: 0;
  font-size: 0.88rem;
  line-height: 1.55;
  color: #334155;
  font-style: italic;
}

/* Remarques sur la copie */
.remarks-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.remark-item {
  display: flex;
  align-items: flex-start;
  gap: 0.85rem;
  padding: 0.85rem 1rem;
  background: var(--surface-2, #f8fafc);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: var(--radius-sm, 8px);
}

.remark-page-badge {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.25rem 0.6rem;
  border-radius: 9999px;
  background: #fae8ff;
  color: #86198f;
  font-weight: 700;
  font-size: 0.8rem;
  white-space: nowrap;
  flex-shrink: 0;
}

.remark-text {
  margin: 0;
  font-size: 0.92rem;
  line-height: 1.55;
  color: var(--text, #111c38);
}

/* États de chargement et erreurs */
.loading-state,
.error-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 50vh;
  text-align: center;
}

.spinner {
  width: 36px;
  height: 36px;
  border: 3px solid var(--border, #dfe5f0);
  border-top-color: var(--blue, #1a55e8);
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin-bottom: 1rem;
}

@keyframes spin {
  to {
    transform: rotate(360deg);
  }
}

.error-card {
  background: #fef2f2;
  border: 1px solid #fecaca;
  padding: 2rem;
  border-radius: var(--radius-md, 10px);
  max-width: 500px;
}

.error-card h1 {
  color: #b91c1c;
  margin-top: 0;
}

.error-message {
  color: var(--danger, #c93b45);
  background: #fee2e2;
  padding: 1rem;
  border-radius: var(--radius-sm, 8px);
  border: 1px solid #fecaca;
  margin-top: 1rem;
}

/* Impression */
@media print {
  .no-print {
    display: none !important;
  }

  .correction-grid-app {
    max-width: 100% !important;
    padding: 0 !important;
    margin: 0 !important;
  }

  .content-card,
  .question-card,
  .summary-card {
    box-shadow: none !important;
    border: 1px solid #cbd5e1 !important;
    break-inside: avoid;
    page-break-inside: avoid;
  }

  .part-card {
    break-inside: auto;
    page-break-inside: auto;
  }

  .question-card {
    break-inside: avoid;
    page-break-inside: avoid;
    margin-bottom: 1rem;
  }

  body {
    background: #fff !important;
  }
}

/* Responsive */
@media (max-width: 640px) {
  .correction-grid-app {
    padding: 1rem 0.5rem 2rem;
  }

  .document-title {
    font-size: 1.35rem;
  }

  .hero-student {
    flex-direction: column;
    align-items: flex-start;
  }

  .part-header,
  .question-header {
    flex-direction: column;
    align-items: flex-start;
  }

  .questions-list {
    padding: 1rem;
  }
}
</style>
