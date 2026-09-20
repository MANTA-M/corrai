<script setup lang="ts">
import LocaleSelector from './LocaleSelector.vue'
import HamburgerMenu from './HamburgerMenu.vue'
import { useI18n } from 'vue-i18n'
import { useSessionStore } from '@/stores/session'
import { useRouter, useRoute } from 'vue-router'
import { ref } from 'vue'

const { t } = useI18n()
const sessionStore = useSessionStore()
const router = useRouter()
const route = useRoute()
const isMenuOpen = ref(false)

const handleLogout = () => {
  sessionStore.clearSession()
  router.push('/login')
}

const toggleMenu = () => {
  isMenuOpen.value = !isMenuOpen.value
}

</script>

<template>
  <header class="header">
    <div class="row">
      <div class="header-left" v-if="sessionStore.isAuthenticated">
        <div class="site-title">{{ $t('title') }}</div>
        <HamburgerMenu :is-open="isMenuOpen" @toggle="toggleMenu" />
        <nav :class="{ 'is-open': isMenuOpen }">
          <router-link to="/exam-list" class="button" @click="isMenuOpen = false">{{
            $t('nav.exams')
          }}</router-link>
          <router-link to="/settings_page" class="button" @click="isMenuOpen = false">{{
            $t('nav.settings')
          }}</router-link>
          <!-- Mobile menu items -->
          <div class="mobile-menu-items">
            <LocaleSelector class="mobile-locale-selector" />
            <button
              v-if="sessionStore.isAuthenticated"
              @click="handleLogout"
              class="button mobile-logout"
            >
              {{ $t('nav.logout') }}
            </button>
          </div>
        </nav>
      </div>
      <div class="header-right desktop-only">
        <LocaleSelector />
        <router-link
          v-if="!sessionStore.isAuthenticated && route.name !== 'login'"
          to="/login"
          class="button"
        >
          {{ $t('nav.login') }}
        </router-link>
        <button v-else-if="sessionStore.isAuthenticated" @click="handleLogout" class="button">
          {{ $t('nav.logout') }}
        </button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.header-left {
  display: flex;
  align-items: center;
  gap: 16px;
}

.site-title {
  font-size: 1.25rem;
  font-weight: 600;
  white-space: nowrap;
  color: var(--accent);
}

.header-right {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-left: auto;
}

nav {
  display: flex;
  gap: 8px;
}

@media (max-width: 768px) {
  .header .row {
    flex-wrap: wrap;
    margin: 0 1em;
  }

  .header-left {
    flex: 1;
    justify-content: space-between;
    width: 100%;
  }

  nav {
    position: fixed;
    top: 60px;
    left: 0;
    right: 0;
    flex-direction: column;
    background: var(--surface);
    padding: 1rem;
    border-bottom: 1px solid var(--border);
    transform: translateY(-100%);
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease-in-out;
    z-index: 40;
  }

  nav.is-open {
    transform: translateY(0);
    opacity: 1;
    visibility: visible;
  }

  .desktop-only {
    display: none !important;
  }

  .mobile-menu-items {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .mobile-locale-selector {
    width: 100%;
  }

  .mobile-locale-selector :deep(.locale-selector) {
    width: 100%;
  }

  .mobile-locale-selector :deep(select) {
    width: 100%;
  }

  .mobile-logout {
    width: 100%;
  }
}

@media (min-width: 768px) {
  .mobile-menu-items {
    display: none !important;
  }
}
</style>
