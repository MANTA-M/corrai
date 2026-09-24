<template>
  <section class="files-section" data-testid="exam-files-section">
    <div class="files-header">
      <h2>{{ t('exam.files') }}</h2>
      <div class="files-header-actions">
        <div
          v-if="files.length"
          class="view-toggle"
          role="tablist"
          aria-label="File grouping"
        >
          <button
            type="button"
            class="view-toggle-button"
            :class="{ active: viewMode === 'type' }"
            data-testid="files-view-type"
            role="tab"
            :aria-selected="viewMode === 'type'"
            @click="viewMode = 'type'"
          >
            {{ t('exam.viewByType') }}
          </button>
          <button
            type="button"
            class="view-toggle-button"
            :class="{ active: viewMode === 'student' }"
            data-testid="files-view-student"
            role="tab"
            :aria-selected="viewMode === 'student'"
            @click="viewMode = 'student'"
          >
            {{ t('exam.viewByStudent') }}
          </button>
        </div>
        <button
          type="button"
          class="button add-file-button"
          data-testid="exam-add-file"
          @click="showAddFilePopup = true"
        >
          {{ t('exam.addFiles') }}
        </button>
      </div>
    </div>

    <p v-if="error" class="error-message">{{ error }}</p>

    <div v-if="viewMode === 'type'" class="type-zones" data-testid="file-type-zones">
      <section
        v-for="zone in typeZones"
        :key="zone"
        class="file-zone"
        :data-testid="`file-zone-${zone}`"
      >
        <div class="file-zone-header">
          <h3>{{ typeZoneLabel(zone) }}</h3>
          <button
            v-if="zone === 'instructions'"
            type="button"
            class="add-instruction-button"
            data-testid="add-instruction"
            :aria-label="t('exam.addInstruction')"
            @click="openCreateInstruction"
          >
            +
          </button>
        </div>
        <p v-if="!filesInZone(zone).length" class="zone-empty">
          {{ t('exam.fileZoneEmpty') }}
        </p>
        <ul v-else class="file-list">
          <li v-for="file in filesInZone(zone)" :key="file.name">
            <button
              type="button"
              class="file-item"
              data-testid="exam-file-item"
              @click="openMenu($event, file)"
            >
              <span class="file-name">{{ file.name }}</span>
              <span class="file-meta">{{ formatFileSize(file.size) }}</span>
            </button>
          </li>
        </ul>
      </section>
    </div>

    <div v-else-if="files.length" class="student-view" data-testid="file-student-view">
      <template v-if="selectedStudent === null">
        <p v-if="!studentEntries.length" class="empty-files" data-testid="file-students-empty">
          {{ t('exam.fileStudentsEmpty') }}
        </p>
        <ul v-else class="student-list" data-testid="file-student-list">
          <li v-for="entry in studentEntries" :key="entry.id">
            <button
              type="button"
              class="student-item"
              data-testid="file-student-item"
              @click="selectedStudent = entry.id"
            >
              <span>{{ entry.label }}</span>
              <span class="file-meta">{{ entry.count }}</span>
            </button>
          </li>
        </ul>
      </template>
      <template v-else>
        <div class="student-detail-header">
          <button
            type="button"
            class="back-button"
            data-testid="file-student-back"
            @click="selectedStudent = null"
          >
            {{ t('exam.fileStudentBack') }}
          </button>
          <h3>{{ selectedStudentLabel }}</h3>
        </div>
        <p v-if="!filesForSelectedStudent.length" class="zone-empty">
          {{ t('exam.fileZoneEmpty') }}
        </p>
        <ul v-else class="file-list">
          <li v-for="file in filesForSelectedStudent" :key="file.name">
            <button
              type="button"
              class="file-item"
              data-testid="exam-file-item"
              @click="openMenu($event, file)"
            >
              <span class="file-name">{{ file.name }}</span>
              <span class="file-meta">{{ formatFileSize(file.size) }}</span>
            </button>
          </li>
        </ul>
      </template>
    </div>
  </section>

  <AddFilePopup
    v-if="showAddFilePopup && examId"
    :exam-id="examId"
    @close="showAddFilePopup = false"
    @uploaded="onFilesUploaded"
  />

  <InstructionEditorPopup
    v-if="showInstructionEditor && examId"
    :exam-id="examId"
    :files="files"
    :existing-file="instructionEditorFile"
    @close="closeInstructionEditor"
    @saved="onFilesUploaded"
  />

  <Teleport to="body">
    <div
      v-if="menu"
      class="file-menu-overlay"
      data-testid="file-menu-overlay"
      @click="closeMenu"
    >
      <div
        class="file-menu"
        :style="menuStyle"
        data-testid="file-menu"
        role="menu"
        @click.stop
      >
        <p class="file-menu-title">{{ menu.file.name }}</p>

        <template v-if="menu.mode === 'root'">
          <a
            class="file-menu-item"
            :href="fileViewUrl(menu.file)"
            target="_blank"
            rel="noopener noreferrer"
            data-testid="file-menu-view"
            role="menuitem"
            @click="closeMenu"
          >
            {{ t('exam.fileView') }}
          </a>
          <button
            v-if="fileZone(menu.file) === 'instructions'"
            type="button"
            class="file-menu-item"
            data-testid="file-menu-edit"
            role="menuitem"
            @click="startEditInstruction"
          >
            {{ t('exam.fileEdit') }}
          </button>
          <button
            v-if="fileZone(menu.file) === 'submission'"
            type="button"
            class="file-menu-item"
            data-testid="file-menu-correct"
            role="menuitem"
            :disabled="isUpdating"
            @click="correctSubmission"
          >
            {{ isUpdating ? t('exam.fileCorrecting') : t('exam.fileCorrect') }}
          </button>
          <button
            type="button"
            class="file-menu-item"
            data-testid="file-menu-rename"
            role="menuitem"
            @click="startRename"
          >
            {{ t('exam.fileRename') }}
          </button>
          <button
            type="button"
            class="file-menu-item danger"
            data-testid="file-menu-delete"
            role="menuitem"
            @click="startDelete"
          >
            {{ t('exam.fileDelete') }}
          </button>
          <button
            type="button"
            class="file-menu-item"
            data-testid="file-menu-change-type"
            role="menuitem"
            @click="menu.mode = 'type'"
          >
            {{ t('exam.fileChangeType') }}
          </button>
          <button
            type="button"
            class="file-menu-item"
            data-testid="file-menu-set-student"
            role="menuitem"
            @click="startSetStudent"
          >
            {{ t('exam.fileSetStudent') }}
          </button>
        </template>

        <template v-else-if="menu.mode === 'type'">
          <button
            type="button"
            class="file-menu-item"
            data-testid="file-menu-type-back"
            @click="menu.mode = 'root'"
          >
            {{ t('common.back') }}
          </button>
          <button
            v-for="zone in typeZones"
            :key="zone"
            type="button"
            class="file-menu-item"
            :class="{ active: fileZone(menu.file) === zone }"
            :data-testid="`file-menu-type-${zone}`"
            role="menuitem"
            :disabled="isUpdating"
            @click="changeType(zone)"
          >
            {{ typeZoneLabel(zone) }}
          </button>
        </template>

        <template v-else>
          <button
            type="button"
            class="file-menu-item"
            data-testid="file-menu-student-back"
            @click="menu.mode = 'root'"
          >
            {{ t('common.back') }}
          </button>
          <form class="file-student-form" @submit.prevent="saveStudent">
            <input
              v-model="studentDraft"
              type="text"
              class="file-student-input"
              data-testid="file-student-input"
              :placeholder="t('exam.fileStudentPlaceholder')"
              :disabled="isUpdating"
            />
            <button
              type="submit"
              class="button add-file-button"
              data-testid="file-student-save"
              :disabled="isUpdating"
            >
              {{ t('exam.fileStudentSave') }}
            </button>
          </form>
        </template>
      </div>
    </div>
  </Teleport>

  <div
    v-if="renameTarget"
    class="popup-overlay"
    data-testid="rename-file-popup"
    @click.self="closeRename"
  >
    <div class="popup-content file-action-popup">
      <div class="popup-header">
        <h2>{{ t('exam.fileRenameTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="rename-file-close"
          :aria-label="t('common.cancel')"
          @click="closeRename"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <form @submit.prevent="submitRename">
          <label class="file-action-label" for="rename-file-input">{{ t('exam.fileRenamePlaceholder') }}</label>
          <input
            id="rename-file-input"
            ref="renameInput"
            v-model="renameDraft"
            type="text"
            class="file-student-input"
            data-testid="rename-file-input"
            :disabled="isUpdating"
          />
        </form>
        <p v-if="actionError" class="error-message" data-testid="rename-file-error">{{ actionError }}</p>
      </div>
      <div class="popup-footer">
        <button
          type="button"
          class="button secondary"
          data-testid="rename-file-cancel"
          :disabled="isUpdating"
          @click="closeRename"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button add-file-button"
          data-testid="rename-file-save"
          :disabled="isUpdating || !renameDraft.trim()"
          @click="submitRename"
        >
          {{ isUpdating ? t('exam.fileRenaming') : t('exam.fileRenameSave') }}
        </button>
      </div>
    </div>
  </div>

  <div
    v-if="deleteTarget"
    class="popup-overlay"
    data-testid="delete-file-popup"
    @click.self="closeDelete"
  >
    <div class="popup-content file-action-popup">
      <div class="popup-header">
        <h2>{{ t('exam.fileDeleteTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="delete-file-close"
          :aria-label="t('common.cancel')"
          @click="closeDelete"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <p data-testid="delete-file-confirm">
          {{ t('exam.fileDeleteConfirm', { name: deleteTarget.name }) }}
        </p>
        <p v-if="actionError" class="error-message" data-testid="delete-file-error">{{ actionError }}</p>
      </div>
      <div class="popup-footer">
        <button
          type="button"
          class="button secondary"
          data-testid="delete-file-cancel"
          :disabled="isUpdating"
          @click="closeDelete"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button delete-file-button"
          data-testid="delete-file-confirm-button"
          :disabled="isUpdating"
          @click="submitDelete"
        >
          {{ isUpdating ? t('exam.fileDeleting') : t('exam.fileDelete') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AddFilePopup from '@/components/AddFilePopup.vue'
import InstructionEditorPopup from '@/components/InstructionEditorPopup.vue'
import { useSessionStore } from '@/stores/session'
import {
  EXAM_FILE_TYPES,
  EXAM_FILE_TYPE_ZONES,
  type ExamFile,
  type ExamFileTypeZone,
} from '@/types/types'

const props = defineProps<{
  examId: string
  files: ExamFile[]
}>()

const emit = defineEmits<{
  updated: [files: ExamFile[]]
}>()

const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const typeZones = EXAM_FILE_TYPE_ZONES
const viewMode = ref<'type' | 'student'>('type')
const selectedStudent = ref<string | null>(null)
const showAddFilePopup = ref(false)
const showInstructionEditor = ref(false)
const instructionEditorFile = ref<ExamFile | null>(null)
const error = ref('')
const actionError = ref('')
const isUpdating = ref(false)
const studentDraft = ref('')
const renameTarget = ref<ExamFile | null>(null)
const renameDraft = ref('')
const renameInput = ref<HTMLInputElement | null>(null)
const deleteTarget = ref<ExamFile | null>(null)

interface FileMenu {
  file: ExamFile
  x: number
  y: number
  mode: 'root' | 'type' | 'student'
}

const menu = ref<FileMenu | null>(null)

const fileZone = (file: ExamFile): ExamFileTypeZone => {
  const type = file.type ?? ''
  return (EXAM_FILE_TYPES as readonly string[]).includes(type)
    ? (type as ExamFileTypeZone)
    : 'unknown'
}

const typeZoneLabel = (zone: ExamFileTypeZone) => {
  const keys: Record<ExamFileTypeZone, string> = {
    subject: 'exam.fileTypeSubject',
    solution: 'exam.fileTypeSolution',
    submission: 'exam.fileTypeSubmission',
    instructions: 'exam.fileTypeInstructions',
    correction: 'exam.fileTypeCorrection',
    unknown: 'exam.fileTypeUnknown',
  }
  return t(keys[zone])
}

const filesInZone = (zone: ExamFileTypeZone) =>
  props.files.filter((file) => fileZone(file) === zone)

const studentKey = (file: ExamFile) => (file.student ?? '').trim()

const studentEntries = computed(() => {
  const counts = new Map<string, number>()
  for (const file of props.files) {
    const key = studentKey(file)
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }
  return [...counts.entries()]
    .sort(([a], [b]) => {
      if (a === '') return 1
      if (b === '') return -1
      return a.localeCompare(b)
    })
    .map(([id, count]) => ({
      id,
      count,
      label: id === '' ? t('exam.fileStudentUnknown') : id,
    }))
})

const filesForSelectedStudent = computed(() => {
  if (selectedStudent.value === null) return []
  return props.files.filter((file) => studentKey(file) === selectedStudent.value)
})

const selectedStudentLabel = computed(() => {
  if (selectedStudent.value === null) return ''
  if (selectedStudent.value === '') return t('exam.fileStudentUnknown')
  return selectedStudent.value
})

const menuStyle = computed(() => {
  if (!menu.value) return {}
  return {
    top: `${menu.value.y}px`,
    left: `${menu.value.x}px`,
  }
})

const formatFileSize = (size: number) => {
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

const openMenu = (event: MouseEvent, file: ExamFile) => {
  const target = event.currentTarget as HTMLElement
  const rect = target.getBoundingClientRect()
  const maxLeft = Math.max(8, window.innerWidth - 240)
  const maxTop = Math.max(8, window.innerHeight - 400)
  menu.value = {
    file,
    x: Math.min(rect.left, maxLeft),
    y: Math.min(rect.bottom + 4, maxTop),
    mode: 'root',
  }
  studentDraft.value = (file.student ?? '').trim()
  error.value = ''
}

const closeMenu = () => {
  menu.value = null
}

const fileViewUrl = (file: ExamFile) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    id: props.examId,
    filename: file.name,
  })

const startRename = () => {
  if (!menu.value) return
  renameTarget.value = menu.value.file
  renameDraft.value = menu.value.file.name
  actionError.value = ''
  closeMenu()
  void nextTick(() => {
    renameInput.value?.focus()
    renameInput.value?.select()
  })
}

const closeRename = () => {
  if (isUpdating.value) return
  renameTarget.value = null
  actionError.value = ''
}

const startDelete = () => {
  if (!menu.value) return
  deleteTarget.value = menu.value.file
  actionError.value = ''
  closeMenu()
}

const closeDelete = () => {
  if (isUpdating.value) return
  deleteTarget.value = null
  actionError.value = ''
}

const startSetStudent = () => {
  if (!menu.value) return
  studentDraft.value = (menu.value.file.student ?? '').trim()
  menu.value.mode = 'student'
}

const openCreateInstruction = () => {
  instructionEditorFile.value = null
  showInstructionEditor.value = true
  closeMenu()
}

const startEditInstruction = () => {
  if (!menu.value) return
  instructionEditorFile.value = menu.value.file
  showInstructionEditor.value = true
  closeMenu()
}

const closeInstructionEditor = () => {
  showInstructionEditor.value = false
  instructionEditorFile.value = null
}

const uiLanguageName = () => {
  const names: Record<string, string> = {
    en: 'English',
    fr: 'French',
    es: 'Spanish',
    de: 'German',
    pt: 'Portuguese',
    ro: 'Romanian',
    ru: 'Russian',
    uk: 'Ukrainian',
  }
  const code = String(locale.value || 'fr').split('-')[0]
  return names[code] ?? code
}

const correctSubmission = async () => {
  if (!menu.value) return
  const file = menu.value.file
  error.value = ''
  isUpdating.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
      'POST',
      '/correction',
      { id: props.examId, filename: file.name },
      { language: uiLanguageName() }
    )
    if (response?.files) {
      applyUpdatedFiles(response.files)
    }
    closeMenu()
  } catch (err) {
    console.error('Error correcting submission:', err)
    error.value = t('exam.fileCorrectError')
    closeMenu()
  } finally {
    isUpdating.value = false
  }
}

const applyUpdatedFiles = (files: ExamFile[]) => {
  emit('updated', files)
  const existingIndex = sessionStore.own_exams.findIndex((e) => e.id === props.examId)
  if (existingIndex !== -1) {
    sessionStore.own_exams[existingIndex] = {
      ...sessionStore.own_exams[existingIndex],
      files,
    }
  }
}

const onFilesUploaded = (updatedFiles: ExamFile[]) => {
  applyUpdatedFiles(updatedFiles)
}

const updateTags = async (file: ExamFile, patch: { type?: string; student?: string }) => {
  error.value = ''
  isUpdating.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
      'PUT',
      '/file',
      { id: props.examId, filename: file.name },
      patch
    )
    if (response?.files) {
      applyUpdatedFiles(response.files)
    }
    closeMenu()
  } catch (err) {
    console.error('Error updating file tags:', err)
    error.value = t('exam.fileUpdateError')
  } finally {
    isUpdating.value = false
  }
}

const changeType = async (zone: ExamFileTypeZone) => {
  if (!menu.value) return
  await updateTags(menu.value.file, { type: zone === 'unknown' ? '' : zone })
}

const saveStudent = async () => {
  if (!menu.value) return
  await updateTags(menu.value.file, { student: studentDraft.value.trim() })
}

const submitRename = async () => {
  if (!renameTarget.value) return
  const newName = renameDraft.value.trim()
  if (!newName) return

  actionError.value = ''
  error.value = ''
  isUpdating.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
      'PUT',
      '/file',
      { id: props.examId, filename: renameTarget.value.name },
      { name: newName }
    )
    if (response?.files) {
      applyUpdatedFiles(response.files)
    }
    renameTarget.value = null
  } catch (err) {
    console.error('Error renaming file:', err)
    actionError.value = t('exam.fileRenameError')
  } finally {
    isUpdating.value = false
  }
}

const submitDelete = async () => {
  if (!deleteTarget.value) return

  actionError.value = ''
  error.value = ''
  isUpdating.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{ files?: ExamFile[] }>(
      'DELETE',
      '/file',
      { id: props.examId, filename: deleteTarget.value.name }
    )
    if (response?.files) {
      applyUpdatedFiles(response.files)
    } else {
      applyUpdatedFiles(props.files.filter((file) => file.name !== deleteTarget.value?.name))
    }
    deleteTarget.value = null
  } catch (err) {
    console.error('Error deleting file:', err)
    actionError.value = t('exam.fileDeleteError')
  } finally {
    isUpdating.value = false
  }
}

