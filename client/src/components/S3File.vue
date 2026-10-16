<template>
  <div class="s3-file-row">
    <a
      class="s3-file"
      :href="href"
      target="_blank"
      rel="noopener noreferrer"
      :data-testid="testId"
      >{{ label }}</a
    >
    <button
      v-if="deletable"
      type="button"
      class="s3-file-delete"
      :data-testid="deleteTestId"
      :aria-label="deleteLabel"
      :title="deleteLabel"
      :disabled="deleting"
      @click.stop.prevent="emit('delete')"
    >
      <ActionIcon name="trash" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionIcon from '@/components/ActionIcon.vue'

const props = defineProps<{
  label: string
  href: string
  testId?: string
  deletable?: boolean
  deleting?: boolean
}>()

const emit = defineEmits<{
  delete: []
}>()

const { t } = useI18n()

const deleteLabel = computed(() => t('common.delete'))
const deleteTestId = computed(() =>
  props.testId ? `${props.testId}-delete` : 's3-file-delete',
)
</script>

<style scoped>
.s3-file-row {
  display: flex;
  align-items: flex-start;
  gap: 0.15rem;
}

.s3-file {
  flex: 1;
  min-width: 0;
  display: block;
  padding: 0.4rem 0.55rem;
  border-radius: var(--radius-sm);
  color: var(--link);
  font-size: 0.9rem;
  line-height: 1.3;
  text-decoration: none;
  overflow-wrap: anywhere;
}

.s3-file:hover,
.s3-file:focus-visible {
  background: var(--hover-bg);
  color: var(--link-hover);
}

.s3-file-delete {
  flex-shrink: 0;
  width: 1.7rem;
  height: 1.7rem;
  margin-top: 0.15rem;
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

.s3-file-delete:hover:not(:disabled),
.s3-file-delete:focus-visible:not(:disabled) {
  background: var(--hover-bg);
  color: var(--danger);
}

.s3-file-delete:disabled {
  opacity: 0.5;
  cursor: default;
}

.s3-file-delete :deep(svg) {
  width: 1rem;
  height: 1rem;
}
</style>
