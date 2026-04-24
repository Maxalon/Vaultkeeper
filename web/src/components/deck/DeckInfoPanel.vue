<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useDeckStore } from '../../stores/deck'
import { useTabsStore } from '../../stores/tabs'
import ManaSymbol from '../ManaSymbol.vue'
import ExportMenu from './ExportMenu.vue'

const deck = useDeckStore()
const tabs = useTabsStore()
const emit = defineEmits(['edit', 'export'])

const canvasRef = ref(null)
const titleRef = ref(null)
const hoveredSlot = ref(null)
let ro = null

const mainCount = computed(() =>
  deck.entriesByZone('main').reduce((sum, e) => sum + (e.quantity || 0), 0),
)

const identityLetters = computed(() => {
  const src = deck.deck?.color_identity || ''
  return typeof src === 'string' ? src.split('') : Array.isArray(src) ? src : []
})

const format = computed(() => deck.deck?.format || '')
const isOathbreaker = computed(() => format.value === 'oathbreaker')
const hasIllegality = computed(() => deck.hasDeckLevelIllegality)

const commanderSlots = computed(() => {
  const slots = []
  const c1 = deck.deck?.commander1 || null
  const c2 = deck.deck?.commander2 || null
  if (c1) slots.push(buildSlot(c1, 0))
  if (c2) slots.push(buildSlot(c2, 1))
  return slots
})

function buildSlot(card, index) {
  let sig = null
  if (isOathbreaker.value) {
    const entry = deck.entries.find((e) => e.scryfall_id === card.scryfall_id)
    const sigEntry = entry
      ? deck.signatureSpellEntries.find((e) => e.signature_for_entry_id === entry.id)
      : null
    if (sigEntry) sig = sigEntry.scryfall_card
  }
  return { card, sig, index }
}

function onTitleKey(e) {
  if (e.key === 'Enter') {
    e.preventDefault()
    e.target.blur()
  } else if (e.key === 'Escape') {
    e.preventDefault()
    e.target.textContent = deck.deck?.name || ''
    e.target.blur()
  }
}

async function onTitleBlur(e) {
  const next = (e.target.textContent || '').trim()
  const current = deck.deck?.name || ''
  if (!next || next === current) {
    e.target.textContent = current
    return
  }
  try {
    await deck.updateDeck(deck.deck.id, { name: next })
  } catch {
    e.target.textContent = current
  }
}

function onLegalClick() {
  if (hasIllegality.value) tabs.openTab('illegalities')
}

function onCommanderClick(card) {
  if (!card) return
  const entry = deck.entries.find((e) => e.scryfall_id === card.scryfall_id)
  if (entry) deck.setActiveEntry(entry.id)
}

function drawBg() {
  const c = canvasRef.value
  if (!c) return
  const ratio = window.devicePixelRatio || 1
  const cssW = c.offsetWidth
  const cssH = c.offsetHeight
  if (cssW === 0 || cssH === 0) return
  c.width = Math.round(cssW * ratio)
  c.height = Math.round(cssH * ratio)
  const ctx = c.getContext('2d')
  ctx.setTransform(ratio, 0, 0, ratio, 0, 0)
  const W = cssW
  const H = cssH

  const bg = ctx.createLinearGradient(0, 0, 0, H)
  bg.addColorStop(0, '#140e08')
  bg.addColorStop(1, '#0a0705')
  ctx.fillStyle = bg
  ctx.fillRect(0, 0, W, H)

  ctx.save()
  ctx.globalAlpha = 0.18
  ctx.strokeStyle = '#c9a867'
  ctx.lineWidth = 1
  const r = 18
  const hh = r * Math.sqrt(3)
  for (let row = -1; row < H / hh + 2; row++) {
    for (let col = -1; col < W / (r * 1.5) + 2; col++) {
      const x = col * r * 3 + (row % 2 === 0 ? 0 : r * 1.5)
      const y = row * hh
      ctx.beginPath()
      for (let i = 0; i < 6; i++) {
        const a = (Math.PI / 3) * i - Math.PI / 6
        const px = x + r * Math.cos(a)
        const py = y + r * Math.sin(a)
        if (i === 0) ctx.moveTo(px, py)
        else ctx.lineTo(px, py)
      }
      ctx.closePath()
      ctx.stroke()
    }
  }
  ctx.restore()

  const vg = ctx.createRadialGradient(W * 0.5, H * 0.5, H * 0.1, W * 0.5, H * 0.5, H * 0.95)
  vg.addColorStop(0, 'rgba(0,0,0,0)')
  vg.addColorStop(1, 'rgba(11,8,6,0.85)')
  ctx.fillStyle = vg
  ctx.fillRect(0, 0, W, H)

  const rg = ctx.createRadialGradient(W * 0.88, H * 0.5, 20, W * 0.88, H * 0.5, 260)
  rg.addColorStop(0, 'rgba(224,176,96,0.18)')
  rg.addColorStop(1, 'rgba(224,176,96,0)')
  ctx.fillStyle = rg
  ctx.fillRect(0, 0, W, H)
}

