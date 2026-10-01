<script setup lang="ts">
import AdminNavigation from './components/AdminNavigation.vue'
</script>

<template>
  <div class="shell">
    <AdminNavigation />
    <main class="page">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.shell {
  min-height: 100%;
}

.page {
  min-height: calc(100vh - 72px);
}
</style>
