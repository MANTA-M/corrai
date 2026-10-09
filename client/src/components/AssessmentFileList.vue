<template>
  <p v-if="error" class="error-message">{{ error }}</p>
  <p v-if="!sortedFiles.length" class="zone-empty">
    {{ emptyText || t('assessment.fileZoneEmpty') }}
  </p>
  <ul v-else-if="cards" class="file-cards" data-testid="assessment-file-list">
    <InputFile
      v-for="file in sortedFiles"
      :key="file.id"
      :assessment-id="assessmentId"
      :file="file"
      @action="(key) => onFileAction(file, key)"
    />
  </ul>
  <ul v-else class="entity-list" data-testid="assessment-file-list">
    <li
      v-for="file in sortedFiles"
      :key="file.id"
      class="entity-row"
      :class="{ 'is-loading': file.loading, 'is-openable': isSubjectFile(file) }"
      data-testid="assessment-file-item"
      :role="isSubjectFile(file) ? 'link' : undefined"
      :tabindex="isSubjectFile(file) ? 0 : undefined"
      @click="onRowClick(file)"
      @keydown.enter="onRowKey(file)"
      @keydown.space.prevent="onRowKey(file)"
    >
      <button
        v-if="allowTextEdit && isEditableTextFile(file)"
        type="button"
        class="entity-name file-name-button"
        data-testid="assessment-file-edit"
        @click="emit('editText', file)"
      >
        {{ file.name }}
      </button>
      <span v-else class="entity-name">{{ file.name }}</span>
      <span
        v-if="file.loading"
        class="file-loading"
        data-testid="assessment-file-loading"
        aria-label="Loading"
      >
        <span class="loading-spinner"></span>
      </span>
      <span v-if="statusLabel(file)" class="file-status" data-testid="assessment-file-status">
        {{ statusLabel(file) }}
      </span>
      <span class="file-size">{{ formatFileSize(file.size) }}</span>
      <div class="row-actions" @click.stop @keydown.stop>
        <div v-if="isSubjectFile(file)" class="file-objects" data-testid="file-objects">
          <MenuIconButton
            :item="filesMenuItem"
            test-id="file-objects-button"
            :aria-expanded="directoryFileId === file.id"
            @click="toggleDirectory(file)"
          />
          <ul
            v-if="directoryFileId === file.id"
            class="file-objects-menu"
            data-testid="file-objects-list"
          >
            <li v-if="directoryLoading" class="file-objects-status">…</li>
            <li
              v-else-if="storedFiles.length === 0"
              class="file-objects-status"
              data-testid="file-objects-empty"
            >
              {{ t('assessment.fileAnnexesEmpty') }}
            </li>
            <li v-for="name in storedFiles" :key="name">
              <S3File :label="name" :href="objectUrl(file, name)" :test-id="s3TestId(name)" />
            </li>
          </ul>
        </div>
        <MenuIconButton
          v-for="item in rowMenu(file)"
          :key="item.key"
          :item="item"
          :test-id="`file-${item.key}`"
          :href="item.key === 'view' ? fileViewUrl(file) : undefined"
          @click="onFileAction(file, item.key)"
        />
      </div>
    </li>
  </ul>

  <div
    v-if="reassignTarget"
    class="popup-overlay"
    data-testid="reassign-file-popup"
    @click.self="closeReassign"
  >
    <div class="popup-content file-action-popup">
      <div class="popup-header">
        <h2>{{ t('assessment.fileReassignTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="reassign-file-close"
          :aria-label="t('common.cancel')"
          @click="closeReassign"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <p class="popup-file-name">{{ reassignTarget.name }}</p>
        <fieldset class="reassign-list">
          <legend class="hidden-visually">{{ t('assessment.fileReassignTitle') }}</legend>
          <label
            v-for="student in sortedStudents"
            :key="student.id"
            class="reassign-option"
            :class="{ selected: selectedStudentId === student.id }"
          >
            <input
              v-model="selectedStudentId"
              type="radio"
              name="reassign-target"
              :value="student.id"
              data-testid="reassign-student"
              :disabled="isUpdating"
            />
            <span>{{ student.name }}</span>
          </label>
          <label class="reassign-option" :class="{ selected: selectedStudentId === NOT_FOUND }">
            <input
              v-model="selectedStudentId"
              type="radio"
              name="reassign-target"
              :value="NOT_FOUND"
              data-testid="reassign-not-found"
              :disabled="isUpdating"
            />
            <span>{{ t('assessment.fileReassignNotFound') }}</span>
          </label>
        </fieldset>
        <label
          v-if="selectedStudentId === NOT_FOUND"
          class="file-action-label"
          for="reassign-name-input"
        >
          {{ t('assessment.studentNamePlaceholder') }}
          <input
            id="reassign-name-input"
            ref="reassignNameInput"
            v-model="newStudentName"
            type="text"
            class="input"
            data-testid="reassign-name-input"
            :disabled="isUpdating"
          />
        </label>
        <p v-if="actionError" class="error-message" data-testid="reassign-file-error">
          {{ actionError }}
        </p>
      </div>
      <div class="popup-footer">
        <button
          type="button"
          class="button secondary popup-footer-left"
          data-testid="reassign-unassign"
          :disabled="isUpdating"
          @click="unassignFile"
        >
          {{ isUpdating && isUnassigning ? t('assessment.fileUnassigning') : t('assessment.fileUnassign') }}
        </button>
        <button
          type="button"
          class="button secondary"
          data-testid="reassign-file-cancel"
          :disabled="isUpdating"
          @click="closeReassign"
        >
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          class="button primary"
          data-testid="reassign-confirm"
          :disabled="!canConfirmReassign || isUpdating"
          @click="confirmReassign"
        >
          {{ isUpdating && !isUnassigning ? t('assessment.fileReassigning') : t('assessment.fileReassignConfirm') }}
        </button>
      </div>
    </div>
  </div>

  <div
    v-if="eventsTarget"
    class="popup-overlay"
    data-testid="file-events-popup"
    @click.self="closeEvents"
  >
    <div class="popup-content file-action-popup events-popup">
      <div class="popup-header">
        <h2>{{ t('assessment.fileEventsTitle') }}</h2>
        <button
          type="button"
          class="close-button"
          data-testid="file-events-close"
          :aria-label="t('common.cancel')"
          @click="closeEvents"
        >
          &times;
        </button>
      </div>
      <div class="popup-body">
        <p class="popup-file-name">{{ eventsTarget.name }}</p>
        <p v-if="eventsLoading" class="zone-empty">…</p>
        <p v-else-if="events.length === 0" class="zone-empty" data-testid="file-events-empty">
          {{ t('assessment.fileHistoryEmpty') }}
        </p>
        <ul v-else class="events-list">
          <li v-for="event in events" :key="event.id">
            <a
              :href="eventUrl(event.id)"
              target="_blank"
              rel="noopener noreferrer"
              data-testid="file-event"
            >
              {{ eventLabel(event) }}
            </a>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <div
    v-if="renameTarget"
    class="popup-overlay"
    data-testid="rename-file-popup"
    @click.self="closeRename"
  >
    <div class="popup-content file-action-popup">
      <div class="popup-header">
        <h2>{{ t('assessment.fileRenameTitle') }}</h2>
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
          <label class="file-action-label" for="rename-file-input">{{
            t('assessment.fileRenamePlaceholder')
          }}</label>
          <input
            id="rename-file-input"
            ref="renameInput"
            v-model="renameDraft"
            type="text"
            class="input"
            data-testid="rename-file-input"
            :disabled="isUpdating"
          />
        </form>
        <p v-if="actionError" class="error-message" data-testid="rename-file-error">
          {{ actionError }}
        </p>
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
          class="button primary"
          data-testid="rename-file-save"
          :disabled="isUpdating || !renameDraft.trim()"
          @click="submitRename"
        >
          {{ isUpdating ? t('assessment.renaming') : t('assessment.rename') }}
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
        <h2>{{ t('assessment.fileDeleteTitle') }}</h2>
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
          {{ t('assessment.fileDeleteConfirm', { name: deleteTarget.name }) }}
        </p>
        <p v-if="actionError" class="error-message" data-testid="delete-file-error">
          {{ actionError }}
        </p>
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
          class="button danger"
          data-testid="delete-file-confirm-button"
          :disabled="isUpdating"
          @click="submitDelete"
        >
          {{ isUpdating ? t('assessment.deleting') : t('common.delete') }}
        </button>
      </div>
    </div>
  </div>

  <AttributesEditorPopup
    v-if="attributesFile"
    :assessment-id="assessmentId"
    :file-id="attributesFile.id"
    :title="attributesFile.name"
    @close="attributesFile = null"
    @saved="emit('changed')"
  />
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AttributesEditorPopup from '@/components/AttributesEditorPopup.vue'
import InputFile from '@/components/InputFile.vue'
import MenuIconButton from '@/components/MenuIconButton.vue'
import S3File from '@/components/S3File.vue'
import { useSessionStore } from '@/stores/session'
import { isEditableTextFile } from '@/utils/assessmentFiles'
import type {
  AssessmentFile,
  AssessmentStats,
  AssessmentStudent,
  MenuItem,
  StateLocales,
} from '@/types/types'

const NOT_FOUND = '__not_found__'

const props = defineProps<{
  assessmentId: string
  files: AssessmentFile[]
  students?: AssessmentStudent[]
  emptyText?: string
  allowTextEdit?: boolean
  cards?: boolean
}>()

const emit = defineEmits<{
  updated: [payload: { files: AssessmentFile[]; students?: AssessmentStudent[] } & AssessmentStats]
  editText: [file: AssessmentFile]
  changed: []
}>()

const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const error = ref('')
const actionError = ref('')
const isUpdating = ref(false)
const isUnassigning = ref(false)

const reassignTarget = ref<AssessmentFile | null>(null)
const selectedStudentId = ref('')
const newStudentName = ref('')
const reassignNameInput = ref<HTMLInputElement | null>(null)

const eventsTarget = ref<AssessmentFile | null>(null)
const eventsLoading = ref(false)
const events = ref<FileEvent[]>([])

const renameTarget = ref<AssessmentFile | null>(null)
const renameDraft = ref('')
const renameInput = ref<HTMLInputElement | null>(null)
const deleteTarget = ref<AssessmentFile | null>(null)

const directoryFileId = ref<string | null>(null)
const directoryLoading = ref(false)
const storedFiles = ref<string[]>([])

interface FileEvent {
  id: string
  timestamp: number
  name: string
}

interface FileDebugInfo {
  events: FileEvent[]
}

const sortedFiles = computed(() =>
  [...props.files].sort((a, b) => a.name.localeCompare(b.name, String(locale.value || 'fr'))),
)

const sortedStudents = computed(() =>
  [...(props.students ?? [])].sort((a, b) =>
    a.name.localeCompare(b.name, String(locale.value || 'fr')),
  ),
)

const filesMenuItem = computed<MenuItem>(() => ({
  key: 'files',
  label: t('assessment.files'),
  icon: 'file',
  color: '#1a55e8',
}))

const isSubjectFile = (file: AssessmentFile) => file.type === 'subject'

const rowMenu = (file: AssessmentFile) => {
  const menu = file.menu ?? []
  if (!isSubjectFile(file)) return menu
  return menu.filter((item) => item.key !== 'view')
}

const canConfirmReassign = computed(() => {
  if (isUpdating.value || !selectedStudentId.value) return false
  if (selectedStudentId.value === NOT_FOUND) return newStudentName.value.trim() !== ''
  return true
})

const formatFileSize = (size: number) => {
  if (size < 1024) return `${size} B`
  if (size < 1024 * 1024) return `${(size / 1024).toFixed(1)} KB`
  return `${(size / (1024 * 1024)).toFixed(1)} MB`
}

const fileViewUrl = (file: AssessmentFile) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: props.assessmentId,
    file: file.id,
  })

const objectUrl = (file: AssessmentFile, name: string) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: props.assessmentId,
    file: file.id,
    object: name,
  })

