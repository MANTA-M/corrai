<template>
  <div class="app">
    <div class="card">
      <div class="header">
        <div>
          <h1>Monitoring</h1>
          <p class="muted">Redis queue tickets waiting for the PHP and Python consumers</p>
        </div>
        <div class="header-actions">
          <button class="button danger" type="button" :disabled="isDeleting" @click="deleteTestData">
            {{ isDeleting ? 'Deleting…' : 'Delete [Test] data' }}
          </button>
          <button class="button" type="button" :disabled="isLoading" @click="loadQueues">
            {{ isLoading ? 'Refreshing…' : 'Refresh' }}
          </button>
        </div>
      </div>

      <p v-if="cleanupMessage" class="cleanup-message">{{ cleanupMessage }}</p>
      <p v-if="cleanupError" class="error cleanup-error">{{ cleanupError }}</p>

      <div v-if="isLoading && !loadedOnce" class="loading">
        <p>Loading queues…</p>
      </div>
      <div v-else-if="error" class="error">
        <p>{{ error }}</p>
      </div>
      <div v-else class="queues">
        <section class="queue-section">
          <div class="section-header">
            <h2>PHP queue</h2>
            <span class="queue-meta">{{ php.key || 'corrai:files' }} · {{ php.items.length }} item{{ php.items.length === 1 ? '' : 's' }}</span>
          </div>
          <div class="queue-list php-list">
            <div class="queue-row queue-head">
              <div>File id</div>
              <div>Name</div>
              <div>Type</div>
              <div>Status</div>
              <div>Content key</div>
            </div>
            <div v-for="(item, index) in php.items" :key="`php-${item.file_id}-${index}`" class="queue-row">
              <div class="mono">{{ item.file_id || '—' }}</div>
              <div>{{ item.name || '—' }}</div>
              <div>{{ item.type || '—' }}</div>
              <div>{{ item.status || '—' }}</div>
              <div class="mono truncate">{{ item.content_key || '—' }}</div>
            </div>
            <p v-if="php.items.length === 0" class="empty-message">PHP queue is empty.</p>
          </div>
        </section>

        <section class="queue-section">
          <div class="section-header">
            <h2>Python OCR queue</h2>
            <span class="queue-meta">{{ python.key || 'corrai:ocr' }} · {{ python.items.length }} item{{ python.items.length === 1 ? '' : 's' }}</span>
          </div>
          <div class="queue-list python-list">
            <div class="queue-row queue-head">
              <div>Path</div>
              <div>Language</div>
              <div>File name</div>
              <div>Status</div>
            </div>
            <div v-for="(item, index) in python.items" :key="`py-${item.path}-${index}`" class="queue-row">
              <div class="mono truncate">{{ item.path || '—' }}</div>
              <div>{{ item.lang || '—' }}</div>
              <div>{{ item.name || '—' }}</div>
              <div>{{ item.status || '—' }}</div>
            </div>
            <p v-if="python.items.length === 0" class="empty-message">Python OCR queue is empty.</p>
          </div>
        </section>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { apiJson } from '@/api'

interface PhpQueueItem {
  file_id: string
  name: string
  type: string
  status: string
  content_key: string
}

interface PythonQueueItem {
  path: string
  lang: string
  file_id: string
  name: string
  type: string
  status: string
}

interface QueuePayload<T> {
  key?: string
  items?: T[]
}

interface QueuesResponse {
  php?: QueuePayload<PhpQueueItem>
  python?: QueuePayload<PythonQueueItem>
}

interface TestDataDeleteResponse {
  schools?: number
  users?: number
  assessments?: number
  students?: number
  errors?: string[]
  message?: string
}

const php = ref<{ key: string; items: PhpQueueItem[] }>({ key: '', items: [] })
const python = ref<{ key: string; items: PythonQueueItem[] }>({ key: '', items: [] })
const isLoading = ref(false)
const isDeleting = ref(false)
const loadedOnce = ref(false)
const error = ref('')
const cleanupMessage = ref('')
const cleanupError = ref('')

const loadQueues = async () => {
  isLoading.value = true
  error.value = ''
  try {
    const data = await apiJson<QueuesResponse>('queues')
    php.value = {
      key: data.php?.key ?? '',
      items: data.php?.items ?? [],
    }
    python.value = {
      key: data.python?.key ?? '',
      items: data.python?.items ?? [],
    }
    loadedOnce.value = true
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Failed to load queues'
  } finally {
    isLoading.value = false
  }
}

const deleteTestData = async () => {
  if (
    !window.confirm(
      'Delete every school, teacher, assessment and student whose name contains [Test]?',
    )
  ) {
    return
  }

  isDeleting.value = true
  cleanupMessage.value = ''
  cleanupError.value = ''
  try {
    const data = await apiJson<TestDataDeleteResponse>('test_data', { method: 'DELETE' })
    const schools = data.schools ?? 0
    const users = data.users ?? 0
    const assessments = data.assessments ?? 0
    const students = data.students ?? 0
    cleanupMessage.value = `Deleted ${schools} school${schools === 1 ? '' : 's'}, ${users} teacher${users === 1 ? '' : 's'}, ${assessments} assessment${assessments === 1 ? '' : 's'}, ${students} student${students === 1 ? '' : 's'}.`
    if (Array.isArray(data.errors) && data.errors.length > 0) {
      cleanupError.value = data.errors.join(' ')
    }
  } catch (err) {
    cleanupError.value = err instanceof Error ? err.message : 'Failed to delete test data'
  } finally {
    isDeleting.value = false
  }
}

onMounted(loadQueues)
</script>

<style scoped>
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1.5rem;
}

.header h1 {
  margin: 0 0 0.5rem 0;
}

.header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem;
}

.cleanup-message {
  margin: -0.5rem 0 1.25rem;
  color: var(--text);
}

.cleanup-error {
  margin: -0.5rem 0 1.25rem;
  text-align: left;
  padding: 0;
}

.muted {
  color: var(--text-muted);
  margin: 0;
}

.loading,
.error,
.empty-message {
  color: var(--text-muted);
  text-align: center;
  padding: 2rem 1rem;
}

.error {
  color: #c93b45;
}

.queues {
  display: flex;
  flex-direction: column;
  gap: 2rem;
}

.section-header {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 1rem;
  margin-bottom: 0.75rem;
}

.section-header h2 {
  margin: 0;
  font-size: 1.25rem;
}

.queue-meta {
  color: var(--text-muted);
  font-size: 0.9rem;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.queue-list {
  border: 1px solid var(--border-color);
  border-radius: 15px;
  overflow: hidden;
  background: var(--white);
}

.queue-row {
  display: grid;
  gap: 0.75rem;
  padding: 0.85rem 1rem;
  align-items: center;
  border-top: 1px solid var(--border-color);
}

.queue-row:first-child {
  border-top: none;
}

.queue-head {
  background: var(--pale);
  font-size: 0.8rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.03em;
  color: var(--muted);
}

.php-list .queue-row {
  grid-template-columns: 120px 1fr 110px 140px minmax(160px, 1.4fr);
}

.python-list .queue-row {
  grid-template-columns: minmax(180px, 1.6fr) 90px 1fr 140px;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 0.85rem;
}

.truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 900px) {
  .php-list .queue-row,
  .python-list .queue-row {
    grid-template-columns: 1fr;
    gap: 0.2rem;
  }

  .queue-head {
    display: none;
  }
}
</style>
