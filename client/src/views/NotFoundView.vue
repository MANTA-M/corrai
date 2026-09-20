<template>
  <div class="app">
    <div class="card" style="text-align: center; max-width: 500px; margin: 4rem auto">
      <div>
        <h1 style="font-size: 3.75rem; margin-bottom: 16px; color: var(--muted);">404</h1>
        <h2>{{ $t('notFound.title') }}</h2>
        <p class="muted" style="margin-bottom: 24px;">{{ $t('notFound.message') }}</p>

        <div style="display: flex; gap: 16px; justify-content: center;">
          <router-link to="/" class="button primary">
            {{ $t('notFound.goHome') }}
          </router-link>
          <button @click="$router.go(-1)" class="button">
            {{ $t('notFound.goBack') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>
