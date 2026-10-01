<template>
  <section class="files-section" data-testid="exam-files-section">
    <div class="files-header">
      <h2>{{ t('exam.files') }}</h2>
      <div class="files-header-actions">
        <div
          v-if="visibleFiles.length"
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
            v-if="zone === 'instructions' || zone === 'solution'"
            type="button"
            class="add-instruction-button"
            :data-testid="zone === 'instructions' ? 'add-instruction' : 'add-solution'"
            :aria-label="zone === 'instructions' ? t('exam.addInstruction') : t('exam.addSolution')"
            @click="openCreateTextFile(zone)"
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
              <span class="file-meta">
                  <span
                  v-if="sessionStore.debugMode && file.status"
                  class="file-status"
                  data-testid="exam-file-status"
                  :title="t('exam.fileStatus')"
                >{{ file.status }}</span>
                {{ formatFileSize(file.size) }}
              </span>
            </button>
          </li>
        </ul>
      </section>
    </div>

    <div v-else-if="visibleFiles.length" class="student-view" data-testid="file-student-view">
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
              <span class="file-meta">
                  <span
                  v-if="sessionStore.debugMode && file.status"
                  class="file-status"
                  data-testid="exam-file-status"
                  :title="t('exam.fileStatus')"
                >{{ file.status }}</span>
                {{ formatFileSize(file.size) }}
              </span>
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
    :kind="textFileKind"
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
            v-if="fileZone(menu.file) === 'instructions' || fileZone(menu.file) === 'solution'"
            type="button"
            class="file-menu-item"
            data-testid="file-menu-edit"
            role="menuitem"
            @click="startEditTextFile"
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
          <template v-if="sessionStore.debugMode">
            <button
              type="button"
              class="file-menu-item"
              data-testid="file-menu-history"
              role="menuitem"
              @click="menu.mode = 'history'"
            >
              {{ t('exam.fileHistory') }}
            </button>
            <p class="file-menu-section" data-testid="file-menu-annexes">
              {{ t('exam.fileAnnexes') }}
            </p>
            <p v-if="debugInfoLoading" class="file-menu-hint">…</p>
            <p
              v-else-if="annexes.length === 0"
              class="file-menu-hint"
              data-testid="file-menu-annexes-empty"
            >
              {{ t('exam.fileAnnexesEmpty') }}
            </p>
            <a
              v-for="name in annexes"
              :key="name"
              class="file-menu-item"
              :href="annexUrl(name)"
              target="_blank"
              rel="noopener noreferrer"
              :data-testid="`file-menu-annex-${name}`"
              role="menuitem"
              @click="closeMenu"
            >
              {{ name }}
            </a>
          </template>
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

        <template v-else-if="menu.mode === 'history'">
          <button
            type="button"
            class="file-menu-item"
            data-testid="file-menu-history-back"
            @click="menu.mode = 'root'"
          >
            {{ t('common.back') }}
          </button>
          <p v-if="debugInfoLoading" class="file-menu-hint">…</p>
          <p
            v-else-if="debugEvents.length === 0"
            class="file-menu-hint"
            data-testid="file-menu-history-empty"
          >
            {{ t('exam.fileHistoryEmpty') }}
          </p>
          <a
            v-for="event in debugEvents"
            :key="event.id"
            class="file-menu-item"
            :href="eventUrl(event.id)"
            target="_blank"
            rel="noopener noreferrer"
            data-testid="file-menu-event"
            role="menuitem"
            @click="closeMenu"
          >
            {{ eventLabel(event) }}
          </a>
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
  type ExamStudent,
} from '@/types/types'

const props = defineProps<{
  examId: string
  files: ExamFile[]
  students?: ExamStudent[]
}>()

const emit = defineEmits<{
  updated: [payload: { files: ExamFile[]; students?: ExamStudent[] }]
}>()

const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const typeZones = computed(() =>
  sessionStore.debugMode
    ? EXAM_FILE_TYPE_ZONES
    : EXAM_FILE_TYPE_ZONES.filter((zone) => zone !== 'debug')
)

const fileZone = (file: ExamFile): ExamFileTypeZone => {
  const type = file.type ?? ''
  return (EXAM_FILE_TYPES as readonly string[]).includes(type)
    ? (type as ExamFileTypeZone)
    : 'unknown'
}

