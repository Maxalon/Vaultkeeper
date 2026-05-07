<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSettingsStore } from '../stores/settings'
import { useAuthStore } from '../stores/auth'
import { useToast } from '../composables/useToast'
import api from '../lib/api'
import VaultMark from '../components/VaultMark.vue'
import HelpHint from '../components/HelpHint.vue'

const router = useRouter()
const settings = useSettingsStore()
const auth = useAuthStore()
const toast = useToast()

// ── Privacy settings (B5) ──────────────────────────────────────────────────
const privacy = ref({
  collection_visibility: 'friends',
  decks_visibility: 'friends',
  discoverable: true,
})
const privacyLoading = ref(false)

onMounted(async () => {
  try {
    const { data } = await api.get('/privacy-settings')
    privacy.value = data.data
  } catch {
    // Non-fatal: defaults are sensible
  }
})

async function savePrivacy(patch) {
  privacyLoading.value = true
  try {
    const { data } = await api.patch('/privacy-settings', patch)
    privacy.value = data.data
    toast.success('Privacy settings saved.')
  } catch (e) {
    toast.error(e.response?.data?.message || 'Failed to save privacy settings')
  } finally {
    privacyLoading.value = false
  }
}

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
  { k: 'A', n: 'Typed' },
  { k: 'B', n: 'Badge' },
]

const hoverOptions = [
  { k: 'expand', n: 'Expand' },
  { k: 'peek', n: 'Peek' },
]

const densityOptions = [
  { k: 'compact', n: 'Compact' },
  { k: 'default', n: 'Default' },
  { k: 'cozy', n: 'Cozy' },
]

const groupCounterOptions = [
  { k: 'cards', n: 'Cards' },
  { k: 'locations', n: 'Locations' },
  { k: 'off', n: 'Off' },
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

      <section class="settings-group">
        <h3 class="group-title">Display</h3>
        <div class="settings-list">
          <div class="row">
            <div class="row-label">
              <span class="row-title">Strip Layout</span>
              <HelpHint text="A is the typed-name treatment; B places a corner quantity badge over the card art." />
            </div>
            <div class="seg">
              <button
                v-for="opt in displayOptions"
                :key="opt.k"
                :class="{ active: settings.displayMode === opt.k }"
                @click="settings.setDisplayMode(opt.k)"
              >{{ opt.n }}</button>
            </div>
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Hover Behaviour</span>
              <HelpHint text="Expand grows the strip in place to reveal the full card. Peek pops the card up next to its row without disturbing the grid." />
            </div>
            <div class="seg">
              <button
                v-for="opt in hoverOptions"
                :key="opt.k"
                :class="{ active: settings.hoverMode === opt.k }"
                @click="settings.setHoverMode(opt.k)"
              >{{ opt.n }}</button>
            </div>
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Density</span>
              <HelpHint text="Controls strip height, gap, and card width." />
            </div>
            <div class="seg">
              <button
                v-for="opt in densityOptions"
                :key="opt.k"
                :class="{ active: settings.density === opt.k }"
                @click="settings.setDensity(opt.k)"
              >{{ opt.n }}</button>
            </div>
          </div>
        </div>
      </section>

      <section class="settings-group">
        <h3 class="group-title">Location Sidebar</h3>
        <div class="settings-list">
          <div class="row">
            <div class="row-label">
              <span class="row-title">Edit button</span>
              <HelpHint text="Reveal the edit pencil on hover for each location strip." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: settings.sidebarShowEdit }"
              :aria-pressed="settings.sidebarShowEdit"
              @click="settings.setSidebarBool('sidebarShowEdit', !settings.sidebarShowEdit)"
            />
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Delete button</span>
              <HelpHint text="Reveal a delete (×) button on hover next to the edit pencil." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: settings.sidebarShowDelete }"
              :aria-pressed="settings.sidebarShowDelete"
              @click="settings.setSidebarBool('sidebarShowDelete', !settings.sidebarShowDelete)"
            />
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Drag handle</span>
              <HelpHint text="Reveal the ⠿ drag handle on hover. Hide to lock the sidebar order." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: settings.sidebarShowDrag }"
              :aria-pressed="settings.sidebarShowDrag"
              @click="settings.setSidebarBool('sidebarShowDrag', !settings.sidebarShowDrag)"
            />
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Deck format tag</span>
              <HelpHint text="Show the small format badge (CMDR, STD, …) next to deck names in the sidebar." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: settings.sidebarShowFormatBadge }"
              :aria-pressed="settings.sidebarShowFormatBadge"
              @click="settings.setSidebarBool('sidebarShowFormatBadge', !settings.sidebarShowFormatBadge)"
            />
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Card count · Drawers</span>
              <HelpHint text="Show the card-count pill on drawer rows." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: settings.sidebarShowCountDrawer }"
              :aria-pressed="settings.sidebarShowCountDrawer"
              @click="settings.setSidebarBool('sidebarShowCountDrawer', !settings.sidebarShowCountDrawer)"
            />
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Card count · Binders</span>
              <HelpHint text="Show the card-count pill on binder rows." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: settings.sidebarShowCountBinder }"
              :aria-pressed="settings.sidebarShowCountBinder"
              @click="settings.setSidebarBool('sidebarShowCountBinder', !settings.sidebarShowCountBinder)"
            />
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Card count · Decks</span>
              <HelpHint text="Show the card-count pill on deck rows." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: settings.sidebarShowCountDeck }"
              :aria-pressed="settings.sidebarShowCountDeck"
              @click="settings.setSidebarBool('sidebarShowCountDeck', !settings.sidebarShowCountDeck)"
            />
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Group counter</span>
              <HelpHint text="Cards: total cards across the group's locations. Locations: number of locations in the group. Off: hide the counter." />
            </div>
            <div class="seg">
              <button
                v-for="opt in groupCounterOptions"
                :key="opt.k"
                :class="{ active: settings.sidebarGroupCounter === opt.k }"
                @click="settings.setSidebarGroupCounter(opt.k)"
              >{{ opt.n }}</button>
            </div>
          </div>
        </div>
      </section>

      <!-- B5: Privacy settings panel -->
      <section class="settings-group">
        <h3 class="group-title">Privacy</h3>
        <div class="settings-list">
          <div class="row">
            <div class="row-label">
              <span class="row-title">Collection visibility</span>
              <HelpHint text="Who can see your card collection. 'Friends' means accepted friends only. 'Private' hides it from everyone." />
            </div>
            <div class="seg">
              <button
                :class="{ active: privacy.collection_visibility === 'friends' }"
                :disabled="privacyLoading"
                @click="savePrivacy({ collection_visibility: 'friends' })"
              >Friends</button>
              <button
                :class="{ active: privacy.collection_visibility === 'private' }"
                :disabled="privacyLoading"
                @click="savePrivacy({ collection_visibility: 'private' })"
              >Private</button>
            </div>
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Decks visibility</span>
              <HelpHint text="Who can see your decks. Independent of collection visibility." />
            </div>
            <div class="seg">
              <button
                :class="{ active: privacy.decks_visibility === 'friends' }"
                :disabled="privacyLoading"
                @click="savePrivacy({ decks_visibility: 'friends' })"
              >Friends</button>
              <button
                :class="{ active: privacy.decks_visibility === 'private' }"
                :disabled="privacyLoading"
                @click="savePrivacy({ decks_visibility: 'private' })"
              >Private</button>
            </div>
          </div>

          <div class="row">
            <div class="row-label">
              <span class="row-title">Discoverable</span>
              <HelpHint text="When off, your username won't appear in search results. Existing friends are unaffected." />
            </div>
            <button
              type="button"
              class="switch"
              :class="{ on: privacy.discoverable }"
              :aria-pressed="privacy.discoverable"
              :disabled="privacyLoading"
              @click="savePrivacy({ discoverable: !privacy.discoverable })"
            />
          </div>
        </div>
      </section>

      <section class="settings-group">
        <h3 class="group-title">Account</h3>
        <div class="settings-list">
          <div class="row">
            <div class="row-label">
              <span class="row-title">Sign out</span>
              <HelpHint text="Sign out of this device. Your settings stay on this browser." />
            </div>
            <button class="logout" @click="logout">Sign Out</button>
          </div>
        </div>
      </section>
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
  margin: 0 0 36px;
  max-width: 520px;
}

