<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { useSettingsStore } from '../stores/settings'
import { colorSkeletonStyle } from '../composables/useColorSkeleton'
import CornerCountBadge from './CornerCountBadge.vue'
import DfcPopover from './DfcPopover.vue'
import SetSymbol from './SetSymbol.vue'
import ManaCost from './ManaCost.vue'

const props = defineProps({
  entry: { type: Object, required: true },
  active: { type: Boolean, default: false },
  selected: { type: Boolean, default: false },
  last: { type: Boolean, default: false },
  // 'expand' (default) — strip grows to reveal the full card image inline.
  // 'peek' — strip stays compact; parent renders a popover preview alongside.
  hoverMode: { type: String, default: 'expand' },
})

const emit = defineEmits(['select', 'peek-show', 'peek-hide'])

const settings = useSettingsStore()

const card = computed(() => props.entry.card || {})
const imgFailed = ref(false)
const imageLoaded = ref(false)

const hasImage = computed(() => !!card.value.image_normal && !imgFailed.value)
const isLoaded = computed(() => hasImage.value && imageLoaded.value)
const isModeB = computed(() => settings.displayMode === 'B')
const isPeek = computed(() => props.hoverMode === 'peek')

// DFC backside popover — expand-mode only. In peek mode the parent's
// CardPeek renders both faces side-by-side, so we suppress the floating
// popover to avoid stacking two previews on the same hover.
const rootRef = ref(null)
const hovered = ref(false)
let hoverTimer = null

function onMouseEnter(event) {
  if (isPeek.value) {
    emit('peek-show', {
      entry: props.entry,
      rect: event.currentTarget.getBoundingClientRect(),
    })
    return
  }
  if (!card.value.is_dfc) return
  hoverTimer = setTimeout(() => { hovered.value = true }, 300)
}

function onMouseLeave() {
  if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
  hovered.value = false
  if (isPeek.value) emit('peek-hide')
}

onBeforeUnmount(() => {
  if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
})

// Color identity skeleton — shown until the real card image loads (or as
// the permanent placeholder for cards Scryfall doesn't return).
// Style resolver extracted to composables/useColorSkeleton so CardStrip
// (collection) and CatalogStrip (catalog) agree on the palette.
const skeletonStyle = computed(() => colorSkeletonStyle(card.value.colors))
</script>

<template>
  <div
    ref="rootRef"
    class="strip"
    :class="{ active, loaded: isLoaded, 'mode-b': isModeB, selected, last, 'peek-mode': isPeek }"
    @click="emit('select', entry.id)"
    @mouseenter="onMouseEnter"
    @mouseleave="onMouseLeave"
  >
    <!-- Inner clipping wrapper. The visual content lives here so .strip
         itself can run with overflow: visible and host the ::after hover
         bridge that extends below the expanded card. -->
    <div class="strip-clip">
      <!-- Color identity skeleton — back layer, hidden once the card image
           loads on top of it. -->
      <div class="skeleton" :style="skeletonStyle"></div>

      <!-- The real card image. Positioned at top: 0, height: auto, so its
           natural rendered height (--card-image-h) overflows the collapsed
           strip and is clipped by .strip-clip's `overflow: hidden`. -->
      <img
        v-if="hasImage"
        class="card-img"
        :src="card.image_normal"
        :alt="card.name"
        @load="imageLoaded = true"
        @error="imgFailed = true"
      />

      <!-- Mode B only: floating corner badge (arc + gold quantity text).
           The base visual lives in CornerCountBadge; Mode B's fade-in /
           slide-to-bar animation is applied from the outer strip below
           via :deep() so the inner component stays mode-agnostic. -->
      <CornerCountBadge :count="entry.quantity" />

      <!-- Name + mana overlay (the "bar"). In Mode A this anchors via top
           and slides on load + hover. In Mode B + loaded it's hidden when
           collapsed and pops in at the bottom after the strip has finished
           expanding (transition-delay matches the expansion duration). -->
      <div class="overlay">
        <span class="qty">×{{ entry.quantity }}</span>
        <SetSymbol :set="card.set_code" :rarity="card.rarity || 'common'" :size="16" />
        <span class="name">{{ card.name || '—' }}</span>
        <span v-if="entry.foil" class="foil-badge">F</span>
        <ManaCost class="cost" :cost="card.mana_cost || ''" />
      </div>
    </div>

    <DfcPopover
      v-if="!isPeek && card.is_dfc && hovered && card.image_normal_back"
      :back-image="card.image_normal_back"
      :anchor="rootRef"
    />
  </div>
