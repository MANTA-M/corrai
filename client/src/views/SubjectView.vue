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
            <div class="editable-line title-line">
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
                <h1 data-testid="subject-page-title">{{ t('assessment.subjectPageTitle') + (assessment.name ? ': ' + assessment.name : '') }}</h1>
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
            <span class="meta-label">{{ t('assessment.country') }}</span>
            <template v-if="editing === 'country'">
              <select
                v-if="catalogCountries.length"
                ref="editControl"
                v-model="draft"
                class="input inline-control"
                data-testid="subject-country-input"
                :disabled="isSaving"
                @keydown.esc.prevent="cancelEdit"
              >
                <option value="">{{ t('assessment.autoDetect') }}</option>
                <option v-if="unknownCountry" :value="draft">{{ unknownCountry }}</option>
                <option v-for="item in catalogCountries" :key="item.country" :value="item.country">
                  {{ item.name }}
                </option>
              </select>
              <input
                v-else
                ref="editControl"
                v-model="draft"
                type="text"
                class="input inline-control"
                data-testid="subject-country-input"
                :placeholder="t('assessment.countryPlaceholder')"
                :disabled="isSaving"
                @keydown.enter.prevent="saveField"
                @keydown.esc.prevent="cancelEdit"
              />
              <button
                type="button"
                class="button primary compact"
                data-testid="subject-country-save"
                :disabled="isSaving"
                @click="saveField"
              >
                {{ isSaving ? t('assessment.saving') : t('assessment.save') }}
              </button>
            </template>
            <template v-else>
              <span class="meta-value" data-testid="subject-country-value">{{
                countryLabel(assessment.subject, assessment.country)
              }}</span>
              <MenuIconButton
                :item="pencilItem"
                test-id="subject-country-edit"
                @click="startEdit('country')"
              />
            </template>
          </li>

          <li class="editable-line">
            <span class="meta-label">{{ t('assessment.level') }}</span>
            <template v-if="editing === 'level'">
              <select
                v-if="catalogLevels.length"
                ref="editControl"
                v-model="draft"
                class="input inline-control"
                data-testid="subject-level-input"
                :disabled="isSaving"
                @keydown.esc.prevent="cancelEdit"
              >
                <option value="">{{ t('assessment.autoDetect') }}</option>
                <option v-if="unknownLevel" :value="draft">{{ unknownLevel }}</option>
                <option v-for="item in catalogLevels" :key="item.level" :value="item.level">
                  {{ item.name }}
                </option>
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
              <span class="meta-value" data-testid="subject-date-value">{{ assessment.date || '' }}</span>
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
              class="icon-button section-add-button"
              data-testid="add-subject-file"
              :aria-label="t('assessment.addSubjectFile')"
              :title="t('assessment.addSubjectFile')"
              @click="openUpload('subject')"
            >
              <ActionIcon name="plus" />
              <span class="icon-tooltip" role="tooltip" aria-hidden="true">{{ t('assessment.addSubjectFile') }}</span>
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
                class="icon-button section-add-button"
                data-testid="add-solution-file"
                :aria-label="t('assessment.addSolutionFile')"
                :title="t('assessment.addSolutionFile')"
                @click="openUpload('solution')"
              >
                <ActionIcon name="plus" />
                <span class="icon-tooltip" role="tooltip" aria-hidden="true">{{ t('assessment.addSolutionFile') }}</span>
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
            <span v-if="isSavingInstruction" class="instruction-saving-indicator">{{ t('assessment.saving') }}</span>
          </div>
          <div class="instruction-editor-wrapper">
            <textarea
              ref="instructionTextareaRef"
              v-model="instructionText"
              class="instruction-textarea"
              data-testid="instruction-body"
              :placeholder="t('assessment.instructionBody')"
              :disabled="isLoadingInstruction"
              @input="onInstructionInput"
              @blur="onInstructionBlur"
            />
          </div>
          <p v-if="instructionError" class="error-message">{{ instructionError }}</p>
        </section>

        <div v-if="canTestCorrection" class="test-correction-actions">
          <button
            type="button"
            class="button secondary"
            data-testid="assessment-test-correction"
            :disabled="isTestingCorrection"
            @click="launchTestCorrection"
          >
            {{ t('assessment.testCorrection') }}
          </button>
          <p v-if="testCorrectionError" class="error-message">{{ testCorrectionError }}</p>
        </div>
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
    auto-close
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
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AddFilePopup from '@/components/AddFilePopup.vue'
import ActionIcon from '@/components/ActionIcon.vue'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import InstructionEditorPopup from '@/components/InstructionEditorPopup.vue'
import MenuIconButton from '@/components/MenuIconButton.vue'
import { useAssessment } from '@/composables/useAssessment'
import { useSubjectCatalog } from '@/composables/useSubjectCatalog'
import { educationLevelName } from '@/data/levels'
import { useSessionStore } from '@/stores/session'
import { toast } from 'vue3-toastify'
import { isAssessmentSubject, type Assessment, type AssessmentFile, type AssessmentStudent, type MenuItem } from '@/types/types'