const visibleFiles = computed(() =>
  sessionStore.debugMode
    ? props.files
    : props.files.filter((file) => fileZone(file) !== 'debug')
)

const viewMode = ref<'type' | 'student'>('type')
const selectedStudent = ref<string | null>(null)
const showAddFilePopup = ref(false)
const showInstructionEditor = ref(false)
const instructionEditorFile = ref<ExamFile | null>(null)
const textFileKind = ref<'instructions' | 'solution'>('instructions')
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
  mode: 'root' | 'type' | 'student' | 'history'
}

interface FileEvent {
  id: string
  timestamp: number
  name: string
}

interface FileDebugInfo {
  annexes: string[]
  events: FileEvent[]
}

const menu = ref<FileMenu | null>(null)
const debugInfo = ref<FileDebugInfo | null>(null)
const debugInfoLoading = ref(false)
let debugLoadSeq = 0

const annexes = computed(() => debugInfo.value?.annexes ?? [])
const debugEvents = computed(() => debugInfo.value?.events ?? [])

const typeZoneLabel = (zone: ExamFileTypeZone) => {
  const keys: Record<ExamFileTypeZone, string> = {
    subject: 'exam.fileTypeSubject',
    solution: 'exam.fileTypeSolution',
    submission: 'exam.fileTypeSubmission',
    instructions: 'exam.fileTypeInstructions',
    correction: 'exam.fileTypeCorrection',
    debug: 'exam.fileTypeDebug',
    unknown: 'exam.fileTypeUnknown',
  }
  return t(keys[zone])
}

const filesInZone = (zone: ExamFileTypeZone) =>
  visibleFiles.value.filter((file) => fileZone(file) === zone)

const studentKey = (file: ExamFile) => (file.student ?? '').trim()

const studentLabelForId = (id: string) => {
  if (id === '') return t('exam.fileStudentUnknown')
  const fromFile = visibleFiles.value.find((file) => studentKey(file) === id)?.student_name
  if (fromFile && fromFile.trim() !== '') return fromFile
  const fromList = (props.students ?? []).find((student) => student.id === id)
  return fromList?.name ?? id
}

const studentEntries = computed(() => {
  const counts = new Map<string, number>()
  for (const file of visibleFiles.value) {
    const key = studentKey(file)
    counts.set(key, (counts.get(key) ?? 0) + 1)
  }
  return [...counts.entries()]
    .sort(([a], [b]) => {
      if (a === '') return 1
      if (b === '') return -1
      return studentLabelForId(a).localeCompare(studentLabelForId(b))
    })
    .map(([id, count]) => ({
      id,
      count,
      label: studentLabelForId(id),
    }))
})

const filesForSelectedStudent = computed(() => {
  if (selectedStudent.value === null) return []
  return visibleFiles.value.filter((file) => studentKey(file) === selectedStudent.value)
})

const selectedStudentLabel = computed(() => {
  if (selectedStudent.value === null) return ''
  return studentLabelForId(selectedStudent.value)
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
  studentDraft.value = (file.student_name ?? '').trim()
  error.value = ''
  if (sessionStore.debugMode) {
    void loadFileDebug(file)
  } else {
    debugInfo.value = null
    debugInfoLoading.value = false
  }
}

const loadFileDebug = async (file: ExamFile) => {
  const seq = ++debugLoadSeq
  debugInfo.value = null
  debugInfoLoading.value = true
  try {
    const data = await sessionStore.getWsClient().queryWs<FileDebugInfo>(
      'GET',
      '/file_annexes',
      { id: props.examId, file: file.id }
    )
    if (seq !== debugLoadSeq || menu.value?.file.id !== file.id) return
    debugInfo.value = {
      annexes: data.annexes ?? [],
      events: data.events ?? [],
    }
  } catch (err) {
    console.error('Error loading file annexes:', err)
    if (seq === debugLoadSeq) {
      debugInfo.value = { annexes: [], events: [] }
    }
  } finally {
    if (seq === debugLoadSeq) {
      debugInfoLoading.value = false
    }
  }
}

const closeMenu = () => {
  menu.value = null
}

const fileViewUrl = (file: ExamFile) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    id: props.examId,
    file: file.id,
  })

const annexUrl = (name: string) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    id: props.examId,
    file: menu.value?.file.id ?? '',
    annex: name,
  })

const eventUrl = (eventId: string) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    id: props.examId,
    file: menu.value?.file.id ?? '',
    event: eventId,
  })

