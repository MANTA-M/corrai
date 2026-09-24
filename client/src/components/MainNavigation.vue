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
        <router-link to="/exam-list" class="brand" @click="isMenuOpen = false">
          <span class="brand-mark" aria-hidden="true">C<span>✓</span></span>corrai
        </router-link>
        <HamburgerMenu :is-open="isMenuOpen" @toggle="toggleMenu" />
        <nav :class="{ 'is-open': isMenuOpen }">
          <router-link to="/exam-list" class="nav-link" @click="isMenuOpen = false">{{
            $t('nav.exams')
          }}</router-link>
          <router-link to="/settings_page" class="nav-link" @click="isMenuOpen = false">{{
            $t('nav.settings')
          }}</router-link>
          <!-- Mobile menu items -->
          <div class="mobile-menu-items">
            <LocaleSelector class="mobile-locale-selector" />
            <button
              v-if="sessionStore.isAuthenticated"
              @click="handleLogout"
              class="button primary mobile-logout"
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
          class="button primary"
        >
          {{ $t('nav.login') }}
        </router-link>
        <button v-else-if="sessionStore.isAuthenticated" @click="handleLogout" class="button primary">
          {{ $t('nav.logout') }}
        </button>
      </div>
    </div>
  </header>
</template>

<style scoped>
.header {
  position: sticky;
  top: 0;
  z-index: 50;
  background: var(--white);
  border-bottom: 1px solid var(--line);
}

.header-left {
  display: flex;
  align-items: center;
  gap: 28px;
}

.header-right {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-left: auto;
}

nav {
  display: flex;
  align-items: center;
  gap: 28px;
}

.nav-link {
  color: #4c5670;
  font-size: 14px;
  font-weight: 600;
  text-decoration: none;
}

.nav-link:hover,
.nav-link.router-link-active {
  color: var(--blue);
}

@media (max-width: 768px) {
  .header .row {
    flex-wrap: wrap;
  }

  .header-left {
    flex: 1;
    justify-content: space-between;
    width: 100%;
  }

  nav {
    position: fixed;
    top: 72px;
    left: 0;
    right: 0;
    align-items: stretch;
    flex-direction: column;
    gap: 4px;
    background: var(--white);
    padding: 12px 18px 16px;
    border-bottom: 1px solid var(--line);
    box-shadow: var(--shadow-1);
    transform: translateY(-120%);
    opacity: 0;
    visibility: hidden;
    transition: all 0.3s ease-in-out;
    z-index: 40;
  }

  .nav-link {
    padding: 12px 4px;
    font-size: var(--type-body);
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
