<template>
  <div class="app">
    <div v-if="showLoading" class="loading">
      <p>{{ t('assessment.loading') }}</p>
    </div>

    <div v-else-if="error && !assessment" class="error">
      <h1>{{ t('assessment.error') }}</h1>
      <p>{{ error }}</p>
      <button class="button" @click="goBack">{{ t('assessment.back') }}</button>
    </div>

    <div v-else class="compiled-submission-page" data-testid="compiled-submission-page">
      <div class="header">
        <h1 data-testid="compiled-submission-title">{{ pageTitle }}</h1>
        <button
          type="button"
          class="button"
          data-testid="compiled-submission-back"
          @click="goBack"
        >
          {{ t('assessment.back') }}
        </button>
      </div>

      <p
        v-if="documentError"
        class="error-message"
        data-testid="compiled-submission-error"
      >
        {{ documentError }}
      </p>

      <article
        v-else-if="hasContent"
        class="submission-document"
        data-testid="compiled-submission-document"
      >
        <section
          v-for="(page, index) in pageItems"
          :key="index"
          class="submission-page-card"
          :data-testid="`submission-page-${index + 1}`"
        >
          <h2 class="page-title">{{ page.title || `Page ${index + 1}` }}</h2>
          <div class="page-html-content" v-html="page.html"></div>
        </section>

        <section
          v-if="singleContentHtml"
          class="submission-single-card"
          data-testid="submission-single-content"
        >
          <div class="page-html-content" v-html="singleContentHtml"></div>
        </section>

        <section
          v-for="(section, secIndex) in extraSections"
          :key="secIndex"
          class="submission-section-card"
          :data-testid="`submission-section-${secIndex}`"
        >
          <h2>{{ section.title }}</h2>
          <div class="page-html-content" v-html="section.html"></div>
        </section>
      </article>

      <p
        v-else
        class="empty-message"
        data-testid="compiled-submission-empty"
      >
        {{ t('assessment.compiledSubmissionMissing') }}
      </p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { useAssessment } from '@/composables/useAssessment'

interface SubmissionPageItem {
  title?: string
  html: string
}

interface ExtraSection {
  title: string
  html: string
}

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const sessionStore = useSessionStore()
const { assessment, isLoading, error, assessmentId, files, students } = useAssessment()

const studentId = computed(() =>
  typeof route.params.studentId === 'string' ? route.params.studentId : '',
)
const documentData = ref<unknown>(null)
const documentError = ref('')
const documentLoading = ref(false)

const student = computed(() => students.value.find((item) => item.id === studentId.value) ?? null)

const compiledSubmissionFileId = computed(
  () =>
    files.value.find(
      (file) =>
        (file.student ?? '') === studentId.value &&
        file.name === 'compiled_submission.json',
    )?.id ?? '',
)

const showLoading = computed(() => isLoading.value || documentLoading.value)

const pageTitle = computed(() => {
  const title = t('assessment.compiledSubmission')
  if (student.value?.name) return `${title} — ${student.value.name}`
  if (assessment.value?.name) return `${title} — ${assessment.value.name}`
  return title
})

const goBack = () => {
  if (studentId.value) {
    router.push({
      name: 'assessment-student',
      params: { id: assessmentId.value, studentId: studentId.value },
    })
    return
  }
  router.push({ name: 'assessment', params: { id: assessmentId.value } })
}

let requestId = 0