const eventLabel = (event: FileEvent) => {
  if (!event.timestamp) return event.name
  const when = new Intl.DateTimeFormat(String(locale.value || 'fr'), {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(new Date(event.timestamp * 1000))
  return `${event.name} — ${when}`
}

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
  studentDraft.value = (menu.value.file.student_name ?? '').trim()
  menu.value.mode = 'student'
}

const openCreateTextFile = (zone: 'instructions' | 'solution') => {
  textFileKind.value = zone
  instructionEditorFile.value = null
  showInstructionEditor.value = true
  closeMenu()
}

const startEditTextFile = () => {
  if (!menu.value) return
  const zone = fileZone(menu.value.file)
  if (zone !== 'instructions' && zone !== 'solution') return
  textFileKind.value = zone
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
    const response = await wsClient.queryWs<{ files?: ExamFile[]; students?: ExamStudent[] }>(
      'POST',
      '/correction',
      { id: props.examId, file: file.id },
      { language: uiLanguageName() }
    )
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students)
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

const applyUpdatedFiles = (files: ExamFile[], students?: ExamStudent[]) => {
  emit('updated', { files, students })
  const existingIndex = sessionStore.own_exams.findIndex((e) => e.id === props.examId)
  if (existingIndex !== -1) {
    sessionStore.own_exams[existingIndex] = {
      ...sessionStore.own_exams[existingIndex],
      files,
      students: students ?? sessionStore.own_exams[existingIndex].students,
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
    const response = await wsClient.queryWs<{ files?: ExamFile[]; students?: ExamStudent[] }>(
      'PUT',
      '/file',
      { id: props.examId, file: file.id },
      patch
    )
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students)
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
  const name = studentDraft.value.trim()
  if (name === '') {
    await updateTags(menu.value.file, { student: '' })
    return
  }

  error.value = ''
  isUpdating.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    let studentId =
      (props.students ?? []).find((student) => student.name.toLowerCase() === name.toLowerCase())
        ?.id ?? null
    let students = props.students

    if (!studentId) {
      const created = await wsClient.queryWs<{
        student?: ExamStudent
        students?: ExamStudent[]
        files?: ExamFile[]
      }>('POST', '/student', { id: props.examId }, { name })
      studentId = created?.student?.id ?? null
      students = created?.students ?? students
      if (!studentId) {
        throw new Error('Student create returned no id')
      }
    }

    const response = await wsClient.queryWs<{ files?: ExamFile[]; students?: ExamStudent[] }>(
      'PUT',
      '/file',
      { id: props.examId, file: menu.value.file.id },
      { student: studentId }
    )
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students ?? students)
    }
    closeMenu()
  } catch (err) {
    console.error('Error assigning student:', err)
    error.value = t('exam.fileUpdateError')
  } finally {
    isUpdating.value = false
  }
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
    const response = await wsClient.queryWs<{ files?: ExamFile[]; students?: ExamStudent[] }>(
      'PUT',
      '/file',
      { id: props.examId, file: renameTarget.value.id },
      { name: newName }
    )
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students)
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
    const response = await wsClient.queryWs<{ files?: ExamFile[]; students?: ExamStudent[] }>(
      'DELETE',
      '/file',
      { id: props.examId, file: deleteTarget.value.id }
    )
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students)
    } else {
      applyUpdatedFiles(props.files.filter((file) => file.id !== deleteTarget.value?.id))
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
  () => [props.files, sessionStore.debugMode] as const,
  () => {
    if (visibleFiles.value.length === 0) {
      viewMode.value = 'type'
      selectedStudent.value = null
      return
    }
    if (selectedStudent.value === null) return
    const remaining = visibleFiles.value.some((file) => studentKey(file) === selectedStudent.value)
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

.file-status {
  margin-right: 0.5rem;
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--info) 15%, transparent);
  color: var(--info);
  font-size: 0.8rem;
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
  max-height: min(70vh, 32rem);
  overflow-y: auto;
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

.file-menu-section {
  margin: 0.35rem 0 0;
  padding: 0.55rem 0.9rem 0.15rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--text-muted);
  border-top: 1px solid var(--border);
}

.file-menu-hint {
  margin: 0;
  padding: 0.35rem 0.9rem 0.55rem;
  font-size: 0.85rem;
  color: var(--text-muted);
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
