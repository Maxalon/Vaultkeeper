<script setup>
import { computed, ref } from 'vue'
import DfcPopover from '../DfcPopover.vue'
import ManaCost from '../ManaCost.vue'
import SetSymbol from '../SetSymbol.vue'
import { useDeckStore } from '../../stores/deck'

const deckStore = useDeckStore()

/**
 * Strip-layout counterpart to DeckCardTile. Compact --strip-height row
 * that expands on hover to --strip-expanded, revealing the full card
 * image. Same drag-to-remove + DFC popover semantics as the tile.
 */
const props = defineProps({
  entry: { type: Object, required: true },
  illegal: { type: Boolean, default: false },
  showGameChanger: { type: Boolean, default: false },
})
const emit = defineEmits(['click'])

const rootRef = ref(null)
const hovered = ref(false)
const imageLoaded = ref(false)
const imgFailed = ref(false)
let hoverTimer = null

const card = computed(() => props.entry?.scryfall_card || {})
const qty = computed(() => props.entry?.quantity || 0)

const hasImage = computed(() => !!card.value.image_normal && !imgFailed.value)
const isLoaded = computed(() => hasImage.value && imageLoaded.value)

function onClick() {
  emit('click', props.entry)
}

function onMouseEnter() {
  if (!card.value.is_dfc) return
  hoverTimer = setTimeout(() => { hovered.value = true }, 300)
}
function onMouseLeave() {
  if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
  hovered.value = false
}

function onDragStart(e) {
  e.dataTransfer.effectAllowed = 'move'
  e.dataTransfer.setData('application/json', JSON.stringify({
    source: 'deck',
    deckEntryId: props.entry.id,
    scryfall_id: props.entry.scryfall_id,
    zone: props.entry.zone,
  }))
  deckStore.setDragEntry(props.entry.id)
}
function onDragEnd() {
  deckStore.setDragEntry(null)
}
</script>

<template>
  <div
    ref="rootRef"
    class="deck-card-strip"
    :class="{ 'illegal-glow': illegal, loaded: isLoaded }"
    :draggable="true"
    @click="onClick"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
    @mouseenter="onMouseEnter"
    @mouseleave="onMouseLeave"
  >
    <div class="strip-clip">
      <img
        v-if="hasImage"
        class="card-img"
        :src="card.image_normal"
        :alt="card.name"
        loading="lazy"
        decoding="async"
        @load="imageLoaded = true"
        @error="imgFailed = true"
      />

      <div class="overlay">
        <SetSymbol :set="card.set_code" :rarity="card.rarity || 'common'" :size="16" />
        <span class="name">{{ card.name || '—' }}</span>
        <ManaCost v-if="card.mana_cost" class="cost" :cost="card.mana_cost" />
      </div>

      <span v-if="qty > 1" class="qty-badge">{{ qty }}</span>
      <span v-if="showGameChanger && card.commander_game_changer" class="gc-badge">GC</span>
    </div>

    <DfcPopover
      v-if="card.is_dfc && hovered && card.image_normal_back"
      :back-image="card.image_normal_back"
      :anchor="rootRef"
    />
  </div>
</template>

<style scoped>
.deck-card-strip {
  position: relative;
  width: var(--card-width);
  height: var(--strip-height);
  margin-bottom: 4px;
  /* Radius keyed to --card-width (not strip height). 4.5% of card-width
     matches the Scryfall image's baked-in corner curve; at a 38–54px
     collapsed strip height a %-based vertical radius would round to
     ~1px — effectively square — so we pin both radii to the same
     horizontal value. */
  border-radius: calc(var(--card-width) * 0.045);
  cursor: pointer;
  background: var(--bg-2);
  border: 1px solid #0a0a0a;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.45);
  transition: height 160ms ease-out, margin-bottom 160ms ease-out,
              box-shadow 160ms ease, transform 160ms ease-out;
  content-visibility: auto;
  contain-intrinsic-size: auto var(--card-width) auto var(--strip-height);
}
.deck-card-strip:hover {
  height: var(--strip-expanded);
  margin-bottom: calc(4px + var(--strip-gap));
  z-index: 2;
  transform: translateY(-1px);
}

.strip-clip {
  position: absolute;
  inset: 0;
  overflow: hidden;
  border-radius: inherit;
}

.card-img {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: auto;
  display: block;
  z-index: 1;
  pointer-events: none;
  user-select: none;
}

.overlay {
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  height: var(--strip-height);
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 8px;
  z-index: 2;
  pointer-events: none;
  background: linear-gradient(
    180deg,
    rgba(13, 15, 20, 0) 0%,
    rgba(13, 15, 20, 0.65) 35%,
    rgba(13, 15, 20, 0.92) 100%
  );
  color: var(--text);
  transition: top 200ms ease-out;
}
.deck-card-strip.loaded .overlay {
  top: calc(100% - var(--strip-height));
}

.name {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 600;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.9);
}
.cost {
  font-size: 14px;
  flex-shrink: 0;
}

.qty-badge, .gc-badge {
  position: absolute;
  min-width: 22px;
  height: 20px;
  padding: 0 6px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-family: var(--font-mono), monospace;
  font-size: 11px;
  font-weight: 700;
  box-shadow: 0 2px 5px rgba(0, 0, 0, 0.45);
  pointer-events: none;
  z-index: 3;
}
.qty-badge {
  top: 4px;
  right: 4px;
  background: rgba(0, 0, 0, 0.82);
  color: #fff;
}
.gc-badge {
  top: 4px;
  left: 4px;
  background: var(--vk-gold, #c9a552);
  color: #1a1408;
}
</style>