const s3TestId = (name: string) => `file-s3-${name.split('/').join('--')}`

const openContent = (file: AssessmentFile) => {
  window.open(fileViewUrl(file), '_blank', 'noopener,noreferrer')
}

const closeDirectory = () => {
  directoryFileId.value = null
  directoryLoading.value = false
  storedFiles.value = []
}

const onRowClick = (file: AssessmentFile) => {
  if (!isSubjectFile(file)) return
  closeDirectory()
  openContent(file)
}

const onRowKey = (file: AssessmentFile) => {
  if (!isSubjectFile(file)) return
  onRowClick(file)
}

const toggleDirectory = (file: AssessmentFile) => {
  if (directoryFileId.value === file.id) {
    closeDirectory()
    return
  }
  directoryFileId.value = file.id
  storedFiles.value = []
  void loadDirectory(file)
}

const loadDirectory = async (file: AssessmentFile) => {
  directoryLoading.value = true
  try {
    const data = await sessionStore
      .getWsClient()
      .queryWs<{ objects?: string[] }>('GET', '/file_annexes', {
        assessment: props.assessmentId,
        file: file.id,
        locale: String(locale.value),
      })
    if (directoryFileId.value !== file.id) return
    storedFiles.value = (data.objects ?? []).filter((name) => name !== 'content' && name !== '')
  } catch (err) {
    console.error('Error loading file directory:', err)
    if (directoryFileId.value === file.id) {
      storedFiles.value = []
    }
  } finally {
    if (directoryFileId.value === file.id) {
      directoryLoading.value = false
    }
  }
}

