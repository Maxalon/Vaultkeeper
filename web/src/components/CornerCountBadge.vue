<script setup>
/**
 * Gold, outlined-text count in the top-left corner with a soft radial
 * fade behind it. Same visual used in the collection Mode B corner and
 * the catalog card tiles / strips.
 *
 * Caller positions via the badge's parent having `position: relative`;
 * the badge itself is absolutely positioned in the top-left. Parent-
 * owned animations (fade-in, slide-to-bar, etc.) use `:deep(.arc-bg)`
 * and `:deep(.qty-corner)` to reach in.
 */
defineProps({
  count: { type: Number, required: true },
})
</script>

<template>
  <div v-if="count > 0" class="corner-count-badge">
    <div class="arc-bg" aria-hidden="true"></div>
    <span class="qty-corner">×{{ count }}</span>
  </div>
</template>

<style scoped>
.corner-count-badge {
  position: absolute;
  top: 0;
  left: 0;
  /* Box is intentionally bigger than the old 32×32 so the gradient's
     100% stop lands *inside* the box (no square seam on bright frames)
     AND the dark mid of the gradient falls behind the text. At 32×32 /
     circle 30px, the text sat at ~57% of the radius — nearly transparent
     by the time it reached the number. 40×40 / circle 36px puts the text
     at ~47% of the radius where the dark stop gives proper contrast. */
  width: 40px;
  height: 40px;
  pointer-events: none;
  z-index: 3;
}

/*
 * Soft dark halo behind the number. Pinning the gradient size to
 * `circle 36px` forces the 100% (fully-transparent) stop to land ~4px
 * inside the 40px box's edge, so the gradient fades smoothly to fully
 * transparent before reaching any edge — no visible seam on Aetherdrift
 * gold borders or other bright frames.
 */
.arc-bg {
  position: absolute;
  inset: 0;
  background: radial-gradient(
    circle 36px at top left,
    rgba(0, 0, 0, 0.95) 0%,
    rgba(0, 0, 0, 0.85) 35%,
    rgba(0, 0, 0, 0.45) 70%,
    rgba(0, 0, 0, 0) 100%
  );
}

.qty-corner {
  position: absolute;
  top: 3px;
  left: 5px;
  font-size: 12px;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  color: var(--vk-gold);
  /* 1px outline via 4-direction text-shadow. text-stroke would be
     cleaner but webkit-only. */
  text-shadow:
    -1px -1px 0 #000,
     1px -1px 0 #000,
    -1px  1px 0 #000,
     1px  1px 0 #000,
     0    0   2px rgba(0, 0, 0, 0.9);
}
</style>