const loadDocument = async () => {
  if (!assessmentId.value || isLoading.value) return
  const current = ++requestId
  documentLoading.value = true
  documentError.value = ''
  try {
    let url = ''
    if (compiledSubmissionFileId.value) {
      url = sessionStore.getWsClient().getWsUrl('/file', {
        assessment: assessmentId.value,
        file: compiledSubmissionFileId.value,
      })
    } else if (studentId.value) {
      url = sessionStore.getWsClient().getWsUrl('/assessment_object', {
        hash: assessmentId.value,
        object: `students/${studentId.value}/compiled_submission.json`,
      })
    }

    if (!url) {
      documentData.value = null
      documentError.value = t('assessment.compiledSubmissionMissing')
      return
    }

    let response = await fetch(url, { credentials: 'include' })
    if (current !== requestId) return

    // If fetching via /file failed or was missing, attempt /assessment_object
    if (!response.ok && studentId.value && compiledSubmissionFileId.value) {
      const fallbackUrl = sessionStore.getWsClient().getWsUrl('/assessment_object', {
        hash: assessmentId.value,
        object: `students/${studentId.value}/compiled_submission.json`,
      })
      response = await fetch(fallbackUrl, { credentials: 'include' })
      if (current !== requestId) return
    }

    if (!response.ok) {
      documentData.value = null
      documentError.value = t('assessment.compiledSubmissionMissing')
      return
    }

    const rawText = await response.text()
    try {
      documentData.value = JSON.parse(rawText) as unknown
    } catch {
      documentData.value = { pages: [rawText] }
    }
  } catch (err) {
    if (current !== requestId) return
    console.error('Error loading compiled submission:', err)
    documentData.value = null
    documentError.value = t('assessment.compiledSubmissionMissing')
  } finally {
    if (current === requestId) documentLoading.value = false
  }
}

watch(
  [assessmentId, studentId, isLoading, compiledSubmissionFileId],
  () => {
    void loadDocument()
  },
  { immediate: true },
)

const escapeHtml = (value: string) =>
  value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

const inlineMarkdown = (value: string) =>
  escapeHtml(value)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/\*([^*]+)\*/g, '<em>$1</em>')

