<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'

const { t } = useI18n()
const router = useRouter()
const sessionStore = useSessionStore()
const goToLogin = () => {
  sessionStore.logout()
  router.push('/login')
}
</script>

<template>
  <div class="not-authenticated">
    <div class="not-authenticated-container">
      <h1>{{ $t('notAuthenticated.title') }}</h1>
      <p>{{ $t('notAuthenticated.message') }}</p>
      <button @click.prevent="goToLogin" class="login-button">
        {{ $t('notAuthenticated.goToLogin') }}
      </button>
    </div>
  </div>
</template>

<style scoped>
.not-authenticated {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 1rem;
}

.not-authenticated-container {
  max-width: 400px;
  padding: 2rem;
  text-align: center;
  border-radius: var(--radius-lg);
  background: var(--surface);
  border: 1px solid var(--border);
  box-shadow: var(--shadow-2);
}

h1 {
  margin-bottom: 1rem;
  color: var(--danger);
  font-size: 2rem;
}

p {
  margin-bottom: 2rem;
  color: var(--muted);
  line-height: 1.5;
}

.login-button {
  display: inline-block;
  padding: 0.75rem 1.5rem;
  background-color: var(--accent);
  color: white;
  text-decoration: none;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: background-color 0.2s;
}

.login-button:hover {
  background-color: var(--accent-600);
}
</style>
