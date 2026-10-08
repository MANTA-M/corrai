<template>
  <div class="app">
    <div class="card">
      <div class="header">
        <div>
          <h1 data-testid="assessments-heading">{{ $t('nav.assessments') }}</h1>
          <p class="muted">{{ $t('assessmentList.subtitle') }}</p>
        </div>
        <router-link
          to="/create_assessment"
          class="create-button"
          data-testid="assessment-create-button"
        >
          {{ $t('assessment.createNew') }}
        </router-link>
      </div>
      <div class="content">
        <div v-if="isLoading" class="loading">
          <p>{{ $t('assessment.loading') }}</p>
        </div>
        <div v-else class="assessments-list">
          <AssessmentListItem
            v-for="assessment in assessments"
            :key="assessment.id || assessment.name"
            :assessment="assessment"
          />
          <p v-if="assessments.length === 0" class="empty-message" data-testid="assessments-empty">
            {{ $t('assessmentList.empty') }}
          </p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useSessionStore } from '@/stores/session'
import AssessmentListItem from '@/components/AssessmentListItem.vue'

const sessionStore = useSessionStore()
const isLoading = ref(false)

const assessments = computed(() => {
  return [...sessionStore.own_assessments].sort((a, b) => {
    const dateA = a.date || ''
    const dateB = b.date || ''
    return dateA.localeCompare(dateB)
  })
})

onMounted(async () => {
  isLoading.value = true
  try {
    await sessionStore.load_assessments()
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
  padding: 12px 18px;
  background-color: var(--accent);
  color: white;
  border: none;
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: var(--type-label);
  text-decoration: none;
  display: inline-flex;
  align-items: center;
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

.assessments-list {
  margin-top: 0;
}

.empty-message {
  color: var(--text-muted);
  margin: 0.5rem 0;
}

.loading {
  color: var(--text-muted);
}
</style>
