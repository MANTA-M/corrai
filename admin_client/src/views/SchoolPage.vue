<template>
  <div class="app">
    <div class="card">
      <router-link class="back-link" :to="{ name: 'schools' }">← Schools</router-link>
      <div v-if="isLoading" class="loading">
        <p>Loading school…</p>
      </div>
      <div v-else-if="error" class="error">
        <p>{{ error }}</p>
      </div>
      <template v-else-if="school">
        <div class="header">
          <div class="title-row">
            <h1 v-if="!isEditing">{{ school.name || 'School' }}</h1>
            <form v-else class="rename-form" @submit.prevent="renameSchool">
              <input
                ref="nameInput"
                v-model="name"
                class="input title-input"
                type="text"
                name="school-name"
                aria-label="School name"
                required
              />
              <button class="button primary" type="submit" :disabled="isSaving || !name.trim()">
                {{ isSaving ? 'Saving…' : 'Save' }}
              </button>
              <button class="button" type="button" :disabled="isSaving" @click="cancelEdit">
                Cancel
              </button>
            </form>
            <button
              v-if="!isEditing"
              class="pen-button"
              type="button"
              aria-label="Rename school"
              @click="startEdit"
            >
              <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                <path
                  fill="currentColor"
                  d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75 1.83-1.83z"
                />
              </svg>
            </button>
          </div>
          <p class="muted">{{ school.id }}</p>
        </div>
        <p v-if="saveError" class="form-error">{{ saveError }}</p>
        <p v-else-if="saveMessage" class="form-success">{{ saveMessage }}</p>

        <section class="teachers">
          <h2>Teachers</h2>
          <form class="create-form" @submit.prevent="createTeacher">
            <input
              v-model="newTeacherName"
              class="input"
              type="text"
              name="teacher-name"
              placeholder="New teacher name"
              autocomplete="off"
              required
            />
            <button
              class="button primary"
              type="submit"
              :disabled="isCreating || !newTeacherName.trim()"
            >
              {{ isCreating ? 'Creating…' : 'Create teacher' }}
            </button>
          </form>
          <p v-if="createError" class="form-error">{{ createError }}</p>
          <div class="teachers-list">
            <div v-for="teacher in teachers" :key="teacher.id || teacher.name" class="teacher-item">
              <router-link
                class="teacher-link"
                :to="
                  teacher.id
                    ? { name: 'teacher', params: { schoolId: school.id, id: teacher.id } }
                    : { name: 'school', params: { id: school.id } }
                "
              >
                <div class="teacher-name">{{ teacher.name || '—' }}</div>
                <div class="teacher-meta">{{ teacher.role }}</div>
                <div class="teacher-date">{{ formatDate(teacher.created_at) }}</div>
              </router-link>
              <button
                v-if="teacher.id"
                class="trash-button"
                type="button"
                aria-label="Delete teacher"
                title="Delete teacher"
                :disabled="deletingTeacherId === teacher.id"
                @click="deleteTeacher(teacher)"
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
            <p v-if="teachers.length === 0" class="empty-message">No teachers yet.</p>
          </div>
          <p v-if="deleteTeacherError" class="form-error">{{ deleteTeacherError }}</p>
        </section>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiJson, formatDate, type School, type Teacher } from '@/api'

interface SchoolDetailResponse {
  school: School
  teachers?: Teacher[]
}

interface TeacherResponse {
  teacher: Teacher
}

const route = useRoute()
const router = useRouter()
const school = ref<School | null>(null)
const teachers = ref<Teacher[]>([])
const name = ref('')
const nameInput = ref<HTMLInputElement | null>(null)
const isEditing = ref(false)
const isLoading = ref(false)
const error = ref('')
const isSaving = ref(false)
const saveError = ref('')
const saveMessage = ref('')
const newTeacherName = ref('')
const isCreating = ref(false)
const createError = ref('')
const deletingTeacherId = ref<string | null>(null)
const deleteTeacherError = ref('')

const sortTeachers = (items: Teacher[]) => {
  return [...items].sort((a, b) => {
    return (a.name || '').localeCompare(b.name || '', undefined, { sensitivity: 'base' })
  })
}

const loadSchool = async (id: string) => {
  isLoading.value = true
  isEditing.value = false
  error.value = ''
  saveError.value = ''
  saveMessage.value = ''
  try {
    const data = await apiJson<SchoolDetailResponse>(`school?hash=${encodeURIComponent(id)}`)
    school.value = data.school
    name.value = data.school.name
    teachers.value = sortTeachers(data.teachers ?? [])
  } catch (err) {
    school.value = null
    error.value = err instanceof Error ? err.message : 'Failed to load school'
  } finally {
    isLoading.value = false
  }
}

const startEdit = async () => {
  name.value = school.value?.name ?? ''
  isEditing.value = true
  saveError.value = ''
  saveMessage.value = ''
  await nextTick()
  nameInput.value?.focus()
  nameInput.value?.select()
}

const cancelEdit = () => {
  isEditing.value = false
  name.value = school.value?.name ?? ''
  saveError.value = ''
}

