<script setup>
import { computed, onBeforeUnmount, ref } from 'vue'
import { colorSkeletonStyle } from '../../composables/useColorSkeleton'
import CornerCountBadge from '../CornerCountBadge.vue'
import DfcPopover from '../DfcPopover.vue'
import SetSymbol from '../SetSymbol.vue'
import ManaCost from '../ManaCost.vue'

/**
 * Shared strip primitive used by CardStrip (Collection), DeckCardStrip
 * (Deck), and CatalogStrip (Catalog). Owns the common root/clip/image
 * layering, the overlay bar, image-load state, DFC-popover hover timing,
 * and the full Mode A / Mode B visual split.
 *
 * Wrappers pass domain-specific data (via `card` + `quantity`), decorate
 * the root with context classes (e.g. `shine-green`, `illegal-glow`,
 * `selected`), and add floating badges through named slots.
 */
const props = defineProps({
  card: { type: Object, required: true },
  quantity: { type: Number, default: 0 },
  // In-bar "×N" text. Collection shows it; Deck and Catalog hide it
  // because their quantity lives in a separate badge/corner instead.
  showQtyInBar: { type: Boolean, default: true },
  modeB: { type: Boolean, default: false },
  showCornerBadge: { type: Boolean, default: true },
  // When true, the corner badge is opaque and static regardless of
  // modeB — Catalog uses this so the owned-count badge is always
  // visible (no fade, no slide-into-bar on hover). In this mode the
  // overlay bar also stays at rest as in Mode A.
  cornerBadgeAlwaysVisible: { type: Boolean, default: false },
  // Independent count for the corner badge; Catalog uses owned_count
  // instead of the collection `quantity`.
  cornerCount: { type: Number, default: null },
  // 'expand' (default) — strip grows on hover to reveal the full card.
  // 'peek'            — strip stays compact; parent draws a popover.
  hoverMode: { type: String, default: 'expand' },
  draggable: { type: Boolean, default: false },
  showSkeleton: { type: Boolean, default: true },
  loadingLazy: { type: Boolean, default: false },
  // Collection's "last card stays expanded in Mode B".
  last: { type: Boolean, default: false },
})

const emit = defineEmits([
  'click',
  'dragstart',
  'dragend',
  'peek-show',
  'peek-hide',
])

const rootRef = ref(null)
const imgFailed = ref(false)
const imageLoaded = ref(false)
const hovered = ref(false)
let hoverTimer = null

const hasImage = computed(() => !!props.card?.image_normal && !imgFailed.value)
const isLoaded = computed(() => hasImage.value && imageLoaded.value)
const isPeek = computed(() => props.hoverMode === 'peek')
const effectiveCornerCount = computed(() =>
  props.cornerCount ?? props.quantity ?? 0,
)
const skeletonStyle = computed(() => colorSkeletonStyle(props.card?.colors))

function onClick(event) {
  emit('click', event)
}

function onMouseEnter(event) {
  if (isPeek.value) {
    emit('peek-show', {
      rect: event.currentTarget.getBoundingClientRect(),
    })
    return
  }
  if (!props.card?.is_dfc) return
  hoverTimer = setTimeout(() => { hovered.value = true }, 300)
}

function onMouseLeave() {
  if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
  hovered.value = false
  if (isPeek.value) emit('peek-hide')
}

function onDragStart(event) {
  emit('dragstart', event)
}
function onDragEnd(event) {
  emit('dragend', event)
}

onBeforeUnmount(() => {
  if (hoverTimer) { clearTimeout(hoverTimer); hoverTimer = null }
})
</script>