watch(
  () => props.files,
  () => {
    if (props.files.length === 0) {
      viewMode.value = 'type'
      selectedStudent.value = null
      return
    }
    if (selectedStudent.value === null) return
    const remaining = props.files.some((file) => studentKey(file) === selectedStudent.value)
    if (!remaining) {
      selectedStudent.value = null
    }
  }
)

watch(viewMode, () => {
  selectedStudent.value = null
  closeMenu()
})
</script>

<style scoped>
.files-section {
  margin-top: 0.5rem;
}

.files-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  margin-bottom: 1rem;
  flex-wrap: wrap;
}

.files-header h2 {
  margin: 0;
  font-size: 1.15rem;
}

.files-header-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

.view-toggle {
  display: inline-flex;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  overflow: hidden;
}

.view-toggle-button {
  background: transparent;
  border: none;
  color: var(--text-muted);
  padding: 0.4rem 0.75rem;
  cursor: pointer;
  font-size: 0.9rem;
}

.view-toggle-button.active {
  background: var(--accent);
  color: white;
}

.view-toggle-button:not(.active):hover {
  background: var(--hover-bg);
}

.add-file-button {
  padding: 0.5rem 1rem;
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: 0.95rem;
  background-color: var(--accent);
  color: white;
  border: none;
}

