<script setup lang="ts">
import HamburgerMenu from './HamburgerMenu.vue'
import { useSessionStore } from '@/stores/session'
import { useRoute } from 'vue-router'
import { computed, ref } from 'vue'

const sessionStore = useSessionStore()
const route = useRoute()
const isMenuOpen = ref(false)

const toggleMenu = () => {
  isMenuOpen.value = !isMenuOpen.value
}

const isMinimalHeader = computed(() => {
  return (
    route.meta.minimalHeader === true ||
    route.name === 'assessment-correction-grid' ||
    route.name === 'student-correction-grid'
  )
})
</script>

<template>
  <header class="header">
    <div class="row">
      <template v-if="isMinimalHeader">
        <router-link to="/assessment-list" class="brand">
          <span class="brand-mark" aria-hidden="true">C<span>✓</span></span
          >corrai
        </router-link>
      </template>
      <template v-else>
        <div class="header-left" v-if="sessionStore.isAuthenticated">
          <router-link to="/assessment-list" class="brand" @click="isMenuOpen = false">
            <span class="brand-mark" aria-hidden="true">C<span>✓</span></span
            >corrai
          </router-link>
          <HamburgerMenu :is-open="isMenuOpen" @toggle="toggleMenu" />
          <nav :class="{ 'is-open': isMenuOpen }">
            <router-link to="/assessment-list" class="nav-link" @click="isMenuOpen = false">{{
              $t('nav.assessments')
            }}</router-link>
            <!-- Mobile menu items -->
            <div class="mobile-menu-items">
              <router-link
                v-if="sessionStore.isAuthenticated"
                to="/settings_page"
                class="account-button"
                @click="isMenuOpen = false"
              >
                <span v-if="sessionStore.user_name" class="account-name">{{
                  sessionStore.user_name
                }}</span>
                <span class="account-label">{{ $t('nav.myAccount') }}</span>
              </router-link>
            </div>
          </nav>
        </div>
        <div class="header-right desktop-only">
          <router-link
            v-if="!sessionStore.isAuthenticated && route.name !== 'login'"
            to="/login"
            class="button primary"
          >
            {{ $t('nav.login') }}
          </router-link>
          <router-link
            v-else-if="sessionStore.isAuthenticated"
            to="/settings_page"
            class="account-button"
          >
            <span v-if="sessionStore.user_name" class="account-name">{{
              sessionStore.user_name
            }}</span>
            <span class="account-label">{{ $t('nav.myAccount') }}</span>
          </router-link>
        </div>
      </template>
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

  .mobile-menu-items .account-button {
    width: 100%;
  }
}

@media (min-width: 768px) {
  .mobile-menu-items {
    display: none !important;
  }
}

.account-button {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  justify-content: center;
  gap: 2px;
  padding: 6px 14px;
  border: 1px solid var(--line);
  border-radius: var(--radius-md);
  background: var(--white);
  color: var(--ink);
  text-decoration: none;
  line-height: 1.2;
}

.account-button:hover,
.account-button.router-link-active {
  background: var(--pale);
  border-color: var(--hover-border);
  text-decoration: none;
}

.account-name {
  font-size: 14px;
  font-weight: 700;
  color: var(--ink);
}

.account-label {
  font-size: 12px;
  font-weight: 500;
  color: #4c5670;
}
</style>
