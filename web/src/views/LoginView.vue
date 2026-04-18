<script setup>
import { nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import VaultMark from '../components/VaultMark.vue'
import HeroCardWall from '../components/HeroCardWall.vue'

const router = useRouter()
const auth = useAuthStore()

const username = ref('')
const password = ref('')
const error = ref(null)
const submitting = ref(false)

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await auth.login({ username: username.value, password: password.value })
    await nextTick()
    router.push('/collection')
  } catch (e) {
    console.error('Login flow failed:', e)
    error.value = e?.response?.data?.message || 'Login failed'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="vk-login">
    <section class="vk-login-form-side">
      <VaultMark />

      <form class="vk-login-form" @submit.prevent="onSubmit">
        <div class="vk-login-eyebrow">The Collector's Archive</div>
        <h1 class="vk-login-title">
          Keep the <em>vault</em> behind the glass.
        </h1>
        <p class="vk-login-sub">
          Catalog, value, and retrieve thousands of cards across every set you've ever sleeved — organized the way a collector thinks.
        </p>

        <label class="vk-login-input">
          <span>Username or Email</span>
          <input
            id="login-username"
            v-model="username"
            type="text"
            autocomplete="username"
            required
          />
        </label>
        <label class="vk-login-input">
          <span>Password</span>
          <input
            id="login-password"
            v-model="password"
            type="password"
            autocomplete="current-password"
            required
          />
        </label>

        <button type="submit" class="vk-login-btn" :disabled="submitting">
          {{ submitting ? 'Signing in…' : 'Enter the Vault →' }}
        </button>

        <p v-if="error" class="error">{{ error }}</p>

        <div class="vk-login-meta">
          <a href="#" @click.prevent>Forgot password?</a>
          <a href="#" @click.prevent>Create an account</a>
        </div>
      </form>

      <footer class="vk-login-foot">
        <span>VK / v2.4.0</span>
        <span>Est. 2026</span>
      </footer>
    </section>

    <section class="vk-login-hero-side">
      <HeroCardWall />
      <div class="vk-hero-bottom">
        <span class="ticker">AUTH / SECURE SESSION</span>
      </div>
    </section>
  </div>
</template>

<style scoped>
.vk-login {
  position: fixed;
  inset: 0;
  display: grid;
  grid-template-columns: 1fr 1.2fr;
  background: var(--vk-bg-0);
}

.vk-login-form-side {
  padding: 56px 64px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  position: relative;
  z-index: 2;
  border-right: 1px solid var(--vk-line);
  overflow-y: auto;
}

.vk-login-form {
  max-width: 360px;
  width: 100%;
  display: flex;
  flex-direction: column;
  margin-top: 48px;
}

.vk-login-eyebrow {
  font-size: 10px;
  letter-spacing: 0.24em;
  text-transform: uppercase;
  color: var(--vk-gold);
  font-weight: 600;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.vk-login-eyebrow::before {
  content: '';
  width: 18px;
  height: 1px;
  background: var(--vk-gold);
}

.vk-login-title {
  font-family: var(--font-display);
  font-size: 44px;
  font-weight: 400;
  line-height: 1.05;
  letter-spacing: -0.02em;
  color: var(--vk-ink-1);
  margin: 0 0 12px;
}
.vk-login-title em {
  font-style: italic;
  color: var(--vk-gold);
}

.vk-login-sub {
  font-size: 14px;
  line-height: 1.5;
  color: var(--vk-ink-2);
  margin: 0 0 32px;
  max-width: 320px;
}

.vk-login-input {
  display: block;
  margin-bottom: 14px;
}
.vk-login-input span {
  display: block;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--vk-ink-3);
  margin-bottom: 6px;
}
.vk-login-input input {
  width: 100%;
  height: 44px;
  padding: 0 14px;
  background: var(--vk-bg-1);
  border: 1px solid var(--vk-line);
  border-radius: var(--radius-sm);
  color: var(--vk-ink-1);
  font-size: 14px;
  outline: 0;
  transition: border-color 0.12s ease;
}
.vk-login-input input:focus {
  border-color: var(--vk-gold-dim);
}

.vk-login-btn {
  width: 100%;
  height: 48px;
  background: var(--vk-gold);
  color: #1a1408;
  border: 0;
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  border-radius: var(--radius-sm);
  margin-top: 8px;
  cursor: pointer;
  transition: all 0.12s ease;
}
.vk-login-btn:hover:not(:disabled) {
  background: color-mix(in oklab, var(--vk-gold) 82%, white);
  transform: translateY(-1px);
  box-shadow: 0 6px 18px rgba(240, 195, 92, 0.25);
}
.vk-login-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error {
  color: #d46a6a;
  font-size: 12px;
  margin: 12px 0 0;
}

.vk-login-meta {
  margin-top: 24px;
  display: flex;
  justify-content: space-between;
  font-size: 12px;
  color: var(--vk-ink-3);
}
.vk-login-meta a {
  color: var(--vk-ink-2);
  text-decoration: none;
  border-bottom: 1px dotted var(--vk-ink-4);
}
.vk-login-meta a:hover {
  color: var(--vk-gold);
  border-color: var(--vk-gold);
}

.vk-login-foot {
  font-family: var(--font-mono);
  font-size: 10px;
  color: var(--vk-ink-4);
  letter-spacing: 0.08em;
  display: flex;
  justify-content: space-between;
  margin-top: 32px;
}

/* ── Hero side ──────────────────────────────────────────────────────── */

.vk-login-hero-side {
  position: relative;
  overflow: hidden;
  background: var(--vk-bg-1);
  background-image:
    radial-gradient(ellipse at 70% 30%, rgba(240, 195, 92, 0.12), transparent 50%),
    radial-gradient(ellipse at 30% 80%, rgba(93, 58, 110, 0.15), transparent 50%);
}

.vk-hero-bottom {
  position: absolute;
  bottom: 32px;
  right: 32px;
  z-index: 4;
  font-family: var(--font-mono);
  font-size: 10px;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  color: var(--vk-ink-3);
}

@media (max-width: 900px) {
  .vk-login {
    grid-template-columns: 1fr;
  }
  .vk-login-hero-side {
    display: none;
  }
}
</style>
