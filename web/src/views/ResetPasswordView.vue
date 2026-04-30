<script setup>
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import VaultMark from '../components/VaultMark.vue'
import HeroCardWall from '../components/HeroCardWall.vue'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const token = String(route.query.token ?? '')
const email = String(route.query.email ?? '')
const linkValid = !!token && !!email

const password = ref('')
const passwordConfirm = ref('')
const error = ref(null)
const fieldErrors = ref({})
const submitting = ref(false)
const done = ref(false)

// Live mismatch hint — only after the user has touched the confirm field.
const passwordsMatch = computed(
  () => passwordConfirm.value === '' || passwordConfirm.value === password.value,
)

async function onSubmit() {
  error.value = null
  fieldErrors.value = {}

  if (!passwordsMatch.value) {
    error.value = 'Passwords do not match.'
    return
  }
  if (password.value.length < 8) {
    error.value = 'Password must be at least 8 characters.'
    return
  }

  submitting.value = true
  try {
    await auth.resetPassword({
      token,
      email,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    })
    done.value = true
    // Brief beat so the user sees the confirmation, then back to sign-in to
    // log in fresh with the new credential.
    setTimeout(() => router.push('/login'), 1500)
  } catch (e) {
    const resp = e?.response?.data
    if (resp?.errors) {
      fieldErrors.value = Object.fromEntries(
        Object.entries(resp.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]),
      )
      error.value = resp.message || 'This reset link is invalid or has expired.'
    } else {
      error.value = resp?.message || 'Reset failed.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="vk-login">
    <section class="vk-login-form-side">
      <VaultMark />

      <div v-if="!linkValid" class="vk-login-form">
        <div class="vk-login-eyebrow">Broken seal</div>
        <h1 class="vk-login-title">
          This <em>link</em> is incomplete.
        </h1>
        <p class="vk-login-sub">
          The reset link is missing its token or email. Request a fresh one and we'll send a new raven.
        </p>
        <div class="vk-login-meta">
          <a @click.prevent="router.push('/forgot-password')">Request a new link</a>
          <a @click.prevent="router.push('/login')">Back to sign in</a>
        </div>
      </div>

      <form v-else-if="!done" class="vk-login-form" novalidate @submit.prevent="onSubmit">
        <div class="vk-login-eyebrow">Set a new key</div>
        <h1 class="vk-login-title">
          Reseal the <em>vault</em>.
        </h1>
        <p class="vk-login-sub">
          One-time link for <strong>{{ email }}</strong>. Pick a new password and you're back inside.
        </p>

        <label class="vk-login-input">
          <span>New Password</span>
          <input
            id="reset-password"
            v-model="password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            :class="{ invalid: !!fieldErrors.password }"
          />
          <span v-if="fieldErrors.password" class="field-hint error">
            {{ fieldErrors.password }}
          </span>
          <span v-else class="field-hint">At least 8 characters.</span>
        </label>

        <label class="vk-login-input">
          <span>Confirm Password</span>
          <input
            id="reset-password-confirm"
            v-model="passwordConfirm"
            type="password"
            autocomplete="new-password"
            minlength="8"
            required
            :class="{ invalid: !passwordsMatch }"
          />
          <span v-if="!passwordsMatch" class="field-hint error">
            Passwords don't match.
          </span>
        </label>

        <button type="submit" class="vk-login-btn" :disabled="submitting">
          {{ submitting ? 'Updating…' : 'Reseal the Vault →' }}
        </button>

        <p v-if="error" class="vk-login-error">
          {{ fieldErrors.email || error }}
        </p>

        <div class="vk-login-meta">
          <a @click.prevent="router.push('/login')">Back to sign in</a>
        </div>
      </form>

      <div v-else class="vk-login-form">
        <div class="vk-login-eyebrow">Vault resealed</div>
        <h1 class="vk-login-title">
          Welcome <em>back</em>, collector.
        </h1>
        <p class="vk-login-sub">
          Password updated. Sending you back to sign in…
        </p>
      </div>

      <footer class="vk-login-foot">
        <span>VK / v2.4.0</span>
        <span>Est. 2026</span>
      </footer>
    </section>

    <section class="vk-login-hero-side">
      <HeroCardWall />
      <div class="vk-hero-bottom">
        <span class="ticker">AUTH / NEW KEY</span>
      </div>
    </section>
  </div>
</template>
