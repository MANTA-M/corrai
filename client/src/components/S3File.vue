<template>
  <a class="s3-file" :href="href" target="_blank" rel="noopener noreferrer" :data-testid="testId">{{
    label
  }}</a>
</template>

<script setup lang="ts">
defineProps<{
  label: string
  href: string
  testId?: string
}>()
</script>

<style scoped>
.s3-file {
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
</style>