const closeDirectoryOnOutside = (event: MouseEvent) => {
  if (!directoryFileId.value) return
  const target = event.target
  if (!(target instanceof Element) || !target.closest('[data-testid="file-objects"]')) {
    closeDirectory()
  }
}

const eventUrl = (eventId: string) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: props.assessmentId,
    file: eventsTarget.value?.id ?? '',
    event: eventId,
  })

const statusLabel = (file: AssessmentFile) =>
  sessionStore.stateLabel(sessionStore.fileStates, file.status, file.status_label)

const eventLabel = (event: FileEvent) => {
  const name =
    event.name === 'Stored' || event.name === 'Loaded' ? t('assessment.fileStored') : event.name
  if (!event.timestamp) return name
  const when = new Intl.DateTimeFormat(String(locale.value || 'fr'), {
    dateStyle: 'short',
    timeStyle: 'medium',
  }).format(new Date(event.timestamp * 1000))
  return `${name} — ${when}`
}

const assessmentStats = (source: AssessmentStats | null | undefined): AssessmentStats => {
  if (!source) return {}
  const stats: AssessmentStats = {}
  if (typeof source.assessed_students_number === 'number') {
    stats.assessed_students_number = source.assessed_students_number
  }
  if ('mark_average' in source) stats.mark_average = source.mark_average ?? null
  if ('mark_min' in source) stats.mark_min = source.mark_min ?? null
  if ('mark_max' in source) stats.mark_max = source.mark_max ?? null
  return stats
}