.add-file-button:hover:not(:disabled) {
  background-color: var(--accent-600);
}

.add-file-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.delete-file-button {
  padding: 0.5rem 1rem;
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: 0.95rem;
  background-color: var(--danger);
  color: white;
  border: none;
}

.delete-file-button:hover:not(:disabled) {
  background-color: var(--danger-600);
}

.delete-file-button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.file-action-popup {
  width: min(420px, calc(100vw - 2rem));
}

.file-action-popup h2 {
  margin: 0;
  font-size: 1.15rem;
}

.file-action-label {
  display: block;
  margin-bottom: 0.4rem;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.file-action-popup .file-student-input {
  width: 100%;
  padding: 0.5rem 0.65rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
}

.file-action-popup .error-message {
  margin-top: 0.75rem;
}

.empty-files,
.zone-empty {
  color: var(--text-muted);
  margin: 0;
}

.zone-empty {
  font-size: 0.9rem;
  padding: 0.25rem 0;
}

.type-zones {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
  gap: 1rem;
}

.file-zone {
  border: 1px solid var(--border);
  border-radius: 15px;
  padding: 0.75rem 1rem 1rem;
  background: var(--surface-2);
  min-height: 6rem;
}

.file-zone-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 0.75rem;
}

.file-zone-header h3,
.student-detail-header h3 {
  margin: 0;
  font-size: 1rem;
}

