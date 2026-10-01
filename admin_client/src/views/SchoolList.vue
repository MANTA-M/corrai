<template>
  <div class="app">
    <div class="card">
      <div class="header">
        <div>
          <h1>Schools</h1>
          <p class="muted">All schools registered in Corrai</p>
        </div>
      </div>
      <form class="create-form" @submit.prevent="createSchool">
        <input
          v-model="newName"
          class="input"
          type="text"
          name="school-name"
          placeholder="New school name"
          autocomplete="off"
          required
        />
        <button class="button primary" type="submit" :disabled="isCreating || !newName.trim()">
          {{ isCreating ? 'Creating…' : 'Create school' }}
        </button>
      </form>
      <p v-if="createError" class="form-error">{{ createError }}</p>
      <div class="content">
        <div v-if="isLoading" class="loading">
          <p>Loading schools…</p>
        </div>
        <div v-else-if="error" class="error">
          <p>{{ error }}</p>
        </div>
        <div v-else class="schools-list">
          <router-link
            v-for="school in schools"
            :key="school.id || school.name"
            class="school-item"
            :to="school.id ? { name: 'school', params: { id: school.id } } : '/'"
          >
            <div class="school-name">{{ school.name || '—' }}</div>
            <div class="school-id">{{ school.id || '—' }}</div>
            <div class="school-date">{{ formatDate(school.created_at) }}</div>
          </router-link>
          <p v-if="schools.length === 0" class="empty-message">No schools yet.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiJson, formatDate, type School } from '@/api'

interface SchoolsResponse {
  schools?: School[]
}

interface SchoolResponse {
  school: School
}

const router = useRouter()
const schools = ref<School[]>([])
const isLoading = ref(false)
const error = ref('')
const newName = ref('')
const isCreating = ref(false)
const createError = ref('')

const sortSchools = (items: School[]) => {
  return [...items].sort((a, b) => {
    return (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })
  })
}

const loadSchools = async () => {
  isLoading.value = true
  error.value = ''
  try {
    const data = await apiJson<SchoolsResponse>('schools')
    schools.value = sortSchools(data.schools ?? [])
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Failed to load schools'
  } finally {
    isLoading.value = false
  }
}

const createSchool = async () => {
  const name = newName.value.trim()
  if (!name) return
  isCreating.value = true
  createError.value = ''
  try {
    const data = await apiJson<SchoolResponse>('school', {
      method: 'POST',
      body: JSON.stringify({ name }),
    })
    newName.value = ''
    if (data.school.id) {
      await router.push({ name: 'school', params: { id: data.school.id } })
      return
    }
    await loadSchools()
  } catch (err) {
    createError.value = err instanceof Error ? err.message : 'Failed to create school'
  } finally {
    isCreating.value = false
  }
}

onMounted(loadSchools)
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 1.5rem;
}

.header h1 {
  margin: 0 0 0.5rem 0;
}

.muted {
  color: var(--text-muted);
  margin: 0;
}

.create-form {
  display: flex;
  gap: 0.75rem;
  align-items: center;
}

.create-form .input {
  flex: 1;
  min-width: 0;
}

.form-error {
  color: #c93b45;
  margin: 0.75rem 0 0;
}

.content {
  padding: 1rem 0;
}

.loading,
.error,
.empty-message {
  color: var(--text-muted);
  text-align: center;
  padding: 2rem 1rem;
}

.error {
  color: #c93b45;
}

.schools-list {
  margin-top: 0;
}

.school-item {
  display: grid;
  grid-template-columns: 1fr 1fr 140px;
  gap: 1rem;
  padding: 1rem;
  border: 1px solid var(--border-color);
  border-radius: 15px;
  background: var(--white);
  margin-bottom: 0.75rem;
  align-items: center;
  color: inherit;
  text-decoration: none;
  transition: background-color 0.2s, border-color 0.2s, box-shadow 0.2s, transform 0.2s;
}

.school-item:hover {
  border-color: var(--hover-border);
  box-shadow: var(--shadow-2);
  transform: translateY(-2px);
  color: inherit;
}

.school-name {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--navy);
}

.school-id,
.school-date {
  color: var(--text-muted);
  font-size: 0.95rem;
}

.school-id {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.85rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 600px) {
  .create-form {
    flex-direction: column;
    align-items: stretch;
  }

  .school-item {
    grid-template-columns: 1fr;
    gap: 0.25rem;
  }
}
</style>
