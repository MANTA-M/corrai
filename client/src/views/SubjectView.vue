<template>
  <div class="app">
    <div class="card">
      <div v-if="isLoading" class="loading">
        <p>{{ t('assessment.loading') }}</p>
      </div>

      <div v-else-if="error && !assessment" class="error">
        <h1>{{ t('assessment.error') }}</h1>
        <p>{{ error }}</p>
        <button class="button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>

      <div v-else-if="assessment" class="subject-page" data-testid="subject-page">
        <div class="header">
          <div class="heading">
            <h1>{{ t('assessment.subjectPageTitle') }}</h1>
            <div class="editable-line">
              <template v-if="editing === 'name'">
                <input
                  ref="editControl"
                  v-model="draft"
                  type="text"
                  class="input inline-control"
                  data-testid="subject-name-input"
                  :placeholder="t('assessment.namePlaceholder')"
                  :disabled="isSaving"
                  @keydown.enter.prevent="saveField"
                  @keydown.esc.prevent="cancelEdit"
                />
                <button
                  type="button"
                  class="button primary compact"
                  data-testid="subject-name-save"
                  :disabled="isSaving"
                  @click="saveField"
                >
                  {{ isSaving ? t('assessment.saving') : t('assessment.save') }}
                </button>
              </template>
              <template v-else>
                <p class="title-value" data-testid="subject-name-value">{{ assessment.name || '—' }}</p>
                <MenuIconButton
                  :item="pencilItem"
                  test-id="subject-name-edit"
                  @click="startEdit('name')"
                />
              </template>
            </div>
          </div>
          <button type="button" class="button" data-testid="subject-back" @click="goBack">
            {{ t('assessment.back') }}
          </button>
        </div>

        <ul class="meta-list" data-testid="subject-meta">
          <li class="editable-line">
            <span class="meta-label">{{ t('assessment.subject') }}</span>
            <template v-if="editing === 'subject'">
              <select
                ref="editControl"
                v-model="draft"
                class="input inline-control"
                data-testid="subject-subject-input"
                :disabled="isSaving"
                @keydown.esc.prevent="cancelEdit"
              >
                <option value="" disabled>{{ t('assessment.subjectPlaceholder') }}</option>
                <option v-for="node in subjects" :key="node.subject" :value="node.subject">
                  {{ node.name }}
                </option>
              </select>
              <button
                type="button"
                class="button primary compact"
                data-testid="subject-subject-save"
                :disabled="isSaving || !draft.trim()"
                @click="saveField"
              >
                {{ isSaving ? t('assessment.saving') : t('assessment.save') }}
              </button>
            </template>
            <template v-else>
              <span class="meta-value" data-testid="subject-subject-value">{{ subjectLabel(assessment.subject) }}</span>
              <MenuIconButton
                :item="pencilItem"
                test-id="subject-subject-edit"
                @click="startEdit('subject')"
              />
            </template>
          </li>

          <li class="editable-line">
            <span class="meta-label">{{ t('assessment.level') }}</span>
            <template v-if="editing === 'level'">
              <select
                v-if="educationCycles.length"
                ref="editControl"
                v-model="draft"
                class="input inline-control"
                data-testid="subject-level-input"
                :disabled="isSaving"
                @keydown.esc.prevent="cancelEdit"
              >
                <option value="">{{ t('assessment.autoDetect') }}</option>
                <option v-if="unknownLevel" :value="draft">{{ unknownLevel }}</option>
                <optgroup v-for="cycle in educationCycles" :key="cycle.code" :label="cycle.name">
                  <option v-for="level in cycle.levels" :key="level.code" :value="level.code">
                    {{ level.name }}
                  </option>
                </optgroup>
              </select>
              <input
                v-else
                ref="editControl"
                v-model="draft"
                type="text"
                class="input inline-control"
                data-testid="subject-level-input"
                :placeholder="t('assessment.levelPlaceholder')"
                :disabled="isSaving"
                @keydown.enter.prevent="saveField"
                @keydown.esc.prevent="cancelEdit"
              />
              <button
                type="button"
                class="button primary compact"
                data-testid="subject-level-save"
                :disabled="isSaving"
                @click="saveField"
              >
                {{ isSaving ? t('assessment.saving') : t('assessment.save') }}
              </button>
            </template>
            <template v-else>
              <span class="meta-value" data-testid="subject-level-value">{{
                levelLabel(assessment.subject, assessment.country, assessment.level)
              }}</span>
              <MenuIconButton
                :item="pencilItem"
                test-id="subject-level-edit"
                @click="startEdit('level')"
              />
            </template>
          </li>

          <li class="editable-line">
            <span class="meta-label">{{ t('assessment.date') }}</span>
            <template v-if="editing === 'date'">
              <input
                ref="editControl"
                v-model="draft"
                type="date"
                class="input inline-control"
                data-testid="subject-date-input"
                :disabled="isSaving"
                @keydown.enter.prevent="saveField"
                @keydown.esc.prevent="cancelEdit"
              />
              <button
                type="button"
                class="button primary compact"
                data-testid="subject-date-save"
                :disabled="isSaving"
                @click="saveField"
              >
                {{ isSaving ? t('assessment.saving') : t('assessment.save') }}
              </button>
            </template>
            <template v-else>
              <span class="meta-value" data-testid="subject-date-value">{{ assessment.date || '—' }}</span>
              <MenuIconButton
                :item="pencilItem"
                test-id="subject-date-edit"
                @click="startEdit('date')"
              />
            </template>
          </li>
        </ul>
        <p v-if="saveError" class="error-message">{{ saveError }}</p>

        <section class="section" data-testid="file-zone-subject">
          <div class="section-header">
            <h2>{{ t('assessment.subjectFiles') }}</h2>
            <button
              type="button"
              class="button primary"
              data-testid="add-subject-file"
              @click="openUpload('subject')"
            >
              {{ t('assessment.addSubjectFile') }}
            </button>
          </div>
          <AssessmentFileList
            :assessment-id="assessment.id || ''"
            :files="subjectFiles"
            :students="students"
            :empty-text="t('assessment.subjectFilesEmpty')"
            @updated="onFilesUpdated"
          />
        </section>

        <section class="section" data-testid="file-zone-solution">
          <div class="section-header">
            <h2>{{ t('assessment.solutionFiles') }}</h2>
            <div class="section-actions">
              <button
                type="button"
                class="button"
                data-testid="add-solution-file"
                @click="openUpload('solution')"
              >
                {{ t('assessment.addSolutionFile') }}
              </button>
              <button
                type="button"
                class="button primary"
                data-testid="add-solution"
                @click="openCreateText('solution')"
              >
                {{ t('assessment.addSolution') }}
              </button>
            </div>
          </div>
          <AssessmentFileList
            :assessment-id="assessment.id || ''"
            :files="solutionFiles"
            :students="students"
            :empty-text="t('assessment.solutionFilesEmpty')"
            allow-text-edit
            @updated="onFilesUpdated"
            @edit-text="openEditText"
          />
        </section>

        <section class="section" data-testid="file-zone-instructions">
          <div class="section-header">
            <h2>{{ t('assessment.fileTypeInstructions') }}</h2>
            <button
              type="button"
              class="button primary"
              data-testid="add-instruction"
              :aria-label="t('assessment.addInstruction')"
              @click="openCreateText('instructions')"
            >
              {{ t('assessment.addInstruction') }}
            </button>
          </div>
          <AssessmentFileList
            :assessment-id="assessment.id || ''"
            :files="instructionFiles"
            :students="students"
            :empty-text="t('assessment.fileZoneEmpty')"
            allow-text-edit
            @updated="onFilesUpdated"
            @edit-text="openEditText"
          />
        </section>
      </div>

      <div v-else class="error">
        <h1>{{ t('assessment.notFound') }}</h1>
        <button class="button" @click="goBack">{{ t('assessment.back') }}</button>
      </div>
    </div>
  </div>

  <AddFilePopup
    v-if="uploadType && assessment?.id"
    :assessment-id="assessment.id"
    :fixed-type="uploadType"
    :title="uploadType === 'subject' ? t('assessment.addSubjectFileTitle') : t('assessment.addSolutionFileTitle')"
    @close="uploadType = null"
    @uploaded="onUploaded"
  />

  <InstructionEditorPopup
    v-if="showEditor && assessment?.id"
    :assessment-id="assessment.id"
    :files="files"
    :existing-file="editorFile"
    :kind="textKind"
    @close="closeEditor"
    @saved="onUploaded"
  />
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AddFilePopup from '@/components/AddFilePopup.vue'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import InstructionEditorPopup from '@/components/InstructionEditorPopup.vue'
import MenuIconButton from '@/components/MenuIconButton.vue'
import { useAssessment } from '@/composables/useAssessment'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { EDUCATION_LEVELS, educationLevelName } from '@/data/levels'
import { useSessionStore } from '@/stores/session'
import { isAssessmentSubject, type Assessment, type AssessmentFile, type AssessmentStudent, type MenuItem } from '@/types/types'

