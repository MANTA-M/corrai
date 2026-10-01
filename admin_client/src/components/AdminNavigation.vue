<script setup lang="ts">
</script>

<template>
  <header class="header">
    <div class="row">
      <div class="header-left">
        <router-link to="/" class="brand">
          <span class="brand-mark" aria-hidden="true">C<span>✓</span></span>corrai
        </router-link>
        <nav>
          <router-link to="/" class="nav-link">Schools</router-link>
          <router-link to="/monitoring" class="nav-link">Monitoring</router-link>
        </nav>
      </div>
      <div class="header-right">
        <span class="admin-badge">Admin</span>
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

.admin-badge {
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: var(--blue);
  background: var(--pale);
  border: 1px solid var(--line);
  border-radius: 999px;
  padding: 6px 12px;
}
</style>
