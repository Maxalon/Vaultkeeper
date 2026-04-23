<script setup>
import { useConfirmState, _resolveConfirm } from '../composables/useConfirm'

const state = useConfirmState()
</script>

<template>
  <Teleport to="body">
    <div v-if="state.open" class="confirm-backdrop" @click.self="_resolveConfirm(false)">
      <div class="confirm-modal" role="dialog" aria-modal="true">
        <h3 v-if="state.title" class="confirm-title">{{ state.title }}</h3>
        <p class="confirm-message">{{ state.message }}</p>
        <div class="confirm-actions">
          <button type="button" class="confirm-btn" @click="_resolveConfirm(false)">
            {{ state.cancelText }}
          </button>
          <button
            type="button"
            class="confirm-btn"
            :class="{ 'confirm-btn--danger': state.destructive }"
            @click="_resolveConfirm(true)"
          >
            {{ state.confirmText }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style>
.confirm-backdrop {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.6);
  display: grid; place-items: center;
  z-index: 9998;
}
.confirm-modal {
  background: var(--bg-2, #1d1c1a);
  color: var(--ink-90, #e9e4d6);
  border: 1px solid var(--hairline, #33312c);
  padding: 1.25rem 1.5rem;
  border-radius: 6px;
  min-width: 320px;
  max-width: 520px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.6);
}
.confirm-title { margin: 0 0 0.5rem; font-size: 1.05rem; }
.confirm-message { margin: 0 0 1.25rem; color: var(--ink-70, #a8a396); }
.confirm-actions { display: flex; justify-content: flex-end; gap: 0.5rem; }
.confirm-btn {
  padding: 0.45rem 0.9rem;
  border: 1px solid var(--hairline, #33312c);
  background: transparent;
  color: inherit;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.85rem;
}
.confirm-btn:hover { background: var(--bg-2, #26241f); }
.confirm-btn--danger {
  background: #8e3c31;
  border-color: #a14739;
  color: #f5eadf;
}
.confirm-btn--danger:hover { background: #a14739; }
</style>