const applyUpdatedFiles = (files: AssessmentFile[], students?: AssessmentStudent[]) => {
  emit('updated', { files, students })
}

const attributesFile = ref<AssessmentFile | null>(null)

const onFileAction = (file: AssessmentFile, key: string) => {
  if (key === 'reassign') openReassign(file)
  else if (key === 'events') openEvents(file)
  else if (key === 'rename') startRename(file)
  else if (key === 'edit_attributes') attributesFile.value = file
  else if (key === 'delete') startDelete(file)
}

const openReassign = (file: AssessmentFile) => {
  reassignTarget.value = file
  const current = (file.student ?? '').trim()
  selectedStudentId.value = sortedStudents.value.some((student) => student.id === current)
    ? current
    : ''
  newStudentName.value = ''
  actionError.value = ''
  error.value = ''
}

const closeReassign = () => {
  if (isUpdating.value) return
  reassignTarget.value = null
  actionError.value = ''
}

const openEvents = (file: AssessmentFile) => {
  eventsTarget.value = file
  events.value = []
  void loadEvents(file)
}

const closeEvents = () => {
  eventsTarget.value = null
}

const loadEvents = async (file: AssessmentFile) => {
  eventsLoading.value = true
  try {
    const data = await sessionStore.getWsClient().queryWs<FileDebugInfo>('GET', '/file_annexes', {
      assessment: props.assessmentId,
      file: file.id,
      locale: String(locale.value),
    })
    if (eventsTarget.value?.id !== file.id) return
    events.value = data.events ?? []
  } catch (err) {
    console.error('Error loading file events:', err)
    if (eventsTarget.value?.id === file.id) {
      events.value = []
    }
  } finally {
    if (eventsTarget.value?.id === file.id) {
      eventsLoading.value = false
    }
  }
}

