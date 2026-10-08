<template>
  <li
    ref="root"
    class="file-card"
    :class="{ 'is-loading': file.loading }"
    data-testid="assessment-file-item"
  >
    <div class="file-card-head">
      <span class="file-card-name" :lang="String(locale)" :title="file.name">{{ file.name }}</span>
      <button
        type="button"
        class="file-card-menu"
        data-testid="file-menu"
        :aria-label="file.name"
        :aria-expanded="menuOpen"
        @click="toggleMenu"
      >
        <ActionIcon name="caret" />
      </button>
      <ul v-if="menuOpen" class="file-card-dropdown" data-testid="file-menu-list">
        <li v-if="canReassignFile(file)">
          <button type="button" data-testid="file-reassign" @click="onAction('reassign')">
            {{ t('assessment.fileAssignStudent') }}
          </button>
        </li>
        <li>
          <button type="button" data-testid="file-rename" @click="onAction('rename')">
            {{ t('assessment.rename') }}
          </button>
        </li>
        <li>
          <button type="button" data-testid="file-events" @click="onAction('events')">
            {{ t('assessment.fileHistory') }}
          </button>
        </li>
        <li v-if="sessionStore.debugMode" class="file-card-files">
          <button
            type="button"
            data-testid="file-directory"
            :aria-expanded="directoryOpen"
            @click.stop="toggleDirectory"
          >
            {{ t('assessment.files') }}
          </button>
          <ul v-if="directoryOpen" class="file-card-submenu" data-testid="file-directory-list">
            <li v-if="directoryLoading" class="file-directory-status">…</li>
            <li
              v-else-if="storedFiles.length === 0"
              class="file-directory-status"
              data-testid="file-directory-empty"
            >
              {{ t('assessment.fileAnnexesEmpty') }}
            </li>
            <li v-for="name in storedFiles" :key="name">
              <S3File :label="name" :href="objectUrl(name)" :test-id="s3TestId(name)" />
            </li>
          </ul>
        </li>
        <li>
          <button
            type="button"
            class="danger"
            data-testid="file-delete"
            @click="onAction('delete')"
          >
            {{ t('common.delete') }}
          </button>
        </li>
      </ul>
    </div>
    <a
      class="file-thumb"
      data-testid="file-view"
      :href="fileViewUrl"
      target="_blank"
      rel="noopener noreferrer"
      :aria-label="viewLabel"
      :title="viewLabel"
    >
      <img v-if="file.thumbnail" :src="thumbnailUrl" alt="" data-testid="file-thumbnail" />
      <span v-else class="file-thumb-missing" data-testid="file-thumbnail">?</span>
      <span class="icon-tooltip" role="tooltip" aria-hidden="true">{{ viewLabel }}</span>
    </a>
    <div v-if="file.loading || statusLabel" class="file-card-status">
      <span
        v-if="file.loading"
        class="file-loading"
        data-testid="assessment-file-loading"
        aria-label="Loading"
      >
        <span class="loading-spinner"></span>
      </span>
      <span v-if="statusLabel" class="file-status" data-testid="assessment-file-status">
        {{ statusLabel }}
      </span>
    </div>
  </li>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionIcon from '@/components/ActionIcon.vue'
import S3File from '@/components/S3File.vue'
import { useSessionStore } from '@/stores/session'
import { canReassignFile } from '@/utils/assessmentFiles'
import type { AssessmentFile } from '@/types/types'

const props = defineProps<{
  assessmentId: string
  file: AssessmentFile
}>()

const emit = defineEmits<{
  action: [key: string]
}>()

const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const root = ref<HTMLElement | null>(null)
const menuOpen = ref(false)
const directoryOpen = ref(false)
const directoryLoading = ref(false)
const storedFiles = ref<string[]>([])

const fileViewUrl = computed(() =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: props.assessmentId,
    file: props.file.id,
  }),
)

const thumbnailUrl = computed(() =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: props.assessmentId,
    file: props.file.id,
    annex: 'thumbnail',
  }),
)

const viewLabel = computed(() => props.file.menu?.find((item) => item.key === 'view')?.label ?? '')

const statusLabel = computed(() =>
  sessionStore.stateLabel(sessionStore.fileStates, props.file.status, props.file.status_label),
)