const renameSchool = async () => {
  if (!school.value?.id) return
  const nextName = name.value.trim()
  if (!nextName) return
  isSaving.value = true
  saveError.value = ''
  saveMessage.value = ''
  try {
    const data = await apiJson<SchoolDetailResponse>(
      `school?hash=${encodeURIComponent(school.value.id)}`,
      {
        method: 'PUT',
        body: JSON.stringify({ name: nextName }),
      },
    )
    school.value = data.school
    name.value = data.school.name
    isEditing.value = false
    saveMessage.value = 'School name saved.'
  } catch (err) {
    saveError.value = err instanceof Error ? err.message : 'Failed to save school name'
  } finally {
    isSaving.value = false
  }
}

const createTeacher = async () => {
  if (!school.value?.id) return
  const teacherName = newTeacherName.value.trim()
  if (!teacherName) return
  isCreating.value = true
  createError.value = ''
  try {
    const data = await apiJson<TeacherResponse>('teacher', {
      method: 'POST',
      body: JSON.stringify({ school_id: school.value.id, name: teacherName }),
    })
    newTeacherName.value = ''
    if (data.teacher.id) {
      await router.push({
        name: 'teacher',
        params: { schoolId: school.value.id, id: data.teacher.id },
      })
    }
  } catch (err) {
    createError.value = err instanceof Error ? err.message : 'Failed to create teacher'
  } finally {
    isCreating.value = false
  }
}

const deleteTeacher = async (teacher: Teacher) => {
  if (!teacher.id) return
  const teacherName = teacher.name ? `"${teacher.name}"` : 'this teacher'
  if (!window.confirm(`Are you sure you want to delete ${teacherName}?`)) {
    return
  }
  deletingTeacherId.value = teacher.id
  deleteTeacherError.value = ''
  try {
    await apiJson(`teacher?hash=${encodeURIComponent(teacher.id)}`, {
      method: 'DELETE',
    })
    teachers.value = teachers.value.filter((t) => t.id !== teacher.id)
  } catch (err) {
    deleteTeacherError.value = err instanceof Error ? err.message : 'Failed to delete teacher'
  } finally {
    deletingTeacherId.value = null
  }
}

onMounted(() => {
  const id = route.params.id
  if (typeof id === 'string' && id) {
    void loadSchool(id)
  }
})

watch(
  () => route.params.id,
  (id) => {
    if (typeof id === 'string' && id) {
      void loadSchool(id)
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

.pen-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 36px;
  height: 36px;
  padding: 0;
  border: 1px solid var(--line);
  border-radius: var(--radius-md);
  background: var(--white);
  color: var(--navy);
  cursor: pointer;
  flex-shrink: 0;
}

.pen-button:hover {
  background: var(--pale);
  border-color: var(--hover-border);
  color: var(--blue);
}

.rename-form {
  display: flex;
  gap: 0.5rem;
  align-items: center;
  flex: 1;
  min-width: 0;
}

.title-input {
  flex: 1;
  min-width: 0;
  font-family: var(--font-display);
  font-weight: 800;
  font-size: 1.35rem;
  color: var(--navy);
}

.muted {
  color: var(--text-muted);
  margin: 0;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.85rem;
}

.create-form {
  display: flex;
  gap: 0.75rem;
  align-items: flex-end;
}

.create-form .input {
  flex: 1;
  min-width: 0;
}

.form-error,
.form-success {
  margin: 0.75rem 0 0;
}

.form-error {
  color: #c93b45;
}

.form-success {
  color: var(--green);
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

.teachers {
  margin-top: 2.5rem;
}

.teachers h2 {
  margin: 0 0 1rem 0;
  font-size: 1.35rem;
}

.teachers-list {
  margin-top: 1rem;
}

.teacher-item {
  display: flex;
  align-items: center;
  border: 1px solid var(--border-color);
  border-radius: 15px;
  background: var(--white);
  margin-bottom: 0.75rem;
  padding: 0.5rem 0.75rem 0.5rem 0.5rem;
  transition:
    background-color 0.2s,
    border-color 0.2s,
    box-shadow 0.2s,
    transform 0.2s;
}

.teacher-item:hover {
  border-color: var(--hover-border);
  box-shadow: var(--shadow-2);
  transform: translateY(-2px);
}

.teacher-link {
  display: grid;
  grid-template-columns: 1fr 160px 140px;
  gap: 1rem;
  padding: 0.5rem 0.5rem 0.5rem 0.75rem;
  flex: 1;
  min-width: 0;
  align-items: center;
  color: inherit;
  text-decoration: none;
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

.teacher-name {
  font-family: var(--font-display);
  font-weight: 700;
  color: var(--navy);
}

.teacher-meta,
.teacher-date {
  color: var(--text-muted);
  font-size: 0.95rem;
}

@media (max-width: 600px) {
  .rename-form,
  .create-form {
    flex-direction: column;
    align-items: stretch;
  }

  .teacher-link {
    grid-template-columns: 1fr;
    gap: 0.25rem;
  }
}
</style>