const startRename = (file: AssessmentFile) => {
  renameTarget.value = file
  renameDraft.value = file.name
  actionError.value = ''
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

const startDelete = (file: AssessmentFile) => {
  deleteTarget.value = file
  actionError.value = ''
}

const closeDelete = () => {
  if (isUpdating.value) return
  deleteTarget.value = null
  actionError.value = ''
}

const confirmReassign = async () => {
  if (!reassignTarget.value || !canConfirmReassign.value) return
  const file = reassignTarget.value
  actionError.value = ''
  error.value = ''
  isUpdating.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    let studentId = selectedStudentId.value
    let students = props.students

    if (studentId === NOT_FOUND) {
      const name = newStudentName.value.trim()
      studentId =
        (props.students ?? []).find((student) => student.name.toLowerCase() === name.toLowerCase())
          ?.id ?? ''
      if (!studentId) {
        const created = await wsClient.queryWs<
          {
            student?: AssessmentStudent
            students?: AssessmentStudent[]
          } & AssessmentStats &
            StateLocales
        >('POST', '/student', { id: props.assessmentId, locale: String(locale.value) }, { name })
        sessionStore.applyStateLocales(created)
        studentId = created?.student?.id ?? ''
        students = created?.students ?? students
        if (!studentId) {
          throw new Error('Student create returned no id')
        }
        emit('updated', {
          files: props.files,
          students,
          ...assessmentStats(created),
        })
      }
    }

    const response = await wsClient.queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>(
      'PUT',
      '/file',
      { assessment: props.assessmentId, file: file.id, locale: String(locale.value) },
      { student: studentId },
    )
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students ?? students)
    }
    reassignTarget.value = null
  } catch (err) {
    console.error('Error reassigning file:', err)
    actionError.value = t('assessment.fileUpdateError')
  } finally {
    isUpdating.value = false
  }
}

const unassignFile = async () => {
  if (!reassignTarget.value) return
  const file = reassignTarget.value
  actionError.value = ''
  error.value = ''
  isUpdating.value = true
  isUnassigning.value = true
  try {
    const wsClient = sessionStore.getWsClient()
    const response = await wsClient.queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>(
      'PUT',
      '/file',
      { assessment: props.assessmentId, file: file.id, locale: String(locale.value) },
      { student: '' },
    )
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students ?? props.students)
    }
    reassignTarget.value = null
  } catch (err) {
    console.error('Error unassigning file:', err)
    actionError.value = t('assessment.fileUpdateError')
  } finally {
    isUpdating.value = false
    isUnassigning.value = false
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
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>('PUT', '/file', { assessment: props.assessmentId, file: renameTarget.value.id, locale: String(locale.value) }, { name: newName })
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students)
    }
    renameTarget.value = null
  } catch (err) {
    console.error('Error renaming file:', err)
    actionError.value = t('assessment.fileRenameError')
  } finally {
    isUpdating.value = false
  }
}