const router = useRouter()
const { t } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()
const { subjects, load: loadSubjects, levelName } = useSubjectCatalog()

type MetaField = 'name' | 'subject' | 'level' | 'date'

const editing = ref<MetaField | null>(null)
const draft = ref('')
const isSaving = ref(false)
const saveError = ref('')
const editControl = ref<HTMLInputElement | HTMLSelectElement | null>(null)

const pencilItem = computed<MenuItem>(() => ({
  key: 'edit',
  label: t('assessment.edit'),
  icon: 'pencil',
  color: '',
}))

const countryCode = computed(() => (assessment.value?.country || sessionStore.country || '').trim())
const educationCycles = computed(() => {
  if (!countryCode.value) return []
  return (EDUCATION_LEVELS[countryCode.value] ?? []).filter((cycle) => cycle.levels.length > 0)
})
const unknownLevel = computed(() => {
  const code = draft.value.trim()
  if (!code || educationLevelName(countryCode.value, code)) return ''
  return code
})

const subjectLabel = (subject: string) => {
  const node = subjects.value.find((item) => item.subject === subject)
  if (node?.name) return node.name
  if (isAssessmentSubject(subject)) return t(`assessment.subjects.${subject}`)
  return subject || '—'
}

const levelLabel = (subject: string, country: string | null | undefined, level: string | null | undefined) => {
  if (!level) return '—'
  const fromEducation = country ? educationLevelName(country, level) : null
  if (fromEducation) return fromEducation
  return levelName(subject, country, level) || level
}

