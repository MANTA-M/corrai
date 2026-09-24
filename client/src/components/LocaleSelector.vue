<template>
  <div class="locale-selector">
    <label for="locale-select">{{ $t('language') }}:</label>
    <select id="locale-select" :value="locale" @change="handleLocaleChange">
      <option v-for="theloc in availableLocales" :key="theloc.code" :value="theloc.code">
        {{ theloc.name }}
      </option>
    </select>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { loadLanguage, type AvailableLocale } from '@/i18n'
import { useSessionStore } from '@/stores/session'

const { t, locale } = useI18n()
const sessionStore = useSessionStore()

const availableLocales = computed(() => {
  return [
    { code: 'en', name: 'English' },
    { code: 'fr', name: 'Français' },
    { code: 'ru', name: 'Русский' },
    { code: 'uk', name: 'Українська' },
    { code: 'es', name: 'Español' },
    { code: 'pt', name: 'Português' },
    { code: 'ro', name: 'Română' },
    { code: 'de', name: 'Deutsch' },
  ]
})

const handleLocaleChange = async (event: Event) => {
  const target = event.target as HTMLSelectElement
  const newLocale = target.value as AvailableLocale

  await loadLanguage(newLocale)
  sessionStore.setLocale(newLocale)
}
</script>

<style scoped>
.locale-selector {
  display: flex;
  align-items: center;
  gap: 8px;
}

label {
  font-weight: 600;
  font-size: var(--type-label);
  color: #4c5670;
  white-space: nowrap;
}

select {
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: var(--radius-md);
  font-size: 0.875rem;
  background-color: var(--surface);
  color: var(--text);
  transition: border-color 0.15s ease;
}

select:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 25%, transparent);
}
</style>