.add-instruction-button {
  width: 1.75rem;
  height: 1.75rem;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  border: none;
  border-radius: var(--radius-md);
  background-color: var(--accent);
  color: white;
  font-size: 1.15rem;
  line-height: 1;
  cursor: pointer;
}

.add-instruction-button:hover {
  background-color: var(--accent-600);
}

.file-list,
.student-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.file-item,
.student-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  width: 100%;
  padding: 0.65rem 0.75rem;
  border: none;
  border-bottom: 1px solid var(--border);
  background: transparent;
  color: var(--text);
  text-align: left;
  cursor: pointer;
  font-size: 0.95rem;
}

.file-list li:last-child .file-item,
.student-list li:last-child .student-item {
  border-bottom: none;
}

.file-item:hover,
.student-item:hover {
  background: var(--hover-bg);
}

.file-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-meta {
  color: var(--text-muted);
  font-size: 0.9rem;
  flex-shrink: 0;
}

.student-detail-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
  flex-wrap: wrap;
}

.student-detail-header h3 {
  margin: 0;
}

.back-button {
  padding: 0.4rem 0.75rem;
  border-radius: var(--radius-md);
  cursor: pointer;
  font-size: 0.9rem;
  background: transparent;
  border: 1px solid var(--border-color);
  color: var(--text);
}