.settings-group {
  margin-bottom: 28px;
}

.group-title {
  font-family: var(--font-sans), sans-serif;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.16em;
  text-transform: uppercase;
  color: var(--ink-50);
  margin: 0 0 10px;
  padding: 0 2px;
}

.settings-list {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  background: var(--bg-1);
}

.row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 12px 16px;
  border-bottom: 1px solid var(--hairline);
  min-height: 48px;
}
.row:last-child { border-bottom: 0; }

.row-label {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.row-title {
  font-size: 13px;
  font-weight: 500;
  color: var(--ink-100);
  letter-spacing: 0.01em;
  white-space: nowrap;
}

/* Compact segmented control */
.seg {
  display: inline-flex;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: 999px;
  padding: 2px;
  gap: 0;
  flex-shrink: 0;
}
.seg button {
  padding: 4px 12px;
  height: 24px;
  font-size: 11px;
  font-weight: 500;
  color: var(--ink-50);
  background: transparent;
  border: 0;
  border-radius: 999px;
  letter-spacing: 0.04em;
  cursor: pointer;
  transition: all 0.12s ease;
}
.seg button:hover { color: var(--ink-100); }
.seg button.active {
  background: var(--amber);
  color: #1a1408;
  font-weight: 600;
}

/* Boolean switch */
.switch {
  position: relative;
  width: 32px;
  height: 18px;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: 999px;
  padding: 0;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
  flex-shrink: 0;
}
.switch::after {
  content: '';
  position: absolute;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  background: var(--ink-50);
  top: 2px;
  left: 2px;
  transition: left 0.15s ease, background 0.15s ease;
}
.switch:hover { border-color: var(--ink-30); }
.switch.on {
  background: var(--amber);
  border-color: var(--amber);
}
.switch.on::after {
  background: #1a1408;
  left: 16px;
}

.logout {
  height: 30px;
  padding: 0 16px;
  background: transparent;
  color: var(--ink-100);
  border: 1px solid color-mix(in oklab, #d46a6a 50%, var(--hairline));
  border-radius: var(--radius-sm);
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
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