const router = useRouter()
const { t, locale } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()
const { subjects, load: loadSubjects, countryName, levelName, countriesFor, levelsFor, countryForLevel } =
  useSubjectCatalog()

type MetaField = 'name' | 'subject' | 'country' | 'level' | 'date'

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

const catalogCountries = computed(() => countriesFor(assessment.value?.subject || ''))
const catalogLevels = computed(() =>
  levelsFor(assessment.value?.subject || '', assessment.value?.country)
)
const unknownCountry = computed(() => {
  const code = draft.value.trim()
  if (!code || catalogCountries.value.some((item) => item.country === code)) return ''
  return countryName(assessment.value?.subject || '', code) || code
})
const unknownLevel = computed(() => {
  const code = draft.value.trim()
  if (!code || catalogLevels.value.some((item) => item.level === code)) return ''
  return (
    levelName(assessment.value?.subject || '', assessment.value?.country, code) ||
    educationLevelName(assessment.value?.country || '', code) ||
    code
  )
})

const subjectLabel = (subject: string) => {
  const node = subjects.value.find((item) => item.subject === subject)
  if (node?.name) return node.name
  if (isAssessmentSubject(subject)) return t(`assessment.subjects.${subject}`)
  return subject || '—'
}

const countryLabel = (subject: string, country: string | null | undefined) => {
  if (!country) return ''
  return countryName(subject, country) || country
}

const levelLabel = (subject: string, country: string | null | undefined, level: string | null | undefined) => {
  if (!level) return ''
  const fromCatalog = levelName(subject, country, level)
  if (fromCatalog && fromCatalog !== level) return fromCatalog
  const fromEducation = country ? educationLevelName(country, level) : null
  if (fromEducation) return fromEducation
  return fromCatalog || level
}

const uploadType = ref<'subject' | 'solution' | null>(null)
const showEditor = ref(false)
const editorFile = ref<AssessmentFile | null>(null)
const textKind = ref<'instructions' | 'solution'>('instructions')

const subjectFiles = computed(() => files.value.filter((file) => (file.type ?? '') === 'subject'))
const solutionFiles = computed(() => files.value.filter((file) => (file.type ?? '') === 'solution'))
const instructionFiles = computed(() => files.value.filter((file) => (file.type ?? '') === 'instructions'))
const canTestCorrection = computed(() =>
  (assessment.value?.menu ?? []).some((item) => item.key === 'test_correction')
)

const isTestingCorrection = ref(false)
const testCorrectionError = ref('')

const instructionFile = computed<AssessmentFile | null>(() => {
  return instructionFiles.value[0] || null
})

const instructionText = ref('')
const isLoadingInstruction = ref(false)
const isSavingInstruction = ref(false)
const instructionError = ref('')
const instructionTextareaRef = ref<HTMLTextAreaElement | null>(null)
let saveTimeout: ReturnType<typeof setTimeout> | null = null
let lastLoadedFileId: string | null = null
let lastSavedContent = ''