</template>

<style scoped>
.strip {
  position: relative;
  width: var(--card-width);
  height: var(--strip-height);
  margin-bottom: 4px;
  border-radius: 4.5%/3.2%;
  /* overflow: visible — clipping moved to .strip-clip so the ::after
     hover bridge can extend below the expanded card without being cut off. */
  cursor: pointer;
  background: var(--bg-2);
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.45);
  outline: 1px solid transparent;
  transition: height 160ms ease-out, margin-bottom 160ms ease-out,
              outline-color 120ms ease, box-shadow 160ms ease,
              transform 160ms ease-out;
  content-visibility: auto;
  contain-intrinsic-size: auto var(--card-width) auto var(--strip-height);
}
.strip:hover,
.strip.mode-b.loaded.last {
  height: var(--strip-expanded);
  margin-bottom: calc(4px + var(--strip-gap));
  z-index: 2;
  outline-color: var(--gold);
  box-shadow: 0 14px 28px rgba(0, 0, 0, 0.7);
}
/* Last card in Mode B stays expanded but shouldn't look "selected". */
.strip.mode-b.loaded.last:not(:hover) {
  outline-color: transparent;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.45);
}

/* Peek hover mode — strip stays compact; parent draws the popover. The
   subtle gold outline + scale lift on hover is preserved so the user
   still gets focus feedback on the row they're targeting. Margin is held
   at the default 4px so rows below don't shift when the hovered strip
   toggles — the scale transform zooms in place without affecting layout. */
.strip.peek-mode:hover,
.strip.peek-mode.mode-b.loaded.last {
  height: var(--strip-height);
  margin-bottom: 4px;
}
.strip.peek-mode:hover {
  z-index: 2;
  outline-color: var(--gold);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
  transform: scale(1.02);
}
/* Mode B + peek: strip never expands, so the overlay bar stays hidden
   at rest and on hover — the corner badge is the only label, matching
   mode B's collapsed appearance. Without this override the base mode-b
   rule at the bottom of this file would pop the bar in on hover. */
.strip.peek-mode.mode-b.loaded .overlay,
.strip.peek-mode.mode-b.loaded:hover .overlay {
  opacity: 0;
  transition: none;
}
/* Keep the corner badge locked in place in peek mode — the mode-b default
   slides it into the bar on hover, which we don't want when the bar stays
   hidden. */
.strip.peek-mode.mode-b.loaded:hover :deep(.qty-corner) {
  transform: translateY(0);
}
.strip.peek-mode.mode-b.loaded:hover :deep(.arc-bg) {
  opacity: 1;
}
.strip.active {
  outline-color: var(--gold-bright);
}
.strip.selected {
  outline-color: var(--gold);
  outline-width: 2px;
  box-shadow: 0 0 0 1px var(--gold), 0 2px 4px rgba(0, 0, 0, 0.45);
}

/* Inner clip layer. Fills the strip exactly and inherits the rounded
   corners; overflow: hidden contains the overflowing card image so we
   don't need it on .strip itself. */
.strip-clip {
  position: absolute;
  inset: 0;
  overflow: hidden;
  border-radius: inherit;
}

/* Hover bridge: a transparent extension of the strip's hover area that
   covers the gap to the next card. Without this, the cursor "falls
   through" the 12px gap between an expanded card and its neighbor and
   the hover state drops, causing the card to collapse before the user
   reaches the next one.

   pointer-events stays `none` at rest so resting cards don't intercept
   mouse events meant for their neighbors; only while .strip:hover is
   active does the bridge become click-through-blocking, which is enough
   to keep the hover alive while the cursor is in the gap. */
