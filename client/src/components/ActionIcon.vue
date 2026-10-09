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
      <path
        d="M4 20l4.1-1.1L19.2 7.8a1.8 1.8 0 0 0 0-2.5l-.5-.5a1.8 1.8 0 0 0-2.5 0L5.1 15.9 4 20z"
      />
    </template>
    <template v-else-if="name === 'plus'">
      <path d="M12 5v14M5 12h14" />
    </template>
    <template v-else-if="name === 'caret'">
      <path d="M6 9l6 6 6-6" />
    </template>
    <template v-else-if="name === 'check'">
      <polyline points="20 6 9 17 4 12" />
    </template>
    <template v-else-if="name === 'file'">
      <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
      <path d="M14 3v5h5" />
    </template>
    <template v-else-if="name === 'braces'">
      <path
        d="M8 4c-2.2 0-3 1.4-3 3.2V9c0 1.1-.5 1.7-1.8 2.2C4.5 11.7 5 12.3 5 13.4v1.6C5 17.2 5.8 18.6 8 18.6"
      />
      <path
        d="M16 4c2.2 0 3 1.4 3 3.2V9c0 1.1.5 1.7 1.8 2.2-1.3.5-1.8 1.1-1.8 2.2v1.6c0 2.2-.8 3.6-3 3.6"
      />
    </template>
    <template v-else-if="name === 'text'">
      <path d="M5 6h14" />
      <path d="M5 12h14" />
      <path d="M5 18h9" />
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
