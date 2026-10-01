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

      <div v-else-if="assessment && studentName" class="student-page" data-testid="student-page">
        <div class="header">
          <h1 data-testid="student-name">{{ studentName }}</h1>
          <button type="button" class="button" data-testid="student-back" @click="goBack">
            {{ t('assessment.back') }}
          </button>
        </div>

        <section class="section">
          <h2>{{ t('assessment.files') }}</h2>
          <AssessmentFileList
            :assessment-id="assessment.id || ''"
            :files="studentFiles"
            :students="students"
            :empty-text="t('assessment.studentFilesEmpty')"
            @updated="onFilesUpdated"
          />
        </section>
      </div>

      <div v-else class="error">
        <h1>{{ t('assessment.studentNotFound') }}</h1>
        <button class="button" data-testid="student-back" @click="goBack">{{ t('assessment.back') }}</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import AssessmentFileList from '@/components/AssessmentFileList.vue'
import { useAssessment } from '@/composables/useAssessment'
import type { AssessmentFile, AssessmentStudent } from '@/types/types'
import { isDebugFile } from '@/utils/assessmentFiles'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students, applyUpdate } = useAssessment()

const studentId = computed(() => route.params.studentId as string)

const studentName = computed(() => {
  const known = students.value.find((student) => student.id === studentId.value)
  if (known?.name) return known.name
  const fromFile = files.value.find((file) => (file.student ?? '') === studentId.value)
  return (fromFile?.student_name ?? '').trim()
})

const studentFiles = computed(() =>
  files.value.filter((file) => {
    if ((file.student ?? '') !== studentId.value) return false
    if (isDebugFile(file) && !sessionStore.debugMode) return false
    return true
  })
)

const goBack = () => {
  router.push({ name: 'assessment', params: { id: assessmentId.value } })
}

const onFilesUpdated = (payload: { files: AssessmentFile[]; students?: AssessmentStudent[] }) => {
  applyUpdate(payload.files, payload.students)
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

.section {
  margin-top: 1.5rem;
}

.section h2 {
  margin: 0 0 0.75rem;
  font-size: 1.05rem;
}

.loading,
.error {
  padding: 1rem 0;
}
</style>
