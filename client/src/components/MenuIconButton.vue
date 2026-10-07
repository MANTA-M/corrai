<template>
  <a
    v-if="href"
    class="icon-button"
    :class="{ danger: isDanger }"
    :href="href"
    target="_blank"
    rel="noopener noreferrer"
    :data-testid="testId"
    :aria-label="item.label"
    :aria-expanded="ariaExpanded"
    :title="item.label"
  >
    <ActionIcon :name="item.icon" />
    <span class="icon-tooltip" role="tooltip" aria-hidden="true">{{ item.label }}</span>
  </a>
  <button
    v-else
    type="button"
    class="icon-button"
    :class="{ danger: isDanger }"
    :data-testid="testId"
    :aria-label="item.label"
    :aria-expanded="ariaExpanded"
    :title="item.label"
    @click="emit('click')"
  >
    <ActionIcon :name="item.icon" />
    <span class="icon-tooltip" role="tooltip" aria-hidden="true">{{ item.label }}</span>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import ActionIcon from '@/components/ActionIcon.vue'
import type { MenuItem } from '@/types/types'

const props = defineProps<{
  item: MenuItem
  testId: string
  href?: string
  ariaExpanded?: boolean
}>()

const emit = defineEmits<{
  click: []
}>()

const isDanger = computed(() => props.item.color === '#c93b45')
</script>

<style scoped>
.icon-button {
  position: relative;
  width: 2rem;
  height: 2rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: none;
  background: transparent;
  color: var(--text-muted);
  border-radius: var(--radius-sm);
  cursor: pointer;
  padding: 0;
  text-decoration: none;
}

.icon-button:hover,
.icon-button:focus-visible {
  background: var(--hover-bg);
  color: var(--text);
}

.icon-button.danger:hover,
.icon-button.danger:focus-visible {
  color: var(--danger);
}

.icon-button :deep(svg) {
  width: 1.15rem;
  height: 1.15rem;
}

.icon-tooltip {
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

.icon-button:hover .icon-tooltip,
.icon-button:focus-visible .icon-tooltip {
  opacity: 1;
}
</style>
