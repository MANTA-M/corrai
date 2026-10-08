<template>
  <table v-if="criteria.length" class="table criteria-table">
    <thead>
      <tr>
        <th>Critère</th>
        <th class="numeric">Modificateur</th>
        <th v-if="showApplication">Retenu</th>
        <th v-if="showApplication" class="numeric">Confiance</th>
        <th v-if="showApplication">Commentaire</th>
      </tr>
    </thead>
    <tbody>
      <tr
        v-for="(criterion, index) in criteria"
        :key="index"
        :class="{ 'not-retained': criterion.retenu === false }"
      >
        <td>{{ criterion.critère }}</td>
        <td class="numeric">{{ formatModifier(criterion.modificateur) }}</td>
        <td v-if="showApplication">{{ criterion.retenu ? 'Oui' : 'Non' }}</td>
        <td v-if="showApplication" class="numeric">
          {{ formatConfidence(criterion.score_de_confiance) }}
        </td>
        <td v-if="showApplication">{{ criterion.commentaire }}</td>
      </tr>
    </tbody>
  </table>
</template>

<script setup lang="ts">
export interface CorrectionCriterion {
  critère?: string
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
  if (value == null || Number.isNaN(value)) return ''
  if (value > 0) return `+${value}`
  return String(value)
}

const formatConfidence = (value: number | undefined) => {
  if (value == null || Number.isNaN(value)) return ''
  return new Intl.NumberFormat(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }).format(value)
}
</script>

<style scoped>
.criteria-table {
  margin-top: 0.25rem;
}

.numeric {
  text-align: right;
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}

.not-retained {
  color: var(--text-muted);
}
</style>
