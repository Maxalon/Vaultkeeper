<script setup>
import { onMounted, ref } from 'vue'
import axios from 'axios'

// Decorative — purely cosmetic strips behind the hero card. Kept static so
// the login page makes no authenticated requests and never leaks the
// signed-in user's collection contents.
const DECOR_STRIPS = [
  [
    { n: 'Ancestral Recall', col: 'U', qty: 1 },
    { n: 'Lightning Bolt', col: 'R', qty: 4 },
    { n: 'Counterspell', col: 'U', qty: 3 },
    { n: 'Llanowar Elves', col: 'G', qty: 4 },
    { n: 'Dark Ritual', col: 'B', qty: 2 },
    { n: 'Swords to Plowshares', col: 'W', qty: 2 },
  ],
  [
    { n: 'Brainstorm', col: 'U', qty: 4 },
    { n: 'Path to Exile', col: 'W', qty: 3 },
    { n: 'Demonic Tutor', col: 'B', qty: 1 },
    { n: 'Birds of Paradise', col: 'G', qty: 2 },
    { n: 'Shock', col: 'R', qty: 4 },
    { n: 'Sol Ring', col: 'C', qty: 1 },
  ],
  [
    { n: 'Wrath of God', col: 'W', qty: 1 },
    { n: 'Mana Drain', col: 'U', qty: 1 },
    { n: 'Thoughtseize', col: 'B', qty: 4 },
    { n: 'Fireblast', col: 'R', qty: 2 },
    { n: 'Tarmogoyf', col: 'G', qty: 1 },
    { n: 'Mox Diamond', col: 'C', qty: 1 },
  ],
  [
    { n: 'Snapcaster Mage', col: 'U', qty: 2 },
    { n: 'Liliana of the Veil', col: 'B', qty: 1 },
    { n: 'Goblin Guide', col: 'R', qty: 4 },
    { n: 'Noble Hierarch', col: 'G', qty: 2 },
    { n: 'Stoneforge Mystic', col: 'W', qty: 2 },
    { n: 'Skullclamp', col: 'C', qty: 1 },
  ],
]

const hero = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    // Public endpoint — no auth header needed. Bypass the api/lib instance
    // (which adds the JWT) so we don't accidentally leak it on the login
    // page if a stale token is sitting in localStorage.
    const { data } = await axios.get('/api/cards/featured')
    hero.value = data
  } catch (err) {
    console.warn('Featured card fetch failed; using fallback', err)
    hero.value = null
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div class="wall">
    <div class="grid-bg" />

    <div
      v-for="(row, idx) in DECOR_STRIPS"
      :key="idx"
      class="strip-row"
      :style="{ top: `${8 + idx * 10}%`, opacity: 0.35 + idx * 0.15 }"
    >
      <div v-for="(c, i) in row" :key="i" class="mini-strip" :data-color="c.col">
        <span class="mini-qty">×{{ c.qty }}</span>
        <span class="mini-name">{{ c.n }}</span>
      </div>
    </div>

    <!-- Hero card — real Scryfall art for a non-land from the most recent
         set in the user's collection. The Scryfall image already includes
         the printed card face (name, art, type, oracle, P/T), so we render
         it edge-to-edge inside the gold-rimmed frame. -->
    <div class="hero-card" :class="{ 'is-loading': loading }">
      <img
        v-if="hero?.image_large"
        :src="hero.image_large"
        :alt="hero.name"
        class="hero-image"
      />
      <div v-else class="hero-fallback">
        <span class="vk-mark"><span class="vk-mark-icon" /></span>
        <p>Welcome back, archivist.</p>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wall {
  position: absolute;
  inset: 0;
  overflow: hidden;
  -webkit-mask-image: radial-gradient(ellipse 100% 80% at center, black 40%, transparent 85%);
  mask-image: radial-gradient(ellipse 100% 80% at center, black 40%, transparent 85%);
}

.grid-bg {
  position: absolute;
  inset: 0;
  background-image:
    linear-gradient(rgba(240, 195, 92, 0.06) 1px, transparent 1px),
    linear-gradient(90deg, rgba(240, 195, 92, 0.06) 1px, transparent 1px);
  background-size: 48px 48px;
  -webkit-mask-image: radial-gradient(ellipse at center, black 30%, transparent 70%);
  mask-image: radial-gradient(ellipse at center, black 30%, transparent 70%);
}

.strip-row {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  gap: 8px;
  padding: 0 40px;
  transform: perspective(800px) rotateX(2deg);
  pointer-events: none;
}

.mini-strip {
  flex: 1;
  min-width: 0;
  height: 22px;
  display: flex;
  align-items: center;
  background: linear-gradient(90deg, var(--mc), color-mix(in oklab, var(--mc) 60%, #000) 100%);
  border: 1px solid rgba(0, 0, 0, 0.4);
  border-radius: 3px;
  overflow: hidden;
  font-size: 11px;
  color: #fff;
  text-shadow: 0 1px 0 rgba(0, 0, 0, 0.8);
}
.mini-strip[data-color="W"] { --mc: #8a8264; }
.mini-strip[data-color="U"] { --mc: #2e5680; }
.mini-strip[data-color="B"] { --mc: #2d2730; }
.mini-strip[data-color="R"] { --mc: #8a3a2e; }
.mini-strip[data-color="G"] { --mc: #2e5c3a; }
.mini-strip[data-color="C"] { --mc: #3a3a42; }

.mini-qty {
  width: 22px;
  text-align: center;
  font-family: var(--font-mono);
  font-size: 10px;
  background: rgba(0, 0, 0, 0.35);
  height: 100%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
.mini-name {
  flex: 1;
  padding: 0 8px;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* ── Hero card ──────────────────────────────────────────────────────── */

.hero-card {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%) perspective(800px) rotateY(-3deg);
  width: 320px;
  aspect-ratio: 63 / 88;
  border-radius: 14px;
  overflow: hidden;
  background: linear-gradient(160deg, #2a1a14, #1a1410 60%, #0a0a10 100%);
  box-shadow:
    0 0 0 1px rgba(240, 195, 92, 0.35),
    0 40px 80px rgba(0, 0, 0, 0.7),
    0 20px 40px rgba(240, 195, 92, 0.1);
}
.hero-card::after {
  /* Diagonal "shine" sweep — faint highlight running across the face. */
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(
    115deg,
    transparent 38%,
    rgba(255, 255, 255, 0.08) 50%,
    transparent 62%
  );
  pointer-events: none;
}
.hero-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.hero-fallback {
  position: absolute;
  inset: 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 14px;
  color: var(--vk-ink-2);
  text-align: center;
  font-family: var(--font-display);
}
.hero-fallback p { margin: 0; font-size: 14px; }
</style>