<template>
  <div
    ref="rootRef"
    class="strip"
    :class="{
      loaded: isLoaded,
      'mode-b': modeB,
      'corner-persistent': cornerBadgeAlwaysVisible,
      last,
      'peek-mode': isPeek,
    }"
    :draggable="draggable || null"
    @click="onClick"
    @dragstart="onDragStart"
    @dragend="onDragEnd"
    @mouseenter="onMouseEnter"
    @mouseleave="onMouseLeave"
  >
    <!-- Inner clipping wrapper. Content lives here so .strip itself can
         run with overflow: visible and host the ::after hover bridge
         that extends below the expanded card. -->
    <div class="strip-clip">
      <div v-if="showSkeleton" class="skeleton" :style="skeletonStyle"></div>

      <img
        v-if="hasImage"
        class="card-img"
        :src="card.image_normal"
        :alt="card.name"
        :loading="loadingLazy ? 'lazy' : null"
        :decoding="loadingLazy ? 'async' : null"
        @load="imageLoaded = true"
        @error="imgFailed = true"
      />

      <!-- Mode B only: floating corner badge (arc + gold quantity text).
           The base visual lives in CornerCountBadge; Mode B's fade-in /
           slide-to-bar animation is applied below via :deep() so the
           inner component stays mode-agnostic. -->
      <CornerCountBadge
        v-if="showCornerBadge"
        :count="effectiveCornerCount"
      />

      <!-- Name + mana overlay (the "bar"). In Mode A this anchors via
           top and slides on load + hover. In Mode B + loaded it's hidden
           when collapsed and pops in at the bottom after the strip has
           finished expanding (transition-delay matches the duration). -->
      <div class="overlay">
        <span v-if="showQtyInBar" class="qty">×{{ quantity }}</span>
        <SetSymbol :set="card.set_code" :rarity="card.rarity || 'common'" :size="16" />
        <span class="name">{{ card.name || '—' }}</span>
        <slot name="overlay-extras" />
        <ManaCost v-if="card.mana_cost" class="cost" :cost="card.mana_cost" />
      </div>

      <!-- Wrapper-supplied floating badges (qty-badge, gc-badge,
           badge-wanted, etc.) render above the overlay bar. -->
      <slot name="badges" />
    </div>

    <DfcPopover
      v-if="!isPeek && card.is_dfc && hovered && card.image_normal_back"
      :back-image="card.image_normal_back"
      :anchor="rootRef"
    />

    <slot name="menu" />
  </div>
</template>

<style scoped>
.strip {
  position: relative;
  width: var(--card-width);
  height: var(--strip-height);
  margin-bottom: 4px;
  /* Percent vertical radius collapses to ~1px at strip height; key
     both radii to card-width to keep the Scryfall card curve visible
     on the top corners. */
  border-radius: calc(var(--card-width) * 0.045);
  /* overflow: visible — clipping is on .strip-clip so the ::after
     hover bridge can extend below the expanded card. */
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
   still gets focus feedback on the row they're targeting. */
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
   at rest and on hover — the corner badge is the only label. */
.strip.peek-mode.mode-b.loaded .overlay,
.strip.peek-mode.mode-b.loaded:hover .overlay {
  opacity: 0;
  transition: none;
}
.strip.peek-mode.mode-b.loaded:hover :deep(.qty-corner) {
  transform: translateY(0);
}
.strip.peek-mode.mode-b.loaded:hover :deep(.arc-bg) {
  opacity: 1;
}

/* Inner clip layer. Fills the strip exactly and inherits the rounded
   corners; overflow: hidden contains the overflowing card image. */
.strip-clip {
  position: absolute;
  inset: 0;
  overflow: hidden;
  border-radius: inherit;
}

/* Hover bridge: a transparent extension of the strip's hover area
   covering the gap to the next card. Without this, the cursor "falls
   through" the gap and the hover state drops, collapsing the card
   before the user reaches the next one.

   pointer-events is `none` at rest so resting cards don't intercept
   mouse events meant for their neighbours; while .strip:hover is
   active the bridge blocks clicks, which is enough to keep the hover
   alive while the cursor is in the gap. */
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
.cost {
  font-size: 14px;
  flex-shrink: 0;
}

/* ─────────────────────────────────────────────────────────────────────
   Mode B — corner quantity badge with arc background.
   The visual lives in CornerCountBadge; Mode B animations reach in via
   :deep() to drive opacity (fade-in) and transform (slide to the
   overlay bar on hover). Mode A leaves the badge hidden.

   These rules MUST live on the component that renders CornerCountBadge
   (this one) so the scoped :deep() selector resolves correctly.
   ───────────────────────────────────────────────────────────────────── */

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
   translateY = (bar-top - rest-top) + centre-offset
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
   final bottom position after the strip's expansion finishes. Both
   opacity and `top` are made instantaneous-but-delayed (duration 0,
   delay 160ms) so neither slides — they snap to their hover values once
   the strip has finished growing. */
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

/* ─────────────────────────────────────────────────────────────────────
   Catalog "always-on" corner badge (cornerBadgeAlwaysVisible prop).
   Owned-count stays visible and static regardless of A/B; the overlay
   bar stays anchored to the bottom like Mode A. These rules override
   the Mode B fade / slide / hide-bar behaviour when both flags mix.
   ───────────────────────────────────────────────────────────────────── */
.strip.corner-persistent :deep(.corner-count-badge) {
  opacity: 1;
}
.strip.corner-persistent :deep(.qty-corner) {
  transform: translateY(0);
}
.strip.corner-persistent :deep(.arc-bg) {
  opacity: 1;
}
.strip.corner-persistent.loaded .overlay,
.strip.corner-persistent.loaded:hover .overlay {
  opacity: 1;
  transition: top 200ms ease-out;
}
.strip.corner-persistent.loaded .overlay .qty {
  visibility: visible;
}
</style>