onMounted(() => {
  drawBg()
  if (canvasRef.value && typeof ResizeObserver !== 'undefined') {
    ro = new ResizeObserver(() => drawBg())
    ro.observe(canvasRef.value)
  } else {
    window.addEventListener('resize', drawBg)
  }
})

onBeforeUnmount(() => {
  if (ro) ro.disconnect()
  else window.removeEventListener('resize', drawBg)
})

watch(
  () => deck.deck?.name,
  async (n) => {
    await nextTick()
    if (titleRef.value && n != null && titleRef.value.textContent !== n) {
      titleRef.value.textContent = n
    }
  },
  { immediate: true },
)
</script>

<template>
  <section v-if="deck.deck" class="deck-header">
    <canvas ref="canvasRef" class="deck-header__canvas"></canvas>

    <div class="deck-header-inner">
      <div class="deck-meta">
        <h1
          ref="titleRef"
          class="deck-title"
          :class="{ 'illegal-glow': hasIllegality }"
          contenteditable="true"
          spellcheck="false"
          @keydown="onTitleKey"
          @blur="onTitleBlur"
        >{{ deck.deck.name }}</h1>

        <div class="deck-meta-row">
          <span class="fmt-badge">{{ format }}</span>
          <span class="card-count">{{ mainCount }} cards</span>
          <span v-if="identityLetters.length" class="mana-identity">
            <ManaSymbol
              v-for="letter in identityLetters"
              :key="letter"
              :symbol="`{${letter}}`"
            />
          </span>
          <span
            class="legal-indicator"
            :class="{ illegal: hasIllegality, clickable: hasIllegality }"
            @click="onLegalClick"
          >
            <span class="dot" :class="hasIllegality ? 'bad' : 'ok'">●</span>
            {{ hasIllegality ? 'Illegal' : 'Legal' }}
          </span>
        </div>

        <div class="deck-actions">
          <button type="button" class="btn" @click="emit('edit')">Edit</button>
          <ExportMenu class="export-menu" @select="(f) => emit('export', f)" />
        </div>
      </div>

      <div v-if="commanderSlots.length" class="commanders">
        <div
          v-for="slot in commanderSlots"
          :key="slot.card.scryfall_id || slot.index"
          class="commander-slot"
          :class="{
            'commander-slot--no-sig': !slot.sig,
            'sig-expanded': hoveredSlot === slot.index,
          }"
        >
          <div
            v-if="slot.sig"
            class="sig-strip"
            @mouseenter="hoveredSlot = slot.index"
            @mouseleave="hoveredSlot = null"
            @click="onCommanderClick(slot.sig)"
          >
            <span class="sig-icon">SIG</span>
            <span class="sig-name">{{ slot.sig.name }}</span>
          </div>

          <div
            class="commander-portrait"
            @click="onCommanderClick(slot.card)"
          >
            <img
              v-if="slot.card.image_normal || slot.card.image_small"
              :src="slot.card.image_normal || slot.card.image_small"
              :alt="slot.card.name"
            />
          </div>

          <div v-if="slot.sig" class="sig-card">
            <img
              v-if="slot.sig.image_normal || slot.sig.image_small"
              :src="slot.sig.image_normal || slot.sig.image_small"
              :alt="slot.sig.name"
            />
          </div>
        </div>
      </div>
    </div>
  </section>
</template>

<style scoped>
.deck-header {
  position: relative;
  height: 200px;
  overflow: hidden;
  background: #0d0906;
  border-bottom: 1px solid var(--hairline);
  flex: 0 0 auto;
}
.deck-header__canvas {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  display: block;
  z-index: 0;
}
.deck-header-inner {
  position: absolute;
  inset: 0;
  z-index: 2;
  display: flex;
  align-items: center;
  padding: 0 28px;
  gap: 32px;
}
.deck-meta {
  flex: 1 1 auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
}
.deck-meta-row {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.deck-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}

