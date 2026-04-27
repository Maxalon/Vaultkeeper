<script setup>
import { useRouter } from 'vue-router'
import { useSettingsStore } from '../stores/settings'
import { useAuthStore } from '../stores/auth'
import VaultMark from '../components/VaultMark.vue'

const router = useRouter()
const settings = useSettingsStore()
const auth = useAuthStore()

async function logout() {
  await auth.logout()
  router.push('/login')
}

function goBack() {
  const target = window.history.state?.returnTo
  if (target && typeof target === 'string') router.push(target)
  else router.push('/collection')
}

const displayOptions = [
  { k: 'A', n: 'Typed names' },
  { k: 'B', n: 'Corner badge' },
]

const hoverOptions = [
  { k: 'expand', n: 'Expand strip' },
  { k: 'peek', n: 'Popover peek' },
]

const densityOptions = [
  { k: 'compact', n: 'Compact' },
  { k: 'default', n: 'Default' },
  { k: 'cozy', n: 'Cozy' },
]
</script>

<template>
  <main class="settings-page">
    <header class="settings-header">
      <VaultMark />
      <button class="back" @click="goBack">← Back</button>
    </header>

    <section class="settings-content">
      <h1 class="title">Display Settings</h1>
      <p class="lede">Tune the catalogue to taste. All preferences persist locally on this device.</p>

      <div class="setting">
        <h2>Strip Layout</h2>
        <p class="hint">A is the typed-name treatment; B places a corner quantity badge over the card art.</p>
        <div class="seg">
          <button
            v-for="opt in displayOptions"
            :key="opt.k"
            :class="{ active: settings.displayMode === opt.k }"
            @click="settings.setDisplayMode(opt.k)"
          >
            {{ opt.n }}
          </button>
        </div>
      </div>

      <div class="setting">
        <h2>Hover Behaviour</h2>
        <p class="hint">"Expand" grows the strip in place to reveal the full card; "Peek" pops the card up next to its row without disturbing the grid.</p>
        <div class="seg">
          <button
            v-for="opt in hoverOptions"
            :key="opt.k"
            :class="{ active: settings.hoverMode === opt.k }"
            @click="settings.setHoverMode(opt.k)"
          >
            {{ opt.n }}
          </button>
        </div>
      </div>

      <div class="setting">
        <h2>Density</h2>
        <p class="hint">Controls strip height, gap and card width.</p>
        <div class="seg">
          <button
            v-for="opt in densityOptions"
            :key="opt.k"
            :class="{ active: settings.density === opt.k }"
            @click="settings.setDensity(opt.k)"
          >
            {{ opt.n }}
          </button>
        </div>
      </div>

      <div class="setting account">
        <h2>Account</h2>
        <p class="hint">Sign out of this device. Your settings stay on this browser.</p>
        <button class="logout" @click="logout">Sign Out</button>
      </div>
    </section>
  </main>
</template>

<style scoped>
.settings-page {
  min-height: 100vh;
  background: var(--bg-0);
  color: var(--ink-100);
  padding: 32px 48px 64px;
}

.settings-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  max-width: 720px;
  margin: 0 auto 40px;
}

.back {
  background: transparent;
  border: 1px solid var(--hairline);
  color: var(--ink-70);
  height: 32px;
  padding: 0 14px;
  border-radius: var(--radius-sm);
  font-size: 12px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: all 0.12s ease;
}
.back:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
  background: var(--bg-1);
}

.settings-content {
  max-width: 720px;
  margin: 0 auto;
}

.title {
  font-family: var(--font-display), serif;
  font-size: 36px;
  font-weight: 400;
  letter-spacing: -0.02em;
  color: var(--amber);
  margin: 0 0 8px;
}

.lede {
  font-size: 14px;
  line-height: 1.5;
  color: var(--ink-70);
  margin: 0 0 40px;
  max-width: 520px;
}

.setting {
  margin-bottom: 32px;
  padding-bottom: 32px;
  border-bottom: 1px solid var(--hairline-strong);
}
.setting:last-child {
  border-bottom: 0;
}

.setting h2 {
  font-family: var(--font-sans), sans-serif;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.14em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0 0 6px;
}

.hint {
  font-size: 13px;
  line-height: 1.5;
  color: var(--ink-70);
  margin: 0 0 14px;
  max-width: 480px;
}

.seg {
  display: inline-flex;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  padding: 3px;
  gap: 0;
}
.seg button {
  padding: 6px 18px;
  height: 30px;
  font-size: 12px;
  font-weight: 500;
  color: var(--ink-50);
  background: transparent;
  border: 0;
  border-radius: 3px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: all 0.12s ease;
}
.seg button:hover {
  color: var(--ink-100);
}
.seg button.active {
  background: var(--amber);
  color: #1a1408;
  font-weight: 600;
}

.logout {
  height: 36px;
  padding: 0 22px;
  background: transparent;
  color: var(--ink-100);
  border: 1px solid color-mix(in oklab, #d46a6a 50%, var(--hairline));
  border-radius: var(--radius-sm);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  cursor: pointer;
  transition: all 0.12s ease;
}
.logout:hover {
  background: #d46a6a;
  color: #1a1408;
  border-color: #d46a6a;
}
</style>
