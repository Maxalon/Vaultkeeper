<script setup>
import { useToast } from '../composables/useToast'

const { toasts, dismiss } = useToast()
</script>

<template>
  <Teleport to="body">
    <div class="toast-host" role="status" aria-live="polite">
      <TransitionGroup name="toast">
        <div
          v-for="t in toasts"
          :key="t.id"
          class="toast"
          :class="`toast--${t.kind}`"
          @click="dismiss(t.id)"
        >
          {{ t.message }}
        </div>
      </TransitionGroup>
    </div>
  </Teleport>
</template>

<style>
.toast-host {
  position: fixed;
  bottom: 1rem;
  right: 1rem;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  z-index: 9999;
  pointer-events: none;
}
.toast {
  min-width: 240px;
  max-width: 420px;
  padding: 0.6rem 0.9rem;
  border-radius: 6px;
  background: var(--vk-surface-raised, #1d1c1a);
  color: var(--vk-fg, #e9e4d6);
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.45);
  font-size: 0.9rem;
  border-left: 3px solid var(--vk-accent, #c99d3d);
  cursor: pointer;
  pointer-events: auto;
}
.toast--success { border-left-color: #5e9a5c; }
.toast--error   { border-left-color: #d15a4a; }
.toast--info    { border-left-color: #5a8bd1; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(20px); }
.toast-enter-active, .toast-leave-active { transition: all .2s ease; }
</style>