.deck-title {
  font-family: var(--font-display), serif;
  font-size: 36px;
  font-weight: 600;
  line-height: 1.02;
  letter-spacing: -0.015em;
  color: var(--ink-100);
  margin: 0;
  padding: 2px 6px;
  margin-left: -6px;
  border-radius: 4px;
  transition: background 0.12s ease;
  cursor: text;
  display: inline-block;
  text-shadow: 0 2px 16px rgba(0, 0, 0, 0.6);
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  outline: none;
}
.deck-title:hover,
.deck-title:focus { background: var(--amber-dim); }

.fmt-badge {
  padding: 2px 8px;
  border-radius: 3px;
  background: var(--amber);
  color: #2a1d0a;
  font-family: var(--font-mono), monospace;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.1em;
}
.card-count { color: var(--ink-70); font-size: 13px; }
.legal-indicator {
  color: var(--ink-50);
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.legal-indicator .dot.ok { color: #7aa36b; }
.legal-indicator .dot.bad { color: #d46a6a; }
.legal-indicator.illegal { color: #d46a6a; }
.legal-indicator.clickable { cursor: pointer; }

.mana-identity {
  display: inline-flex;
  align-items: center;
  gap: 2px;
  font-size: 20px;
  line-height: 1;
}

.btn {
  padding: 5px 12px;
  font-size: 11px;
  font-weight: 500;
  color: var(--ink-70);
  background: rgba(20, 15, 9, 0.6);
  border: 1px solid var(--hairline-strong);
  border-radius: 3px;
  letter-spacing: 0.04em;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  transition: color 0.12s ease, border-color 0.12s ease, background 0.12s ease;
}
.btn:hover {
  color: var(--ink-100);
  border-color: var(--amber);
}

.commanders {
  display: flex;
  gap: 10px;
  flex-shrink: 0;
  height: 100%;
  align-items: center;
}
.commander-slot {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 6px;
  width: 132px;
}
.commander-slot--no-sig { padding-top: 28px; }

.commander-portrait {
  position: relative;
  width: 132px;
  height: 184px;
  border-radius: 6px;
  overflow: hidden;
  border: 1px solid var(--hairline-strong);
  cursor: pointer;
  flex: none;
  background: linear-gradient(135deg, #3a2a18, #1a120c);
  transition: border-color 0.2s ease, transform 0.25s ease, opacity 0.25s ease, filter 0.25s ease;
}
.commander-portrait:hover { border-color: var(--amber); }
.commander-portrait img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.sig-strip {
  position: relative;
  height: 22px;
  padding: 0 8px;
  display: flex;
  align-items: center;
  gap: 6px;
  border-radius: 4px;
  background: linear-gradient(90deg, rgba(30, 22, 15, 0.9), rgba(18, 13, 9, 0.75));
  border: 1px solid var(--hairline-strong);
  font-size: 10.5px;
  color: var(--ink-90);
  overflow: hidden;
  white-space: nowrap;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.sig-strip:hover {
  border-color: rgba(224, 176, 96, 0.45);
  background: linear-gradient(90deg, rgba(42, 29, 18, 0.95), rgba(26, 18, 12, 0.85));
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.5);
}
.sig-strip .sig-icon {
  font-family: var(--font-mono), monospace;
  font-size: 9px;
  font-weight: 700;
  color: var(--amber);
  opacity: 0.7;
}
.sig-strip .sig-name {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sig-card {
  position: absolute;
  left: 0;
  top: 28px;
  width: 132px;
  height: 184px;
  border-radius: 6px;
  border: 1px solid var(--amber);
  overflow: hidden;
  opacity: 0;
  transform: translateY(6px) scale(0.96);
  transform-origin: top center;
  transition: opacity 0.18s ease, transform 0.22s ease;
  pointer-events: none;
  z-index: 3;
  background: linear-gradient(135deg, #3a2a18, #1a120c);
  box-shadow:
    0 14px 30px rgba(0, 0, 0, 0.6),
    0 0 0 3px var(--amber-dim);
}
.sig-card img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.commander-slot.sig-expanded .commander-portrait {
  transform: translateY(4px) scale(0.98);
  opacity: 0.35;
  filter: grayscale(0.3);
}
.commander-slot.sig-expanded .sig-card {
  opacity: 1;
  transform: translateY(0) scale(1);
}

.export-menu :deep(button) {
  padding: 5px 12px;
  font-size: 11px;
  font-weight: 500;
  color: var(--ink-70);
  background: rgba(20, 15, 9, 0.6);
  border: 1px solid var(--hairline-strong);
  border-radius: 3px;
  letter-spacing: 0.04em;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  transition: color 0.12s ease, border-color 0.12s ease;
}
.export-menu :deep(button:hover) {
  color: var(--ink-100);
  border-color: var(--amber);
  background: rgba(20, 15, 9, 0.6);
}
</style>