const adjustTextareaHeight = () => {
  const el = instructionTextareaRef.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${el.scrollHeight}px`
}

const fileViewUrl = (file: AssessmentFile) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: assessmentId.value,
    file: file.id,
  })

const loadInstructionContent = async () => {
  const file = instructionFile.value
  if (!file) {
    lastLoadedFileId = null
    instructionText.value = ''
    lastSavedContent = ''
    await nextTick()
    adjustTextareaHeight()
    return
  }

  if (file.id === lastLoadedFileId && instructionText.value !== '') {
    return
  }

  lastLoadedFileId = file.id
  isLoadingInstruction.value = true
  instructionError.value = ''
  try {
    const response = await fetch(fileViewUrl(file))
    if (!response.ok) throw new Error('Failed to load instruction file')
    const text = await response.text()
    instructionText.value = text
    lastSavedContent = text
    await nextTick()
    adjustTextareaHeight()
  } catch (err) {
    console.error('Error loading instruction:', err)
    instructionError.value = t('assessment.instructionLoadError')
  } finally {
    isLoadingInstruction.value = false
  }
}

watch(
  () => instructionFile.value?.id,
  () => {
    loadInstructionContent()
  },
  { immediate: true }
)

const saveInstructionContent = async () => {
  if (saveTimeout) {
    clearTimeout(saveTimeout)
    saveTimeout = null
  }

  const contentToSave = instructionText.value
  if (contentToSave === lastSavedContent && lastLoadedFileId) {
    return
  }

  if (!assessment.value?.id) return

  isSavingInstruction.value = true
  instructionError.value = ''

  try {
    const wsClient = sessionStore.getWsClient()
    const targetFile = instructionFile.value

    if (!targetFile) {
      if (!contentToSave.trim()) {
        isSavingInstruction.value = false
        return
      }
      const filename = 'instructions.md'
      const blob = new Blob([contentToSave], { type: 'text/markdown;charset=utf-8' })
      const file = new File([blob], filename, { type: 'text/markdown' })
      const formData = new FormData()
      formData.append('file', file)
      formData.append('type', 'instructions')
      const response = await wsClient.queryWs<{ files?: AssessmentFile[] }>(
        'POST',
        '/file',
        { assessment: assessment.value.id, locale: String(locale.value) },
        formData,
        'form'
      )
      if (response?.files) {
        lastSavedContent = contentToSave
        applyUpdate(response.files)
      }
    } else {
      const patch: { content: string; name?: string } = { content: contentToSave }
      if (targetFile.name?.toLowerCase().includes('consigne') || targetFile.name?.toLowerCase().endsWith('.txt')) {
        patch.name = 'instructions.md'
      }
      const response = await wsClient.queryWs<{ files?: AssessmentFile[] }>(
        'PUT',
        '/file',
        { assessment: assessment.value.id, file: targetFile.id, locale: String(locale.value) },
        patch
      )
      if (response?.files) {
        lastSavedContent = contentToSave
        applyUpdate(response.files)
      }
    }
  } catch (err) {
    console.error('Error saving instruction content:', err)
    instructionError.value = t('assessment.instructionSaveError')
  } finally {
    isSavingInstruction.value = false
  }
}

const onInstructionInput = () => {
  adjustTextareaHeight()
  if (saveTimeout) {
    clearTimeout(saveTimeout)
  }
  saveTimeout = setTimeout(() => {
    saveInstructionContent()
  }, 1200)
}

const onInstructionBlur = () => {
  if (saveTimeout) {
    clearTimeout(saveTimeout)
    saveTimeout = null
  }
  void saveInstructionContent()
}

onBeforeUnmount(() => {
  if (saveTimeout) {
    clearTimeout(saveTimeout)
    saveInstructionContent()
  }
})

const goBack = () => {
  router.push({ name: 'assessment', params: { id: assessmentId.value } })
}

const launchTestCorrection = async () => {
  if (!assessment.value?.id || isTestingCorrection.value) return
  testCorrectionError.value = ''
  isTestingCorrection.value = true
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>('POST', '/assessment_test_correction', {
      id: assessment.value.id,
      locale: String(locale.value),
    })
    if (response?.files) {
      applyUpdate(response.files, response.students)
      toast.success(t('assessment.startCorrectionSuccess'), {
        position: toast.POSITION.TOP_CENTER,
      })
      return
    }
    testCorrectionError.value = t('assessment.startCorrectionError')
  } catch (err) {
    console.error('Error starting test correction:', err)
    testCorrectionError.value = t('assessment.startCorrectionError')
  } finally {
    isTestingCorrection.value = false
  }
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
  if (field === 'country') return assessment.value.country || ''
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
  if (field === 'level') {
    body.level = value || null
    if (value && !assessment.value.country) {
      const inferred = countryForLevel(assessment.value.subject, value)
      if (inferred) body.country = inferred
    }
  } else if (field === 'country') {
    body.country = value || null
  } else {
    body[field] = value
  }

  saveError.value = ''
  isSaving.value = true
  try {
    await sessionStore.getWsClient().queryWs('PUT', '/assessment', { hash: assessment.value.id }, body)
    const patch: Partial<Assessment> =
      field === 'level'
        ? { level: value || null, ...(body.country ? { country: body.country } : {}) }
        : field === 'country'
          ? { country: value || null }
          : { [field]: value }
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

.title-line {
  min-height: unset;
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

.section-add-button {
  color: var(--accent);
}

.icon-button {
  position: relative;
  width: 2rem;
  height: 2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: var(--text-muted);
  border-radius: var(--radius-sm);
  cursor: pointer;
  padding: 0;
  text-decoration: none;
}

.icon-button:hover,
.icon-button:focus-visible {
  background: var(--hover-bg);
  color: var(--text);
}

.icon-button :deep(svg) {
  width: 1.15rem;
  height: 1.15rem;
}

.icon-tooltip {
  position: absolute;
  bottom: calc(100% + 0.35rem);
  left: 50%;
  transform: translateX(-50%);
  padding: 0.2rem 0.45rem;
  border-radius: var(--radius-sm);
  background: var(--text);
  color: var(--bg, #fff);
  font-size: 0.75rem;
  line-height: 1.2;
  white-space: nowrap;
  opacity: 0;
  pointer-events: none;
  z-index: 4;
}

.icon-button:hover .icon-tooltip,
.icon-button:focus-visible .icon-tooltip {
  opacity: 1;
}

.instruction-editor-wrapper {
  margin-top: 0.5rem;
}

.instruction-saving-indicator {
  font-size: 0.85rem;
  color: var(--text-muted);
}

.instruction-textarea {
  width: 100%;
  min-height: 8rem;
  padding: 0.75rem;
  border-radius: var(--radius-sm);
  border: 1px solid var(--border-color, #ccc);
  background: var(--input-bg, transparent);
  color: inherit;
  font-family: inherit;
  font-size: 0.95rem;
  line-height: 1.5;
  box-sizing: border-box;
  resize: vertical;
  overflow-y: hidden;
  transition: border-color 0.15s ease;
}

.instruction-textarea:focus {
  outline: none;
  border-color: var(--accent);
}

.test-correction-actions {
  margin-top: 1.75rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
