<template>
  <div class="app">
    <div class="card">
      <router-link class="back-link" :to="{ name: 'school', params: { id: schoolId } }"
        >← School</router-link
      >
      <div v-if="isLoading" class="loading">
        <p>Loading teacher…</p>
      </div>
      <div v-else-if="error" class="error">
        <p>{{ error }}</p>
      </div>
      <template v-else-if="teacher">
        <div class="header">
          <div>
            <div class="title-row">
              <h1>{{ teacher.name || 'Teacher' }}</h1>
              <button
                class="trash-button"
                type="button"
                aria-label="Delete teacher"
                title="Delete teacher"
                :disabled="isDeleting"
                @click="deleteTeacher"
              >
                <svg
                  viewBox="0 0 24 24"
                  width="18"
                  height="18"
                  fill="none"
                  stroke="currentColor"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  aria-hidden="true"
                >
                  <path d="M5 7h14" />
                  <path d="M9 7V5h6v2" />
                  <path d="M8 7l.8 12h6.4L16 7" />
                </svg>
              </button>
            </div>
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
        <p v-if="deleteError" class="form-error">{{ deleteError }}</p>
        <form class="rename-form" @submit.prevent="saveTeacher">
          <label class="field">
            <span>Teacher name</span>
            <input v-model="name" class="input" type="text" name="teacher-name" required />
          </label>
          <label class="field discount-field">
            <span>Discount (%)</span>
            <input
              v-model.number="discountRate"
              class="input"
              type="number"
              name="discount-rate"
              min="0"
              max="100"
              step="1"
              required
            />
          </label>
          <button class="button primary" type="submit" :disabled="isSaving || !canSave">
            {{ isSaving ? 'Saving…' : 'Save' }}
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
import { useRoute, useRouter } from 'vue-router'
import { apiJson, type Teacher } from '@/api'

interface TeacherResponse {
  teacher: Teacher
}

const route = useRoute()
const router = useRouter()
const teacher = ref<Teacher | null>(null)
const name = ref('')
const discountRate = ref(0)
const isLoading = ref(false)
const error = ref('')
const isSaving = ref(false)
const saveError = ref('')
const saveMessage = ref('')
const isDeleting = ref(false)
const deleteError = ref('')

const schoolId = computed(() => {
  const value = route.params.schoolId
  return typeof value === 'string' ? value : ''
})

const discountValid = computed(
  () => Number.isInteger(discountRate.value) && discountRate.value >= 0 && discountRate.value <= 100,
)

const canSave = computed(() => name.value.trim() !== '' && discountValid.value)

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
    discountRate.value = data.teacher.discount_rate ?? 0
  } catch (err) {
    teacher.value = null
    error.value = err instanceof Error ? err.message : 'Failed to load teacher'
  } finally {
    isLoading.value = false
  }
}

const saveTeacher = async () => {
  if (!teacher.value?.id || !canSave.value) return
  const nextName = name.value.trim()
  isSaving.value = true
  saveError.value = ''
  saveMessage.value = ''
  deleteError.value = ''
  try {
    const data = await apiJson<TeacherResponse>(
      `teacher?hash=${encodeURIComponent(teacher.value.id)}`,
      {
        method: 'PUT',
        body: JSON.stringify({ name: nextName, discount_rate: discountRate.value }),
      },
    )
    teacher.value = data.teacher
    name.value = data.teacher.name
    discountRate.value = data.teacher.discount_rate ?? 0
    saveMessage.value = 'Teacher saved.'
  } catch (err) {
    saveError.value = err instanceof Error ? err.message : 'Failed to save teacher'
  } finally {
    isSaving.value = false
  }
}

const deleteTeacher = async () => {
  if (!teacher.value?.id) return
  const teacherName = teacher.value.name ? `"${teacher.value.name}"` : 'this teacher'
  if (!window.confirm(`Are you sure you want to delete ${teacherName}?`)) {
    return
  }
  isDeleting.value = true
  deleteError.value = ''
  try {
    await apiJson(`teacher?hash=${encodeURIComponent(teacher.value.id)}`, {
      method: 'DELETE',
    })
    if (schoolId.value) {
      await router.push({ name: 'school', params: { id: schoolId.value } })
    } else {
      await router.push({ name: 'schools' })
    }
  } catch (err) {
    deleteError.value = err instanceof Error ? err.message : 'Failed to delete teacher'
  } finally {
    isDeleting.value = false
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

.title-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.35rem;
}

.header h1 {
  margin: 0;
}

.trash-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  padding: 0;
  border: 1px solid var(--line);
  border-radius: var(--radius-md);
  background: var(--white);
  color: #c93b45;
  cursor: pointer;
  flex-shrink: 0;
  transition:
    background-color 0.2s,
    border-color 0.2s,
    color 0.2s;
}

.trash-button:hover:not(:disabled) {
  background: #fff5f5;
  border-color: #fca5a5;
  color: #b91c1c;
}

.trash-button:disabled {
  opacity: 0.5;
  cursor: not-allowed;
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

.discount-field {
  flex: 0 0 8rem;
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
