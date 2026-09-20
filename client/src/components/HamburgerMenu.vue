<script setup lang="ts">
defineProps<{
  isOpen: boolean
}>()

defineEmits<{
  (e: 'toggle'): void
}>()
</script>

<template>
  <button 
    class="hamburger" 
    :class="{ 'is-open': isOpen }" 
    @click="$emit('toggle')"
    aria-label="Toggle menu"
  >
    <span class="line"></span>
    <span class="line"></span>
    <span class="line"></span>
  </button>
</template>

<style scoped>
.hamburger {
  display: none;
  flex-direction: column;
  justify-content: space-around;
  width: 2rem;
  height: 2rem;
  background: transparent;
  border: none;
  cursor: pointer;
  padding: 0;
  z-index: 10;
}

.hamburger:focus {
  outline: none;
}

.line {
  width: 2rem;
  height: 0.25rem;
  background: var(--text);
  border-radius: 10px;
  transition: all 0.3s linear;
  position: relative;
  transform-origin: 1px;
}

.is-open .line:first-child {
  transform: rotate(45deg);
}

.is-open .line:nth-child(2) {
  opacity: 0;
  transform: translateX(20px);
}

.is-open .line:nth-child(3) {
  transform: rotate(-45deg);
}

@media (max-width: 768px) {
  .hamburger {
    display: flex;
  }
}
</style>
