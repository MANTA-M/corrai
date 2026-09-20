<template>
  <div class="app"></div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useSessionStore } from '@/stores/session'
import { onMounted } from 'vue'

const { t } = useI18n()
const router = useRouter()
const sessionStore = useSessionStore()

// Redirect to meetings if user is authenticated
onMounted(() => {
  if (sessionStore.isAuthenticated) {
    router.push('/meetings')
  }
})
</script>
