<template>
  <section class="files-section" data-testid="exam-files-section">
    <div class="files-header">
      <h2>{{ t('exam.files') }}</h2>
      <div class="files-header-actions">
        <div class="view-toggle" role="tablist" aria-label="File grouping">
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
            :class="{ active: viewMode === 'author' }"
            data-testid="files-view-author"
            role="tab"
            :aria-selected="viewMode === 'author'"
            @click="viewMode = 'author'"
          >
            {{ t('exam.viewByAuthor') }}
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

    <p
      v-if="!files.length"
      class="empty-files"
      data-testid="exam-files-empty"
    >
      {{ t('exam.filesEmpty') }}
    </p>

    <div v-if="viewMode === 'type'" class="type-zones" data-testid="file-type-zones">
      <section
        v-for="zone in typeZones"
        :key="zone"
        class="file-zone"
        :data-testid="`file-zone-${zone}`"
      >
        <h3>{{ typeZoneLabel(zone) }}</h3>
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

    <div v-else class="author-view" data-testid="file-author-view">
      <template v-if="selectedAuthor === null">
        <p v-if="!authorEntries.length" class="empty-files" data-testid="file-authors-empty">
          {{ t('exam.fileAuthorsEmpty') }}
        </p>
        <ul v-else class="author-list" data-testid="file-author-list">
          <li v-for="entry in authorEntries" :key="entry.id">
            <button
              type="button"
              class="author-item"
              data-testid="file-author-item"
              @click="selectedAuthor = entry.id"
            >
              <span>{{ entry.label }}</span>
              <span class="file-meta">{{ entry.count }}</span>
            </button>
          </li>
        </ul>
      </template>
      <template v-else>
        <div class="author-detail-header">
          <button
            type="button"
            class="back-button"
            data-testid="file-author-back"
            @click="selectedAuthor = null"
          >
            {{ t('exam.fileAuthorBack') }}
          </button>
          <h3>{{ selectedAuthorLabel }}</h3>
        </div>
        <p v-if="!filesForSelectedAuthor.length" class="zone-empty">
          {{ t('exam.fileZoneEmpty') }}
        </p>
        <ul v-else class="file-list">
          <li v-for="file in filesForSelectedAuthor" :key="file.name">
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
            data-testid="file-menu-set-author"
            role="menuitem"
            @click="startSetAuthor"
          >
            {{ t('exam.fileSetAuthor') }}
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
            data-testid="file-menu-author-back"
            @click="menu.mode = 'root'"
          >
            {{ t('common.back') }}
          </button>
          <form class="file-author-form" @submit.prevent="saveAuthor">
            <input
              v-model="authorDraft"
              type="text"
              class="file-author-input"
              data-testid="file-author-input"
              :placeholder="t('exam.fileAuthorPlaceholder')"
              :disabled="isUpdating"
            />
            <button
              type="submit"
              class="button add-file-button"
              data-testid="file-author-save"
              :disabled="isUpdating"
            >
              {{ t('exam.fileAuthorSave') }}
            </button>
          </form>
        </template>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import AddFilePopup from '@/components/AddFilePopup.vue'
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

const { t } = useI18n()
const sessionStore = useSessionStore()

const typeZones = EXAM_FILE_TYPE_ZONES
const viewMode = ref<'type' | 'author'>('type')
const selectedAuthor = ref<string | null>(null)
const showAddFilePopup = ref(false)
const error = ref('')
const isUpdating = ref(false)
const authorDraft = ref('')

interface FileMenu {
  file: ExamFile
  x: number
  y: number
  mode: 'root' | 'type' | 'author'
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
    unknown: 'exam.fileTypeUnknown',
  }
  return t(keys[zone])
}

const filesInZone = (zone: ExamFileTypeZone) =>
  props.files.filter((file) => fileZone(file) === zone)

const authorKey = (file: ExamFile) => (file.author ?? '').trim()

const authorEntries = computed(() => {
  const counts = new Map<string, number>()
  for (const file of props.files) {
    const key = authorKey(file)
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
      label: id === '' ? t('exam.fileAuthorUnknown') : id,
    }))
})

const filesForSelectedAuthor = computed(() => {
  if (selectedAuthor.value === null) return []
  return props.files.filter((file) => authorKey(file) === selectedAuthor.value)
})

const selectedAuthorLabel = computed(() => {
  if (selectedAuthor.value === null) return ''
  if (selectedAuthor.value === '') return t('exam.fileAuthorUnknown')
  return selectedAuthor.value
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
  const maxTop = Math.max(8, window.innerHeight - 280)
  menu.value = {
    file,
    x: Math.min(rect.left, maxLeft),
    y: Math.min(rect.bottom + 4, maxTop),
    mode: 'root',
  }
  authorDraft.value = (file.author ?? '').trim()
  error.value = ''
}

const closeMenu = () => {
  menu.value = null
}

const startSetAuthor = () => {
  if (!menu.value) return
  authorDraft.value = (menu.value.file.author ?? '').trim()
  menu.value.mode = 'author'
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

const updateTags = async (file: ExamFile, patch: { type?: string; author?: string }) => {
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

const saveAuthor = async () => {
  if (!menu.value) return
  await updateTags(menu.value.file, { author: authorDraft.value.trim() })
}

watch(
  () => props.files,
  () => {
    if (selectedAuthor.value === null) return
    const remaining = props.files.some((file) => authorKey(file) === selectedAuthor.value)
    if (!remaining) {
      selectedAuthor.value = null
    }
  }
)

watch(viewMode, () => {
  selectedAuthor.value = null
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
  border-radius: 6px;
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
  border-radius: 4px;
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
  border-radius: 8px;
  padding: 0.75rem 1rem 1rem;
  background: var(--surface-2);
  min-height: 6rem;
}

.file-zone h3,
.author-detail-header h3 {
  margin: 0 0 0.75rem;
  font-size: 1rem;
}

.file-list,
.author-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.file-item,
.author-item {
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
.author-list li:last-child .author-item {
  border-bottom: none;
}

.file-item:hover,
.author-item:hover {
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

.author-detail-header {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 0.75rem;
  flex-wrap: wrap;
}

.author-detail-header h3 {
  margin: 0;
}

.back-button {
  padding: 0.4rem 0.75rem;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.9rem;
  background: transparent;
  border: 1px solid var(--border-color);
  color: var(--text);
}

.back-button:hover {
  background: var(--hover-bg);
}

.author-list {
  border: 1px solid var(--border);
  border-radius: 8px;
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
  border-radius: 8px;
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
}

.file-menu-item:hover:not(:disabled) {
  background: var(--hover-bg);
}

.file-menu-item.active {
  color: var(--accent);
  font-weight: 600;
}

.file-menu-item:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.file-author-form {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.65rem 0.9rem 0.75rem;
}

.file-author-input {
  width: 100%;
  padding: 0.45rem 0.55rem;
  border: 1px solid var(--border);
  border-radius: 4px;
  background: var(--surface);
  color: var(--text);
}
</style>