const submitDelete = async () => {
  if (!deleteTarget.value) return
  const removedId = deleteTarget.value.id

  actionError.value = ''
  error.value = ''
  isUpdating.value = true
  try {
    const response = await sessionStore.getWsClient().queryWs<{
      files?: AssessmentFile[]
      students?: AssessmentStudent[]
    }>('DELETE', '/file', { assessment: props.assessmentId, file: removedId, locale: String(locale.value) })
    if (response?.files) {
      applyUpdatedFiles(response.files, response.students)
    } else {
      applyUpdatedFiles(props.files.filter((file) => file.id !== removedId))
    }
    deleteTarget.value = null
  } catch (err) {
    console.error('Error deleting file:', err)
    actionError.value = t('assessment.fileDeleteError')
  } finally {
    isUpdating.value = false
  }
}

watch(selectedStudentId, (value) => {
  if (value !== NOT_FOUND) return
  void nextTick(() => {
    reassignNameInput.value?.focus()
  })
})

onMounted(() => {
  document.addEventListener('click', closeDirectoryOnOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', closeDirectoryOnOutside)
})
</script>

<style scoped>
.error-message {
  color: var(--danger);
  margin: 0 0 0.75rem;
}

.zone-empty {
  color: var(--text-muted);
  margin: 0;
  font-size: 0.9rem;
}

.file-cards {
  list-style: none;
  display: flex;
  flex-wrap: wrap;
  gap: 0.85rem;
  margin: 0;
  padding: 0;
}

.entity-list {
  list-style: none;
  margin: 0;
  padding: 0;
  border: 1px solid var(--border);
  border-radius: 15px;
  overflow: visible;
}

.entity-row {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.45rem 0.65rem;
  border-bottom: 1px solid var(--border);
}

.entity-list li:last-child .entity-row {
  border-bottom: none;
}

.entity-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-name-button {
  background: none;
  border: none;
  padding: 0;
  color: var(--link);
  text-align: left;
  cursor: pointer;
  font: inherit;
}

.file-name-button:hover {
  color: var(--link-hover);
}

.file-size,
.file-status {
  color: var(--text-muted);
  font-size: 0.85rem;
  flex-shrink: 0;
}

.file-status {
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--info) 15%, transparent);
  color: var(--info);
}

.entity-row.is-openable {
  cursor: pointer;
}

.entity-row.is-openable:hover,
.entity-row.is-openable:focus-visible {
  background: var(--hover-bg);
}

.row-actions {
  display: flex;
  align-items: center;
  gap: 0.1rem;
  flex-shrink: 0;
}

.file-objects {
  position: relative;
}

.file-objects-menu {
  position: absolute;
  top: calc(100% + 0.15rem);
  right: 0;
  z-index: 6;
  list-style: none;
  margin: 0;
  padding: 0.25rem;
  min-width: 11.5rem;
  background: var(--surface, var(--bg, #fff));
  border: 1px solid var(--border);
  border-radius: var(--radius-sm);
  box-shadow: 0 8px 24px rgb(20 24 40 / 12%);
}

.file-objects-status {
  padding: 0.35rem 0.55rem;
  color: var(--text-muted);
  font-size: 0.85rem;
}

.file-action-popup {
  width: min(440px, calc(100vw - 2rem));
}

.file-action-popup h2 {
  margin: 0;
  font-size: 1.15rem;
}

.popup-file-name {
  margin: 0 0 0.75rem;
  color: var(--text-muted);
  font-size: 0.9rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.file-action-label {
  display: flex;
  flex-direction: column;
  gap: 0.4rem;
  margin-top: 0.85rem;
  color: var(--text-muted);
  font-size: 0.9rem;
}

.reassign-list {
  border: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  max-height: 16rem;
  overflow-y: auto;
}

.reassign-option {
  display: flex;
  align-items: center;
  gap: 0.6rem;
  padding: 0.45rem 0.35rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.reassign-option.selected,
.reassign-option:hover {
  background: var(--hover-bg);
}

.events-popup {
  max-height: calc(100vh - 2rem);
  display: flex;
  flex-direction: column;
}

.events-popup .popup-header {
  flex-shrink: 0;
}

.events-popup .popup-body {
  overflow-y: auto;
  min-height: 0;
}

.events-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.events-list a {
  display: block;
  padding: 0.4rem 0;
}

.popup-footer-left {
  margin-right: auto;
}
</style>
