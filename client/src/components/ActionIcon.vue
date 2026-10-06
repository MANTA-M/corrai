<template>
  <svg
    viewBox="0 0 24 24"
    aria-hidden="true"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    stroke-linecap="round"
    stroke-linejoin="round"
  >
    <template v-if="name === 'eye'">
      <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z" />
      <circle cx="12" cy="12" r="3" />
    </template>
    <template v-else-if="name === 'person'">
      <circle cx="12" cy="8" r="3.2" />
      <path d="M5.5 19.5c1.1-3.2 3.2-4.8 6.5-4.8s5.4 1.6 6.5 4.8" />
    </template>
    <template v-else-if="name === 'list'">
      <path d="M9 7h11M9 12h11M9 17h11" />
      <circle cx="5" cy="7" r="1" fill="currentColor" stroke="none" />
      <circle cx="5" cy="12" r="1" fill="currentColor" stroke="none" />
      <circle cx="5" cy="17" r="1" fill="currentColor" stroke="none" />
    </template>
    <template v-else-if="name === 'pencil'">
      <path d="M4 20l4.1-1.1L19.2 7.8a1.8 1.8 0 0 0 0-2.5l-.5-.5a1.8 1.8 0 0 0-2.5 0L5.1 15.9 4 20z" />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M12 5v14M5 12h14" />
    </template>
    <template v-else-if="name === 'caret'">
      <path d="M6 9l6 6 6-6" />
    </template>
    <template v-else>
      <path d="M5 7h14" />
      <path d="M9 7V5h6v2" />
      <path d="M8 7l.8 12h6.4L16 7" />
    </template>
  </svg>
</template>

<script setup lang="ts">
defineProps<{
  name: string
}>()
</script>
