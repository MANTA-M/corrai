<template>
  <div v-if="criteria && criteria.length" class="question-criteria-wrapper">
    <table class="table question-criteria-table">
      <thead>
        <tr>
          <th class="col-criterion">Critère d'évaluation</th>
          <th class="col-numeric col-ponderation">Pondération</th>
          <th class="col-numeric col-note">Note</th>
          <th class="col-numeric col-confidence">Confiance</th>
          <th class="col-comment">Commentaire du correcteur</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(criterion, index) in criteria" :key="index" class="criterion-row">
          <td class="col-criterion">
            <span class="criterion-title">
              {{ criterion.description || criterion.critère || `Critère ${index + 1}` }}
            </span>
          </td>
          <td class="col-numeric col-ponderation">
            <span v-if="criterion.ponderation != null" class="badge-ponderation">
              {{ formatPonderation(criterion.ponderation) }}
            </span>
            <span v-else class="text-muted">—</span>
          </td>
          <td class="col-numeric col-note">
            <span
              v-if="criterion.note != null"
              class="badge-note"
              :class="{
                'badge-note-zero': criterion.note === 0,
                'badge-note-positive': criterion.note > 0,
              }"
            >
              {{ formatNumber(criterion.note) }}
            </span>
            <span
              v-else-if="criterion.modificateur != null"
              class="badge-note"
              :class="{
                'badge-note-zero': criterion.modificateur === 0,
                'badge-note-positive': criterion.modificateur > 0,
                'badge-note-negative': criterion.modificateur < 0,
              }"
            >
              {{ formatModifier(criterion.modificateur) }}
            </span>
            <span v-else class="text-muted">—</span>
          </td>
          <td class="col-numeric col-confidence">
            <span
              v-if="criterion.score_de_confiance != null"
              class="confidence-pill"
              :class="confidenceClass(criterion.score_de_confiance)"
            >
              <span class="confidence-dot"></span>
              {{ formatPercent(criterion.score_de_confiance) }}
            </span>
            <span v-else class="text-muted">—</span>
          </td>
          <td class="col-comment">
            <p v-if="criterion.commentaire" class="criterion-comment">
              {{ criterion.commentaire }}
            </p>
            <span v-else class="text-muted">—</span>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
export interface QuestionCriterion {
  description?: string
  ponderation?: number
  note?: number
  score_de_confiance?: number
  commentaire?: string
  // Rétrocompatibilité
  critère?: string
  modificateur?: number
  retenu?: boolean
}

defineProps<{
  criteria: QuestionCriterion[]
}>()

const formatPonderation = (value: number | undefined) => {
  if (value == null || Number.isNaN(value)) return '—'
  const percent = Math.round(value * 100)
  return `${percent} %`
}

const formatNumber = (value: number | undefined) => {
  if (value == null || Number.isNaN(value)) return '—'
  return new Intl.NumberFormat('fr-FR', {
    maximumFractionDigits: 2,
    minimumFractionDigits: 0,
  }).format(value)
}

const formatModifier = (value: number | undefined) => {
  if (value == null || Number.isNaN(value)) return '—'
  if (value > 0) return `+${value}`
  return String(value)
}

const formatPercent = (value: number | undefined) => {
  if (value == null || Number.isNaN(value)) return '—'
  return `${Math.round(value * 100)} %`
}

const confidenceClass = (score: number) => {
  if (score >= 0.8) return 'confidence-high'
  if (score >= 0.6) return 'confidence-medium'
  return 'confidence-low'
}
</script>

<style scoped>
.question-criteria-wrapper {
  overflow-x: auto;
  border-radius: var(--radius-sm, 8px);
  border: 1px solid var(--border, #e2e8f0);
  background: var(--surface, #fff);
  margin-top: 0.75rem;
}

.question-criteria-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
  line-height: 1.5;
}

.question-criteria-table th {
  background: var(--surface-2, #f7f9fd);
  color: var(--text, #111c38);
  font-weight: 600;
  padding: 0.7rem 1rem;
  border-bottom: 1px solid var(--border, #e2e8f0);
  text-align: left;
  white-space: nowrap;
}

.question-criteria-table td {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border, #e2e8f0);
  vertical-align: top;
  color: var(--text, #111c38);
}

.question-criteria-table tr:last-child td {
  border-bottom: none;
}

.col-numeric {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.col-criterion {
  min-width: 180px;
}

.col-ponderation {
  width: 110px;
}

.col-note {
  width: 90px;
}

.col-confidence {
  width: 110px;
}

.col-comment {
  min-width: 260px;
}

.criterion-title {
  font-weight: 600;
  color: var(--text, #111c38);
}

.badge-ponderation {
  display: inline-block;
  padding: 0.15rem 0.5rem;
  background: var(--surface-2, #f1f5f9);
  border-radius: 9999px;
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--muted, #5d6680);
}

.badge-note {
  display: inline-block;
  padding: 0.2rem 0.6rem;
  border-radius: 6px;
  font-weight: 700;
  font-size: 0.95rem;
}

.badge-note-positive {
  background: #dcfce7;
  color: #15803d;
}

.badge-note-zero {
  background: #f1f5f9;
  color: var(--muted, #5d6680);
}

.badge-note-negative {
  background: #fee2e2;
  color: #b91c1c;
}

.confidence-pill {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.2rem 0.55rem;
  border-radius: 9999px;
  font-size: 0.82rem;
  font-weight: 600;
}

.confidence-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  flex-shrink: 0;
}

.confidence-high {
  background: #ecfdf5;
  color: #065f46;
}

.confidence-high .confidence-dot {
  background: #10b981;
}

.confidence-medium {
  background: #eff6ff;
  color: #1e40af;
}

.confidence-medium .confidence-dot {
  background: #3b82f6;
}

.confidence-low {
  background: #fffbeb;
  color: #92400e;
}

.confidence-low .confidence-dot {
  background: #f59e0b;
}

.criterion-comment {
  margin: 0;
  font-size: 0.9rem;
  color: var(--text, #111c38);
  line-height: 1.55;
}

.text-muted {
  color: var(--muted, #5d6680);
}
</style>