const objectUrl = (name: string) =>
  sessionStore.getWsClient().getWsUrl('/file', {
    assessment: props.assessmentId,
    file: props.file.id,
    object: name,
  })

const s3TestId = (name: string) => `file-s3-${name.split('/').join('--')}`

const closeMenu = () => {
  menuOpen.value = false
  directoryOpen.value = false
}

const toggleMenu = () => {
  if (menuOpen.value) {
    closeMenu()
    return
  }
  menuOpen.value = true
}

const onAction = (key: string) => {
  closeMenu()
  emit('action', key)
}

const toggleDirectory = () => {
  directoryOpen.value = !directoryOpen.value
  if (directoryOpen.value) {
    void loadDirectory()
  }
}

const loadDirectory = async () => {
  directoryLoading.value = true
  try {
    const data = await sessionStore
      .getWsClient()
      .queryWs<{ objects?: string[] }>('GET', '/file_annexes', {
        assessment: props.assessmentId,
        file: props.file.id,
        locale: String(locale.value),
      })
    if (!directoryOpen.value) return
    storedFiles.value = (data.objects ?? []).filter(
      (name) => name !== 'content' && !name.includes('/'),
    )
  } catch (err) {
    console.error('Error loading file directory:', err)
    if (directoryOpen.value) {
      storedFiles.value = []
    }
  } finally {
    if (directoryOpen.value) {
      directoryLoading.value = false
    }
  }
}

const closeMenuOnOutside = (event: MouseEvent) => {
  if (!menuOpen.value) return
  const target = event.target
  if (!(target instanceof Node) || !root.value?.contains(target)) {
    closeMenu()
  }
}

onMounted(() => {
  document.addEventListener('click', closeMenuOnOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', closeMenuOnOutside)
})
</script>

<style scoped>
.file-card {
  position: relative;
  width: 11rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.file-card-head {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 0.2rem;
  min-height: 2rem;
}

.file-card-name {
  flex: 1;
  min-width: 0;
  font-size: 0.85rem;
  line-height: 1.25;
  overflow: hidden;
  display: -webkit-box;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  hyphens: auto;
  -webkit-hyphens: auto;
  overflow-wrap: anywhere;
}

.file-card-menu {
  position: relative;
  width: 1.7rem;
  height: 1.7rem;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: var(--text-muted);
  border-radius: var(--radius-sm);
  cursor: pointer;
  padding: 0;
}

.file-card-menu:hover,
.file-card-menu:focus-visible {
  background: var(--hover-bg);
  color: var(--text);
}

.file-card-menu :deep(svg) {
  width: 1.1rem;
  height: 1.1rem;
}

.file-card-dropdown {
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

.file-card-dropdown button {
  display: block;
  width: 100%;
  text-align: left;
  border: none;
  background: transparent;
  color: var(--text);
  font: inherit;
  font-size: 0.9rem;
  padding: 0.4rem 0.55rem;
  border-radius: var(--radius-sm);
  cursor: pointer;
}

.file-card-dropdown button:hover,
.file-card-dropdown button:focus-visible {
  background: var(--hover-bg);
}

.file-card-dropdown button.danger {
  color: var(--danger);
}

.file-card-submenu {
  list-style: none;
  margin: 0.1rem 0 0.15rem 0.55rem;
  padding: 0.1rem 0 0.1rem 0.45rem;
  border-left: 2px solid var(--border);
}

.file-directory-status {
  padding: 0.35rem 0.55rem;
  color: var(--text-muted);
  font-size: 0.85rem;
}

.file-thumb {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 8.5rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--hover-bg);
  overflow: hidden;
  text-decoration: none;
  color: var(--text-muted);
}

.file-thumb img {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.file-thumb-missing {
  font-size: 1.6rem;
  font-weight: 600;
}

.file-thumb .icon-tooltip {
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

.file-thumb:hover .icon-tooltip,
.file-thumb:focus-visible .icon-tooltip {
  opacity: 1;
}

.file-card-status {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  min-height: 1.4rem;
}

.file-status {
  color: var(--info);
  font-size: 0.85rem;
  flex-shrink: 0;
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius-sm);
  background: color-mix(in srgb, var(--info) 15%, transparent);
}
</style>
