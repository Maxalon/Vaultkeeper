<script setup>
import { useToast } from '../composables/useToast'

const { toasts, dismiss, runAction } = useToast()

function onToastClick(t) {
  // Toasts with actions need the body click for nothing — actions
  // fire from their own buttons, and clicks elsewhere shouldn't
  // accidentally dismiss the user's still-readable prompt.
  if (t.actions && t.actions.length) return
  dismiss(t.id)
}
</script>

<template>
  <Teleport to="body">
    <div class="toast-host" role="status" aria-live="polite">
      <TransitionGroup name="toast">
        <div
          v-for="t in toasts"
          :key="t.id"
          class="toast"
          :class="[`toast--${t.kind}`, { 'toast--with-actions': t.actions && t.actions.length }]"
          @click="onToastClick(t)"
        >
          <div class="toast-message">{{ t.message }}</div>
          <div v-if="t.actions && t.actions.length" class="toast-actions" @click.stop>
            <button
              v-for="a in t.actions"
              :key="a.label"
              type="button"
              class="toast-action"
              :class="{ 'toast-action--primary': a.kind === 'primary' }"
              @click="runAction(t.id, a)"
            >{{ a.label }}</button>
            <button
              type="button"
              class="toast-action toast-action--dismiss"
              @click="dismiss(t.id)"
            >Dismiss</button>
          </div>
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
  background: var(--bg-2, #1d1c1a);
  color: var(--ink-90, #e9e4d6);
  box-shadow: 0 8px 30px rgba(0, 0, 0, 0.45);
  font-size: 0.9rem;
  border-left: 3px solid var(--amber, #c99d3d);
  cursor: pointer;
  pointer-events: auto;
}
.toast--success { border-left-color: #5e9a5c; }
.toast--error   { border-left-color: #d15a4a; }
.toast--info    { border-left-color: #5a8bd1; }
.toast--with-actions {
  cursor: default;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}
.toast-message { line-height: 1.3; }
.toast-actions {
  display: flex;
  gap: 0.4rem;
  flex-wrap: wrap;
}
.toast-action {
  background: transparent;
  color: var(--ink-90, #e9e4d6);
  border: 1px solid var(--hairline, #33312c);
  border-radius: 3px;
  padding: 0.25rem 0.6rem;
  font-size: 0.78rem;
  cursor: pointer;
  font: inherit;
}
.toast-action:hover { border-color: var(--amber, #c99d3d); }
.toast-action--primary {
  background: var(--amber, #c99d3d);
  color: #1a120c;
  border-color: var(--amber, #c99d3d);
}
.toast-action--dismiss {
  margin-left: auto;
  color: var(--ink-50, #8a857a);
}
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateX(20px); }
.toast-enter-active, .toast-leave-active { transition: all .2s ease; }
</style>
