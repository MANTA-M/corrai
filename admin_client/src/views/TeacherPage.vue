<template>
  <div class="app">
    <div class="card">
      <router-link class="back-link" :to="{ name: 'school', params: { id: schoolId } }">← School</router-link>
      <div v-if="isLoading" class="loading">
        <p>Loading teacher…</p>
      </div>
      <div v-else-if="error" class="error">
        <p>{{ error }}</p>
      </div>
      <template v-else-if="teacher">
        <div class="header">
          <div>
            <h1>{{ teacher.name || 'Teacher' }}</h1>
            <p class="muted">{{ teacher.id }}</p>
          </div>
          <a
            v-if="teacher.id"
            class="button primary"
            :href="openInCorraiUrl"
            target="_blank"
            rel="noopener noreferrer"
          >
            Open in Corrai
          </a>
        </div>
        <form class="rename-form" @submit.prevent="renameTeacher">
          <label class="field">
            <span>Teacher name</span>
            <input v-model="name" class="input" type="text" name="teacher-name" required />
          </label>
          <button class="button primary" type="submit" :disabled="isSaving || !name.trim()">
            {{ isSaving ? 'Saving…' : 'Save name' }}
          </button>
        </form>
        <p v-if="saveError" class="form-error">{{ saveError }}</p>
        <p v-else-if="saveMessage" class="form-success">{{ saveMessage }}</p>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { apiJson, type Teacher } from '@/api'

interface TeacherResponse {
  teacher: Teacher
}

const route = useRoute()
const teacher = ref<Teacher | null>(null)
const name = ref('')
const isLoading = ref(false)
const error = ref('')
const isSaving = ref(false)
const saveError = ref('')
const saveMessage = ref('')

const schoolId = computed(() => {
  const value = route.params.schoolId
  return typeof value === 'string' ? value : ''
})

const openInCorraiUrl = computed(() => {
  if (!teacher.value?.id) return '#'
  const params = new URLSearchParams({ as: teacher.value.id })
  const teacherName = teacher.value.name?.trim()
  if (teacherName) {
    params.set('name', teacherName)
  }
  return `/corrai_test/assessment-list?${params.toString()}`
})

const loadTeacher = async (id: string) => {
  isLoading.value = true
  error.value = ''
  saveError.value = ''
  saveMessage.value = ''
  try {
    const data = await apiJson<TeacherResponse>(`teacher?hash=${encodeURIComponent(id)}`)
    if (schoolId.value && data.teacher.school_id !== schoolId.value) {
      throw new Error('Teacher does not belong to this school')
    }
    teacher.value = data.teacher
    name.value = data.teacher.name
  } catch (err) {
    teacher.value = null
    error.value = err instanceof Error ? err.message : 'Failed to load teacher'
  } finally {
    isLoading.value = false
  }
}

const renameTeacher = async () => {
  if (!teacher.value?.id) return
  const nextName = name.value.trim()
  if (!nextName) return
  isSaving.value = true
  saveError.value = ''
  saveMessage.value = ''
  try {
    const data = await apiJson<TeacherResponse>(`teacher?hash=${encodeURIComponent(teacher.value.id)}`, {
      method: 'PUT',
      body: JSON.stringify({ name: nextName }),
    })
    teacher.value = data.teacher
    name.value = data.teacher.name
    saveMessage.value = 'Teacher name saved.'
  } catch (err) {
    saveError.value = err instanceof Error ? err.message : 'Failed to save teacher name'
  } finally {
    isSaving.value = false
  }
}

onMounted(() => {
  const id = route.params.id
  if (typeof id === 'string' && id) {
    void loadTeacher(id)
  }
})

watch(
  () => route.params.id,
  (id) => {
    if (typeof id === 'string' && id) {
      void loadTeacher(id)
    }
  },
)
</script>

<style scoped>
.back-link {
  display: inline-block;
  margin-bottom: 1.25rem;
  font-weight: 600;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.header h1 {
  margin: 0 0 0.35rem 0;
}

.muted {
  color: var(--text-muted);
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.85rem;
}

.rename-form {
  display: flex;
  gap: 0.75rem;
  align-items: flex-end;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  flex: 1;
  min-width: 0;
  font-weight: 600;
  color: var(--navy);
}

.form-error,
.form-success {
  margin: 0.75rem 0 0;
}

.form-error,
.error {
  color: #c93b45;
}

.form-success {
  color: var(--green);
}

.loading,
.error {
  color: var(--text-muted);
  text-align: center;
  padding: 2rem 1rem;
}

.error {
  color: #c93b45;
}

@media (max-width: 600px) {
  .rename-form {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
