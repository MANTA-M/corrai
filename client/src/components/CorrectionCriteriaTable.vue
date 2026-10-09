<template>
  <div v-if="criteria.length" class="criteria-table-wrapper">
    <table class="table criteria-table">
      <thead>
        <tr>
          <th class="col-criterion">Critère / Règle</th>
          <th class="col-numeric col-impact">Impact</th>
          <th v-if="showApplication" class="col-status">Statut</th>
          <th v-if="showApplication" class="col-numeric col-confidence">Confiance</th>
          <th v-if="showApplication" class="col-comment">Justification du correcteur</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(criterion, index) in criteria"
          :key="index"
          :class="{
            'is-applied': showApplication && criterion.retenu === true,
            'is-not-applied': showApplication && criterion.retenu === false,
          }"
        >
          <td class="col-criterion">
            <span class="criterion-name">{{ criterion.critère || criterion.description }}</span>
          </td>
          <td class="col-numeric col-impact">
            <span
              class="badge-modifier"
              :class="{
                'badge-negative': (criterion.modificateur ?? 0) < 0,
                'badge-positive': (criterion.modificateur ?? 0) > 0,
                'badge-neutral': (criterion.modificateur ?? 0) === 0,
              }"
            >
              {{ formatModifier(criterion.modificateur) }}
            </span>
          </td>
          <td v-if="showApplication" class="col-status">
            <span
              v-if="criterion.retenu === true"
              class="status-pill status-retained"
            >
              Appliqué
            </span>
            <span
              v-else-if="criterion.retenu === false"
              class="status-pill status-dismissed"
            >
              Non retenu
            </span>
            <span v-else class="status-pill status-unknown">—</span>
          </td>
          <td v-if="showApplication" class="col-numeric col-confidence">
            <span v-if="criterion.score_de_confiance != null" class="confidence-tag">
              {{ formatConfidence(criterion.score_de_confiance) }}
            </span>
            <span v-else class="text-muted">—</span>
          </td>
          <td v-if="showApplication" class="col-comment">
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
export interface CorrectionCriterion {
  critère?: string
  description?: string
  modificateur?: number
  retenu?: boolean
  score_de_confiance?: number
  commentaire?: string
}

defineProps<{
  criteria: CorrectionCriterion[]
  showApplication: boolean
}>()

const formatModifier = (value: number | undefined) => {
  if (value == null || Number.isNaN(value)) return '—'
  if (value > 0) return `+${value} pt${Math.abs(value) > 1 ? 's' : ''}`
  if (value < 0) return `${value} pt${Math.abs(value) > 1 ? 's' : ''}`
  return '0 pt'
}

const formatConfidence = (value: number | undefined) => {
  if (value == null || Number.isNaN(value)) return ''
  const percent = Math.round(value * 100)
  return `${percent} %`
}
</script>

<style scoped>
.criteria-table-wrapper {
  overflow-x: auto;
  border-radius: var(--radius-sm, 8px);
  border: 1px solid var(--border, #e2e8f0);
  background: var(--surface, #fff);
}

.criteria-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.92rem;
  line-height: 1.5;
}

.criteria-table th {
  background: var(--surface-2, #f7f9fd);
  color: var(--text, #111c38);
  font-weight: 600;
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border, #e2e8f0);
  text-align: left;
  white-space: nowrap;
}

.criteria-table td {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border, #e2e8f0);
  vertical-align: top;
  color: var(--text, #111c38);
}

.criteria-table tr:last-child td {
  border-bottom: none;
}

.col-numeric {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.col-criterion {
  min-width: 200px;
}

.col-impact {
  width: 100px;
}

.col-status {
  width: 120px;
  white-space: nowrap;
}

.col-confidence {
  width: 100px;
}

.col-comment {
  min-width: 250px;
}

.criterion-name {
  font-weight: 500;
}

.badge-modifier {
  display: inline-block;
  padding: 0.2rem 0.55rem;
  border-radius: 9999px;
  font-weight: 600;
  font-size: 0.85rem;
  letter-spacing: -0.01em;
}

.badge-negative {
  background: #fee2e2;
  color: #b91c1c;
}

.badge-positive {
  background: #dcfce7;
  color: #15803d;
}

.badge-neutral {
  background: var(--surface-2, #f1f5f9);
  color: var(--muted, #5d6680);
}

.status-pill {
  display: inline-flex;
  align-items: center;
  padding: 0.2rem 0.6rem;
  border-radius: 9999px;
  font-size: 0.8rem;
  font-weight: 600;
}

.status-retained {
  background: #fee2e2;
  color: #b91c1c;
}

.status-dismissed {
  background: #f1f5f9;
  color: var(--muted, #5d6680);
}

.status-unknown {
  color: var(--muted, #5d6680);
}

.confidence-tag {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  background: var(--surface-2, #f1f5f9);
  border-radius: 4px;
  font-size: 0.82rem;
  font-weight: 600;
  color: var(--text, #111c38);
}

.criterion-comment {
  margin: 0;
  font-size: 0.88rem;
  color: var(--text, #111c38);
}

.text-muted {
  color: var(--muted, #5d6680);
}

.is-applied {
  background: rgba(254, 226, 226, 0.25);
}

.is-not-applied {
  opacity: 0.9;
}
</style>