const uploadType = ref<'subject' | 'solution' | null>(null)
const showEditor = ref(false)
const editorFile = ref<AssessmentFile | null>(null)
const textKind = ref<'instructions' | 'solution'>('instructions')

const subjectFiles = computed(() => files.value.filter((file) => (file.type ?? '') === 'subject'))
const solutionFiles = computed(() => files.value.filter((file) => (file.type ?? '') === 'solution'))
const instructionFiles = computed(() => files.value.filter((file) => (file.type ?? '') === 'instructions'))

const goBack = () => {
  router.push({ name: 'assessment', params: { id: assessmentId.value } })
}

const onFilesUpdated = (payload: { files: AssessmentFile[]; students?: AssessmentStudent[] }) => {
  applyUpdate(payload.files, payload.students)
}

const onUploaded = (updatedFiles: AssessmentFile[]) => {
  applyUpdate(updatedFiles)
}

const openUpload = (type: 'subject' | 'solution') => {
  uploadType.value = type
}

const openCreateText = (kind: 'instructions' | 'solution') => {
  textKind.value = kind
  editorFile.value = null
  showEditor.value = true
}

const openEditText = (file: AssessmentFile) => {
  const type = file.type ?? ''
  if (type !== 'instructions' && type !== 'solution') return
  textKind.value = type
  editorFile.value = file
  showEditor.value = true
}

const closeEditor = () => {
  showEditor.value = false
  editorFile.value = null
}

const fieldValue = (field: MetaField) => {
  if (!assessment.value) return ''
  if (field === 'level') return assessment.value.level || ''
  return assessment.value[field] || ''
}

const startEdit = (field: MetaField) => {
  if (isSaving.value || !assessment.value) return
  saveError.value = ''
  editing.value = field
  draft.value = fieldValue(field)
  void nextTick(() => {
    editControl.value?.focus()
  })
}

const cancelEdit = () => {
  if (isSaving.value) return
  editing.value = null
  saveError.value = ''
}

const saveField = async () => {
  if (!assessment.value?.id || !editing.value || isSaving.value) return
  const field = editing.value
  const value = draft.value.trim()
  if (field === 'subject' && !value) return

  const body: Record<string, string | null> = {}
  if (field === 'level') body.level = value || null
  else body[field] = value

  saveError.value = ''
  isSaving.value = true
  try {
    await sessionStore.getWsClient().queryWs('PUT', '/assessment', { hash: assessment.value.id }, body)
    const patch: Partial<Assessment> =
      field === 'level' ? { level: value || null } : { [field]: value }
    assessment.value = { ...assessment.value, ...patch }
    const index = sessionStore.own_assessments.findIndex((item: Assessment) => item.id === assessment.value?.id)
    if (index !== -1) {
      sessionStore.own_assessments[index] = {
        ...sessionStore.own_assessments[index],
        ...patch,
      }
    }
    editing.value = null
  } catch (err) {
    console.error('Error saving assessment field:', err)
    saveError.value = t('assessment.saveError')
  } finally {
    isSaving.value = false
  }
}

loadSubjects()
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  flex-wrap: wrap;
}

.header h1 {
  margin: 0;
}

.title-value {
  margin: 0.35rem 0 0;
  font-size: 1.15rem;
  font-weight: 700;
}

.meta-list {
  list-style: none;
  margin: 1.25rem 0 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 0.45rem;
}

.editable-line {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-wrap: wrap;
  min-height: 2rem;
}

.meta-label {
  width: 5.5rem;
  flex-shrink: 0;
  color: var(--text-muted);
}

.meta-value {
  min-width: 0;
}

.inline-control {
  width: min(16rem, 100%);
  padding: 8px 10px;
}

.button.compact {
  padding: 8px 12px;
}

.error-message {
  color: var(--danger);
  margin-top: 0.75rem;
}

.section {
  margin-top: 1.75rem;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
  flex-wrap: wrap;
}

.section-header h2 {
  margin: 0;
  font-size: 1.05rem;
}

.section-actions {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
