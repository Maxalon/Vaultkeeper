<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import VaultMark from '../components/VaultMark.vue'
import HeroCardWall from '../components/HeroCardWall.vue'

const router = useRouter()
const auth = useAuthStore()

const email = ref('')
const error = ref(null)
const submitting = ref(false)
// Generic confirmation — shown for both success and 422 to avoid leaking
// whether the address belongs to a real account.
const sent = ref(false)

async function onSubmit() {
  error.value = null
  submitting.value = true
  try {
    await auth.forgotPassword({ email: email.value })
    sent.value = true
  } catch (e) {
    if (e?.response?.status === 422 && e.response.data?.errors?.email) {
      // Backend rejected the email *format* — surface that, since it's not
      // an enumeration signal.
      error.value = e.response.data.errors.email[0]
    } else {
      // Any other failure (network, 500, throttle) — never indicates whether
      // the address exists, so fall through to the same confirmation.
      sent.value = true
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

      <form v-if="!sent" class="vk-login-form" @submit.prevent="onSubmit">
        <div class="vk-login-eyebrow">Recover the Vault</div>
        <h1 class="vk-login-title">
          Misplaced your <em>key</em>?
        </h1>
        <p class="vk-login-sub">
          Drop the email tied to your archive and we'll send a reset link. The vault stays sealed until you click through.
        </p>

        <label class="vk-login-input">
          <span>Email</span>
          <input
            id="forgot-email"
            v-model.trim="email"
            type="email"
            autocomplete="email"
            required
          />
        </label>

        <button type="submit" class="vk-login-btn" :disabled="submitting">
          {{ submitting ? 'Sending…' : 'Send reset link →' }}
        </button>

        <p v-if="error" class="vk-login-error">{{ error }}</p>

        <div class="vk-login-meta">
          <a @click.prevent="router.push('/login')">Back to sign in</a>
          <a @click.prevent="router.push('/register')">Create an account</a>
        </div>
      </form>

      <div v-else class="vk-login-form">
        <div class="vk-login-eyebrow">Check your inbox</div>
        <h1 class="vk-login-title">
          The <em>raven</em> is on its way.
        </h1>
        <p class="vk-login-sub">
          If that address is on file, a reset link is on its way. Check your inbox — and the spam folder, just in case. The link expires in an hour.
        </p>

        <div class="vk-login-meta">
          <a @click.prevent="router.push('/login')">Back to sign in</a>
        </div>
      </div>

      <footer class="vk-login-foot">
        <span>VK / v2.4.0</span>
        <span>Est. 2026</span>
      </footer>
    </section>

    <section class="vk-login-hero-side">
      <HeroCardWall />
      <div class="vk-hero-bottom">
        <span class="ticker">AUTH / RECOVERY</span>
      </div>
    </section>
  </div>
</template>
