<script setup>
import { computed, nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import VaultMark from '../components/VaultMark.vue'
import HeroCardWall from '../components/HeroCardWall.vue'

const router = useRouter()
const auth = useAuthStore()

const username = ref('')
const email = ref('')
const password = ref('')
const passwordConfirm = ref('')
const error = ref(null)
const fieldErrors = ref({})
const submitting = ref(false)
const submitted = ref(false)

// Live mismatch hint — only shown after the user has touched the confirm
// field, so the form doesn't yell at them while they're still typing.
const passwordsMatch = computed(
  () => passwordConfirm.value === '' || passwordConfirm.value === password.value,
)

// ── Email format + typo detection ──────────────────────────────────────
// Browser `type=email` is permissive; this catches the same things the
// backend will reject (no @, no TLD, etc.) so the user gets feedback
// before the network round-trip.
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

// Domains that account for ~95% of typo'd inboxes. Each maps to its
// canonical form. Add more as we see them.
const COMMON_DOMAINS = {
  // gmail
  'gmial.com': 'gmail.com', 'gmai.com': 'gmail.com', 'gnail.com': 'gmail.com',
  'gmali.com': 'gmail.com', 'gmail.co': 'gmail.com', 'gmail.cm': 'gmail.com',
  'gmaill.com': 'gmail.com', 'gemail.com': 'gmail.com',
  // yahoo
  'yhaoo.com': 'yahoo.com', 'yahooo.com': 'yahoo.com', 'yaho.com': 'yahoo.com',
  'yahoo.co': 'yahoo.com', 'yahoo.cm': 'yahoo.com',
  // hotmail / outlook
  'hotmial.com': 'hotmail.com', 'hotmali.com': 'hotmail.com',
  'hotmail.co': 'hotmail.com', 'hotnail.com': 'hotmail.com',
  'outlok.com': 'outlook.com', 'outloook.com': 'outlook.com',
  // icloud
  'iclould.com': 'icloud.com', 'icloud.co': 'icloud.com',
  // German consumer providers
  'gxm.de': 'gmx.de', 'gmx.dee': 'gmx.de',
  'web.dee': 'web.de', 'wbe.de': 'web.de',
  't-onlin.de': 't-online.de',
}

const emailLooksValid = computed(() => email.value === '' || EMAIL_RE.test(email.value))

const emailDomainSuggestion = computed(() => {
  const at = email.value.lastIndexOf('@')
  if (at < 1) return null
  const local = email.value.slice(0, at)
  const domain = email.value.slice(at + 1).toLowerCase()
  const fix = COMMON_DOMAINS[domain]
  return fix ? `${local}@${fix}` : null
})

function applyEmailSuggestion() {
  if (emailDomainSuggestion.value) email.value = emailDomainSuggestion.value
}

async function onSubmit() {
  submitted.value = true
  error.value = null
  fieldErrors.value = {}

  if (!emailLooksValid.value) {
    error.value = 'That doesn\u2019t look like a valid email.'
    return
  }
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
    await auth.register({
      username: username.value,
      email: email.value,
      password: password.value,
      password_confirmation: passwordConfirm.value,
    })
    await nextTick()
    router.push('/collection')
  } catch (e) {
    console.error('Registration failed:', e)
    const resp = e?.response?.data
    if (resp?.errors) {
      // Laravel-style { errors: { field: [msg, ...] } } — surface per-field
      // hints so duplicate-username / invalid-email show under the right input.
      fieldErrors.value = Object.fromEntries(
        Object.entries(resp.errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]),
      )
      error.value = resp.message || 'Please fix the errors above.'
    } else {
      error.value = resp?.message || 'Registration failed'
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

      <form class="vk-login-form" novalidate @submit.prevent="onSubmit">
        <div class="vk-login-eyebrow">Open the Archive</div>
        <h1 class="vk-login-title">
          Claim your <em>vault</em>.
        </h1>
        <p class="vk-login-sub">
          A new collector's account, ready in under a minute. Your decks, locations, and collection history live here from your first card onward.
        </p>

        <label class="vk-login-input">
          <span>Username</span>
          <input
            id="register-username"
            v-model="username"
            type="text"
            autocomplete="username"
            minlength="3"
            maxlength="50"
            required
            :class="{ invalid: !!fieldErrors.username }"
          />
          <span v-if="fieldErrors.username" class="field-hint error">
            {{ fieldErrors.username }}
          </span>
        </label>

        <label class="vk-login-input">
          <span>Email</span>
          <input
            id="register-email"
            v-model.trim="email"
            type="email"
            autocomplete="email"
            required
            :class="{ invalid: !!fieldErrors.email || !emailLooksValid }"
          />
          <span v-if="fieldErrors.email" class="field-hint error">
            {{ fieldErrors.email }}
          </span>
          <span v-else-if="!emailLooksValid" class="field-hint error">
            That doesn't look like a valid email.
          </span>
          <span v-else-if="emailDomainSuggestion" class="field-hint suggestion">
            Did you mean
            <a href="#" @click.prevent="applyEmailSuggestion">{{ emailDomainSuggestion }}</a>?
          </span>
        </label>

        <label class="vk-login-input">
          <span>Password</span>
          <input
            id="register-password"
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
            id="register-password-confirm"
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
          {{ submitting ? 'Creating account…' : 'Create Account →' }}
        </button>

        <p v-if="error" class="vk-login-error">{{ error }}</p>

        <div class="vk-login-meta">
          <span>Already a collector?</span>
          <a @click.prevent="router.push('/login')">Sign in</a>
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
        <span class="ticker">AUTH / NEW SESSION</span>
      </div>
    </section>
  </div>
</template>
