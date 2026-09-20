<template>
  <div class="app">
    <div class="card">
      <div class="header">
        <div>
          <h1 data-testid="exams-heading">{{ $t('nav.exams') }}</h1>
          <p class="muted">{{ $t('examList.subtitle') }}</p>
        </div>
        <router-link to="/create_exam" class="create-button" data-testid="exam-create-button">
          {{ $t('exam.createNew') }}
        </router-link>
      </div>
      <div class="content">
        <div v-if="isLoading" class="loading">
          <p>{{ $t('exam.loading') }}</p>
        </div>
        <div v-else class="exams-list">
          <ExamListItem
            v-for="exam in exams"
            :key="exam.id || exam.name"
            :exam="exam"
          />
          <p v-if="exams.length === 0" class="empty-message" data-testid="exams-empty">
            {{ $t('examList.empty') }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useSessionStore } from '@/stores/session'
import ExamListItem from '@/components/ExamListItem.vue'

const sessionStore = useSessionStore()
const isLoading = ref(false)

const exams = computed(() => {
  return [...sessionStore.own_exams].sort((a, b) => {
    const dateA = a.date || ''
    const dateB = b.date || ''
    return dateA.localeCompare(dateB)
  })
})

onMounted(async () => {
  isLoading.value = true
  try {
    await sessionStore.load_exams()
  } finally {
    isLoading.value = false
  }
})
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1.5rem;
}

.create-button {
  padding: 0.5rem 1rem;
  background-color: var(--accent);
  color: white;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  font-size: 1rem;
  font-weight: 500;
  text-decoration: none;
  display: inline-block;
}

.create-button:hover {
  background-color: var(--accent-600);
}

.header h1 {
  margin: 0 0 0.5rem 0;
}

.muted {
  color: var(--text-muted);
  margin: 0;
}

.content {
  padding: 1rem 0;
}

.exams-list {
  margin-top: 0;
}

.empty-message {
  color: var(--text-muted);
  font-style: italic;
  margin: 0.5rem 0;
}

.loading {
  color: var(--text-muted);
}
</style>