.strip::after {
  content: '';
  position: absolute;
  top: 100%;
  left: 0;
  right: 0;
  height: calc(4px + var(--strip-gap));
  pointer-events: none;
}
.strip:hover::after {
  pointer-events: auto;
}

.skeleton {
  position: absolute;
  inset: 0;
  z-index: 0;
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

/* ─────────────────────────────────────────────────────────────────────
   Bar overlay (.overlay)
   ───────────────────────────────────────────────────────────────────── */
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
.strip.loaded .overlay {
  top: calc(100% - var(--strip-height));
}

.qty {
  font-variant-numeric: tabular-nums;
  color: var(--gold);
  font-weight: 700;
  font-size: 13px;
  flex-shrink: 0;
  text-shadow:
    -1px -1px 0 #000,
     1px -1px 0 #000,
    -1px  1px 0 #000,
     1px  1px 0 #000,
     0    0   2px rgba(0, 0, 0, 0.9);
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
.foil-badge {
  flex-shrink: 0;
  font-size: 9px;
  font-weight: 700;
  line-height: 1;
  padding: 2px 4px;
  border-radius: 6px;
  color: #fff;
  background: linear-gradient(135deg, #a855f7, #3b82f6, #3ec97c);
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.6);
}
.cost {
  font-size: 14px;
  flex-shrink: 0;
}

/* ─────────────────────────────────────────────────────────────────────
   Mode B only — corner quantity badge with arc background
   The visual lives in CornerCountBadge; Mode B animations reach in
   via :deep() to drive opacity (fade-in) and transform (slide to the
   overlay bar on hover). Mode A leaves the badge hidden.
   ───────────────────────────────────────────────────────────────────── */

/* Hide the badge at rest — it only exists in Mode B. */
:deep(.corner-count-badge) {
  opacity: 0;
  transition: opacity 150ms ease-out;
}
:deep(.qty-corner) {
  transform: translateY(0);
  transition: transform 150ms ease-out;
}
:deep(.arc-bg) {
  transition: opacity 150ms ease-out;
}

.strip.mode-b.loaded :deep(.corner-count-badge) {
  opacity: 1;
}

/* On hover: counter slides into the bar's left edge, arc fades out.
   translateY = (bar-top - rest-top) + center-offset
              = (strip-expanded - strip-height - 3) + 9
              = strip-expanded - strip-height + 6 */
.strip.mode-b.loaded:hover :deep(.qty-corner),
.strip.mode-b.loaded.last :deep(.qty-corner) {
  transform: translateY(calc(var(--strip-expanded) - var(--strip-height) + 6px));
}
.strip.mode-b.loaded:hover :deep(.arc-bg),
.strip.mode-b.loaded.last :deep(.arc-bg) {
  opacity: 0;
}

/* In Mode B + loaded, the in-bar quantity is hidden but its width is
   preserved so the flex layout reserves the slot the .qty-corner slides
   into — set symbol / name / cost stay in the same x positions as Mode A. */
.strip.mode-b.loaded .overlay .qty {
  visibility: hidden;
}

/* Mode B + loaded: the bar is hidden when collapsed and pops in at the
   final bottom position after the strip's expansion finishes. Both opacity
   and `top` are made instantaneous-but-delayed (duration: 0, delay: 160ms)
   so neither slides — they snap to their hover values once the strip has
   finished growing. */
.strip.mode-b.loaded .overlay {
  opacity: 0;
  transition: opacity 0ms 0ms, top 0ms 0ms;
}
.strip.mode-b.loaded:hover .overlay {
  opacity: 1;
  transition: opacity 0ms 160ms, top 0ms 160ms;
}
/* Last card in Mode B: always show expanded — no delay needed. */
.strip.mode-b.loaded.last .overlay {
  opacity: 1;
  transition: none;
}
</style>