.back-button:hover {
  background: var(--hover-bg);
}

.student-list {
  border: 1px solid var(--border);
  border-radius: 15px;
  overflow: hidden;
}

.error-message {
  color: var(--danger);
  margin-bottom: 1rem;
}

</style>

<style>
.file-menu-overlay {
  position: fixed;
  inset: 0;
  z-index: 1100;
}

.file-menu {
  position: fixed;
  min-width: 220px;
  max-width: min(320px, calc(100vw - 1.5rem));
  background: var(--popover);
  border: 1px solid var(--border);
  border-radius: 15px;
  box-shadow: var(--shadow-2);
  padding: 0.4rem 0;
}

.file-menu-title {
  margin: 0;
  padding: 0.4rem 0.9rem 0.6rem;
  font-size: 0.8rem;
  color: var(--text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  border-bottom: 1px solid var(--border);
}

.file-menu-item {
  display: block;
  width: 100%;
  background: transparent;
  border: none;
  color: var(--text);
  text-align: left;
  padding: 0.55rem 0.9rem;
  cursor: pointer;
  font-size: 0.95rem;
  text-decoration: none;
  box-sizing: border-box;
}

.file-menu-item:hover:not(:disabled) {
  background: var(--hover-bg);
}

.file-menu-item.active {
  color: var(--accent);
  font-weight: 600;
}

.file-menu-item.danger {
  color: var(--danger);
}

.file-menu-item:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.file-student-form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.65rem 0.9rem 0.75rem;
}

.file-student-input {
  width: 100%;
  padding: 0.45rem 0.55rem;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  background: var(--surface);
  color: var(--text);
}
</style>
