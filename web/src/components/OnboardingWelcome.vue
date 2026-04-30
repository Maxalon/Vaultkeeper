<script setup>
import { ref } from 'vue'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const submitting = ref(false)

async function dismiss() {
  if (submitting.value) return
  submitting.value = true
  try {
    await auth.completeOnboarding()
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <div v-if="auth.isAuthenticated && auth.needsOnboarding" class="onboarding-backdrop">
      <div class="onboarding-modal" role="dialog" aria-modal="true" aria-labelledby="onboarding-title">
        <h2 id="onboarding-title" class="onboarding-title">Welcome to Vaultkeeper</h2>
        <p class="onboarding-body">
          Track your collection, build decks, and keep tabs on what's where —
          all from one place.
        </p>
        <p class="onboarding-body onboarding-body--muted">
          You can always come back to settings to import an existing collection
          or tweak how things look.
        </p>
        <div class="onboarding-actions">
          <button
            type="button"
            class="onboarding-btn"
            :disabled="submitting"
            @click="dismiss"
          >
            {{ submitting ? 'Just a moment…' : 'Get started' }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<style scoped>
.onboarding-backdrop {
  position: fixed; inset: 0;
  background: rgba(0, 0, 0, 0.7);
  display: grid; place-items: center;
  z-index: 9999;
}
.onboarding-modal {
  background: var(--bg-2, #1d1c1a);
  color: var(--ink-90, #e9e4d6);
  border: 1px solid var(--hairline, #33312c);
  padding: 1.75rem 2rem;
  border-radius: 6px;
  min-width: 360px;
  max-width: 520px;
  box-shadow: 0 24px 80px rgba(0, 0, 0, 0.7);
}
.onboarding-title {
  margin: 0 0 0.85rem;
  font-family: 'Newsreader', serif;
  font-weight: 500;
  font-size: 1.5rem;
}
.onboarding-body {
  margin: 0 0 0.85rem;
  font-size: 0.95rem;
  line-height: 1.5;
}
.onboarding-body--muted { color: var(--ink-70, #a8a396); }
.onboarding-actions {
  display: flex;
  justify-content: flex-end;
  margin-top: 1.5rem;
}
.onboarding-btn {
  padding: 0.55rem 1.1rem;
  border: 1px solid var(--hairline, #33312c);
  background: var(--bg-3, #26241f);
  color: inherit;
  border-radius: 4px;
  cursor: pointer;
  font-size: 0.9rem;
}
.onboarding-btn:hover:not(:disabled) { background: var(--bg-4, #322f29); }
.onboarding-btn:disabled { opacity: 0.6; cursor: progress; }
</style>
