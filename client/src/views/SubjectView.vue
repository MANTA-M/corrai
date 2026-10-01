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
          <div>
            <h1>{{ t('assessment.subjectPageTitle') }}</h1>
            <p class="subtitle">{{ assessment.name }}</p>
          </div>
          <button type="button" class="button" data-testid="subject-back" @click="goBack">
            {{ t('assessment.back') }}
          </button>
        </div>

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
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import AddFilePopup from '@/components/AddFilePopup.vue'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import InstructionEditorPopup from '@/components/InstructionEditorPopup.vue'
import { useAssessment } from '@/composables/useAssessment'
import type { AssessmentFile, AssessmentStudent } from '@/types/types'

const router = useRouter()
const { t } = useI18n()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()

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

.subtitle {
  margin: 0.35rem 0 0;
  color: var(--text-muted);
  font-size: 0.85rem;
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
