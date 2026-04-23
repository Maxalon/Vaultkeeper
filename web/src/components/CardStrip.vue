<script setup>
import { useSettingsStore } from '../stores/settings'
import BaseCardStrip from './strip/BaseCardStrip.vue'

/**
 * Collection-view strip. Thin wrapper over BaseCardStrip: pulls the
 * scryfall card out of a collection entry, forwards the A/B mode from
 * settings, adds the foil-badge slot and the selected/active outlines.
 */
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

function onPeekShow({ rect }) {
  emit('peek-show', { entry: props.entry, rect })
}
</script>

<template>
  <BaseCardStrip
    :card="entry.card || {}"
    :quantity="entry.quantity"
    :mode-b="settings.displayMode === 'B'"
    :hover-mode="hoverMode"
    :last="last"
    :class="{ active, selected }"
    @click="emit('select', entry.id)"
    @peek-show="onPeekShow"
    @peek-hide="emit('peek-hide')"
  >
    <template #overlay-extras>
      <span v-if="entry.foil" class="foil-badge">F</span>
    </template>
  </BaseCardStrip>
</template>

<style scoped>
/* Collection-specific outlines. Base already sets a transparent outline
   and overrides the colour on hover / Mode B; these rules layer on top
   for the selection and active states unique to the collection panel. */
.strip.active {
  outline-color: var(--gold-bright);
}
.strip.selected {
  outline-color: var(--gold);
  outline-width: 2px;
  box-shadow: 0 0 0 1px var(--gold), 0 2px 4px rgba(0, 0, 0, 0.45);
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
</style>
