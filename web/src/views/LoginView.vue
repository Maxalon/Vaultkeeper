<script setup>
import { nextTick, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

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
    // Wait one tick so the auth store mutations have flushed before the
    // route guard re-evaluates the new token, then navigate to /collection
    // (the old `dashboard` route was removed in 3B).
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
  <main class="login">
    <h1>Vaultkeeper</h1>
    <form @submit.prevent="onSubmit">
      <label>
        Username
        <input id="login-username" v-model="username" type="text" autocomplete="username" required />
      </label>
      <label>
        Password
        <input id="login-password" v-model="password" type="password" autocomplete="current-password" required />
      </label>
      <button type="submit" :disabled="submitting">
        {{ submitting ? 'Signing in…' : 'Sign in' }}
      </button>
      <p v-if="error" class="error">{{ error }}</p>
    </form>
  </main>
</template>

<style scoped>
.login {
  max-width: 320px;
  margin: 4rem auto;
  display: flex;
  flex-direction: column;
  gap: 1rem;
}
form {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}
label {
  display: flex;
  flex-direction: column;
  font-size: 0.875rem;
  gap: 0.25rem;
}
input {
  padding: 0.5rem;
  font-size: 1rem;
}
button {
  padding: 0.6rem;
  font-size: 1rem;
  cursor: pointer;
}
.error {
  color: #b91c1c;
  font-size: 0.875rem;
}
</style>
