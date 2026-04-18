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

        <p v-if="error" class="vk-login-error">{{ error }}</p>

        <div class="vk-login-meta">
          <a href="#" @click.prevent>Forgot password?</a>
          <a @click.prevent="router.push('/register')">Create an account</a>
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