const markdownToHtml = (source: string): string => {
  const html: string[] = []
  let list: 'ul' | 'ol' | null = null
  let paragraph: string[] = []
  const closeList = () => {
    if (list) {
      html.push(list === 'ul' ? '</ul>' : '</ol>')
      list = null
    }
  }
  const closeParagraph = () => {
    if (paragraph.length === 0) return
    html.push(`<p>${paragraph.join('<br>')}</p>`)
    paragraph = []
  }
  for (const line of source.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n')) {
    const heading = /^(#{1,3})\s+(.*)$/.exec(line)
    const bullet = /^[-*]\s+(.*)$/.exec(line)
    const ordered = /^\d+\.\s+(.*)$/.exec(line)
    if (heading) {
      closeParagraph()
      closeList()
      const level = Math.min(heading[1].length + 2, 6)
      html.push(`<h${level}>${inlineMarkdown(heading[2])}</h${level}>`)
      continue
    }
    if (bullet) {
      closeParagraph()
      if (list !== 'ul') {
        closeList()
        html.push('<ul>')
        list = 'ul'
      }
      html.push(`<li>${inlineMarkdown(bullet[1])}</li>`)
      continue
    }
    if (ordered) {
      closeParagraph()
      if (list !== 'ol') {
        closeList()
        html.push('<ol>')
        list = 'ol'
      }
      html.push(`<li>${inlineMarkdown(ordered[1])}</li>`)
      continue
    }
    if (line.trim() === '') {
      closeList()
      closeParagraph()
      continue
    }
    closeList()
    paragraph.push(inlineMarkdown(line))
  }
  closeList()
  closeParagraph()
  return html.join('')
}

const renderContent = (content: unknown): string => {
  if (content == null) return ''
  if (typeof content !== 'string') {
    return markdownToHtml(JSON.stringify(content, null, 2))
  }
  const str = content.trim()
  if (!str) return ''
  const hasHtml = /<\/?(p|div|h[1-6]|ul|ol|li|table|tr|td|br|blockquote|span|strong|em|pre|code)[^>]*>/i.test(str)
  if (hasHtml) {
    return str
  }
  return markdownToHtml(str)
}

const pageItems = computed<SubmissionPageItem[]>(() => {
  const data = documentData.value
  if (!data) return []

  let rawPages: unknown[] | null = null
  if (Array.isArray(data)) {
    rawPages = data
  } else if (typeof data === 'object' && data !== null && 'pages' in data && Array.isArray((data as { pages: unknown[] }).pages)) {
    rawPages = (data as { pages: unknown[] }).pages
  }

  if (!rawPages) return []

  return rawPages.map((item, index) => {
    if (typeof item === 'string') {
      return {
        title: `Page ${index + 1}`,
        html: renderContent(item),
      }
    }
    if (typeof item === 'object' && item !== null) {
      const pageObj = item as Record<string, unknown>
      const title =
        typeof pageObj.title === 'string'
          ? pageObj.title
          : typeof pageObj.page === 'number' || typeof pageObj.page === 'string'
            ? `Page ${pageObj.page}`
            : `Page ${index + 1}`
      const content =
        typeof pageObj.html === 'string'
          ? pageObj.html
          : typeof pageObj.text === 'string'
            ? pageObj.text
            : typeof pageObj.content === 'string'
              ? pageObj.content
              : JSON.stringify(item, null, 2)
      return {
        title,
        html: renderContent(content),
      }
    }
    return {
      title: `Page ${index + 1}`,
      html: renderContent(String(item)),
    }
  })
})

const singleContentHtml = computed<string>(() => {
  if (pageItems.value.length > 0) return ''
  const data = documentData.value
  if (!data) return ''
  if (typeof data === 'string') {
    return renderContent(data)
  }
  if (typeof data === 'object' && data !== null) {
    const obj = data as Record<string, unknown>
    if (typeof obj.html === 'string') return renderContent(obj.html)
    if (typeof obj.text === 'string') return renderContent(obj.text)
    if (typeof obj.content === 'string') return renderContent(obj.content)
    if (typeof obj.markdown === 'string') return renderContent(obj.markdown)
  }
  return ''
})

const extraSections = computed<ExtraSection[]>(() => {
  if (pageItems.value.length > 0 || singleContentHtml.value) return []
  const data = documentData.value
  if (!data || typeof data !== 'object') return []
  const sections: ExtraSection[] = []
  for (const [key, val] of Object.entries(data as Record<string, unknown>)) {
    if (val == null) continue
    sections.push({
      title: key,
      html: renderContent(val),
    })
  }
  return sections
})

const hasContent = computed(
  () =>
    pageItems.value.length > 0 ||
    Boolean(singleContentHtml.value) ||
    extraSections.value.length > 0,
)
</script>

<style scoped>
.compiled-submission-page {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

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

.submission-document {
  display: flex;
  flex-direction: column;
  gap: 1.5rem;
}

.submission-page-card,
.submission-single-card,
.submission-section-card {
  background: var(--surface, #fff);
  border: 1px solid var(--border, #e2e8f0);
  border-radius: var(--radius-md, 8px);
  padding: 1.5rem;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.page-title {
  margin: 0 0 1rem;
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--text, #1e293b);
  border-bottom: 1px solid var(--border, #e2e8f0);
  padding-bottom: 0.5rem;
}

.page-html-content {
  line-height: 1.6;
  color: var(--text, #1e293b);
  word-break: break-word;
}

.page-html-content :deep(p) {
  margin: 0 0 0.85rem;
}

.page-html-content :deep(p:last-child) {
  margin-bottom: 0;
}

.page-html-content :deep(h1),
.page-html-content :deep(h2),
.page-html-content :deep(h3),
.page-html-content :deep(h4),
.page-html-content :deep(h5),
.page-html-content :deep(h6) {
  margin: 1.25rem 0 0.5rem;
  color: var(--text, #1e293b);
}

.page-html-content :deep(h1:first-child),
.page-html-content :deep(h2:first-child),
.page-html-content :deep(h3:first-child) {
  margin-top: 0;
}

.page-html-content :deep(ul),
.page-html-content :deep(ol) {
  margin: 0.5rem 0 0.85rem;
  padding-left: 1.5rem;
}

.page-html-content :deep(li) {
  margin-bottom: 0.25rem;
}

.page-html-content :deep(code) {
  background: var(--code-bg, #f1f5f9);
  padding: 0.15rem 0.35rem;
  border-radius: 4px;
  font-size: 0.9em;
}

.page-html-content :deep(pre) {
  background: var(--code-bg, #f1f5f9);
  padding: 1rem;
  border-radius: var(--radius-sm, 6px);
  overflow-x: auto;
  margin: 0.75rem 0;
}

.empty-message {
  color: var(--text-muted, #64748b);
  font-style: italic;
  padding: 1rem 0;
}
</style>
