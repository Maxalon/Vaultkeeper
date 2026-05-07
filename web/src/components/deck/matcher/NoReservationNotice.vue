<script setup>
/**
 * NoReservationNotice — reusable inline notice documenting the absence of a
 * reservation system in v1.
 *
 * Vaultkeeper shows all available copies to everyone who wants a card. There
 * is no way to "hold" or reserve a copy. This component surfaces that fact
 * clearly near any place that shows friend matches, so users aren't surprised
 * when they reach out and the card is already spoken for.
 *
 * Props
 *  compact — when true, renders as a single line; full mode adds a sub-line
 *            explaining the implication. Default: false.
 */
import HelpHint from '../../HelpHint.vue'

defineProps({
  compact: {
    type: Boolean,
    default: false,
  },
})
</script>

<template>
  <div class="nrn" :class="{ 'nrn--compact': compact }">
    <span class="nrn-icon" aria-hidden="true">ℹ</span>
    <span class="nrn-body">
      <span class="nrn-lead">No reservation system in v1.</span>
      <span v-if="!compact" class="nrn-sub">
        If a friend has one copy and multiple people want it, everyone sees it
        here. Reach out directly to confirm before assuming it's available.
      </span>
    </span>
    <HelpHint
      text="Vaultkeeper shows ALL available copies to everyone whose deck wants the card. There is no way to hold or reserve a copy — coordination happens out-of-band. This is by design in v1."
      position="top"
      :width="260"
    />
  </div>
</template>

<style scoped>
.nrn {
  display: flex;
  align-items: flex-start;
  gap: 7px;
  padding: 8px 12px;
  background: color-mix(in srgb, var(--amber, #c9a552) 6%, transparent);
  border: 1px solid color-mix(in srgb, var(--amber, #c9a552) 20%, transparent);
  border-radius: 5px;
  font-family: var(--font-sans), sans-serif;
}

.nrn--compact {
  padding: 5px 10px;
  align-items: center;
}

.nrn-icon {
  flex-shrink: 0;
  font-size: 12px;
  color: var(--amber, #c9a552);
  line-height: 1.5;
  margin-top: 1px;
}
.nrn--compact .nrn-icon {
  margin-top: 0;
  font-size: 11px;
}

.nrn-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  font-size: 11px;
  color: var(--ink-70);
  line-height: 1.45;
}
.nrn--compact .nrn-body {
  flex-direction: row;
  align-items: center;
  gap: 5px;
  flex-wrap: wrap;
}

.nrn-lead {
  font-weight: 600;
  color: var(--ink-100);
}

.nrn-sub {
  color: var(--ink-50);
  font-size: 11px;
}
</style>
