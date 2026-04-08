<script setup>
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()
const router = useRouter()

onMounted(() => {
  if (!auth.user) {
    auth.fetchMe().catch(() => {
      auth.logout()
      router.push({ name: 'login' })
    })
  }
})

async function onLogout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <main class="dashboard">
    <header>
      <h1>Vaultkeeper</h1>
      <button @click="onLogout">Log out</button>
    </header>
    <p v-if="auth.user">Welcome, {{ auth.user.username }}.</p>
    <p v-else>Loading…</p>
  </main>
</template>

<style scoped>
.dashboard {
  max-width: 720px;
  margin: 2rem auto;
  padding: 0 1rem;
}
header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 1.5rem;
}
button {
  padding: 0.4rem 0.8rem;
  cursor: pointer;
}
</style>
