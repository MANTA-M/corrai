<template>
  <router-link
    :to="assessment.id ? `/assessment/${assessment.id}` : '#'"
    class="assessment-item"
    data-testid="assessment-item"
  >
    <div class="assessment-name">{{ assessment.name || '—' }}</div>
    <div class="assessment-subject">{{ subjectLabel(assessment.subject) }}</div>
    <div class="assessment-date">{{ assessment.date || '' }}</div>
  </router-link>
</template>

<script setup lang="ts">
import { onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { isAssessmentSubject, type Assessment } from '@/types/types'

defineProps<{
  assessment: Assessment
}>()

const { t } = useI18n()
const { subjects, load } = useSubjectCatalog()

const subjectLabel = (subject: string) => {
  const node = subjects.value.find((item) => item.subject === subject)
  if (node?.name) return node.name
  if (isAssessmentSubject(subject)) return t(`assessment.subjects.${subject}`)
  return subject || '—'
}

onMounted(() => {
  load()
})
</script>

<style scoped>
.assessment-item {
  display: grid;
  grid-template-columns: 1fr 1fr 120px;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--border-color);
  border-radius: 15px;
  background: var(--white);
  margin-bottom: 0.75rem;
  align-items: center;
  cursor: pointer;
  transition:
    background-color 0.2s,
    border-color 0.2s;
  text-decoration: none;
  color: inherit;
}

.assessment-item:hover {
  background-color: var(--white);
  border-color: var(--hover-border);
  box-shadow: var(--shadow-2);
  transform: translateY(-2px);
}

.assessment-name {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--navy);
}

.assessment-subject,
.assessment-date {
  color: var(--text-muted);
  font-size: 0.95rem;
}

@media (max-width: 600px) {
  .assessment-item {
    grid-template-columns: 1fr;
    gap: 0.25rem;
  }
}
</style>
