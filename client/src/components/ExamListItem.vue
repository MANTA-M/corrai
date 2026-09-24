<template>
  <router-link
    :to="exam.id ? `/exam/${exam.id}` : '#'"
    class="exam-item"
    data-testid="exam-item"
  >
    <div class="exam-name">{{ exam.name || '—' }}</div>
    <div class="exam-subject">{{ exam.subject || '—' }}</div>
    <div class="exam-date">{{ exam.date || '—' }}</div>
  </router-link>
</template>

<script setup lang="ts">
import type { Exam } from '@/types/types'

defineProps<{
  exam: Exam
}>()
</script>

<style scoped>
.exam-item {
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
  transition: background-color 0.2s, border-color 0.2s;
  text-decoration: none;
  color: inherit;
}

.exam-item:hover {
  background-color: var(--white);
  border-color: var(--hover-border);
  box-shadow: var(--shadow-2);
  transform: translateY(-2px);
}

.exam-name {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--navy);
}

.exam-subject,
.exam-date {
  color: var(--text-muted);
  font-size: 0.95rem;
}

@media (max-width: 600px) {
  .exam-item {
    grid-template-columns: 1fr;
    gap: 0.25rem;
  }
}
</style>
