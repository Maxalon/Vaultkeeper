<script setup>
import { computed, ref, watch } from 'vue'
import { assetUrl } from '../lib/assets.js'

const props = defineProps({
  set: { type: String, required: true },
  rarity: { type: String, default: 'common' }, // common|uncommon|rare|mythic
  size: { type: Number, default: 18 },
})

// mtg-vectors ships per-rarity files: C.svg / U.svg / R.svg / M.svg
const rarityLetter = computed(() => {
  switch ((props.rarity || '').toLowerCase()) {
    case 'mythic':   return 'M'
    case 'rare':     return 'R'
    case 'uncommon': return 'U'
    default:         return 'C'
  }
})

// Files on disk are uppercase (LEA, FDN, ...). Force uppercase here so a
// case-mismatched API response (or a hand-typed set_code) still resolves.
const setUpper = computed(() => (props.set || '').toString().toUpperCase())
const src = computed(() =>
  assetUrl(`/sets/${setUpper.value}/${rarityLetter.value}.svg`),
)

const failed = ref(false)
watch(src, () => { failed.value = false })
</script>

<template>
  <img
    v-if="!failed"
    :src="src"
    :alt="`${set} ${rarity}`"
    :title="`${set?.toUpperCase()} · ${rarity}`"
    class="set-symbol"
    :style="{ width: size + 'px', height: size + 'px' }"
    @error="failed = true"
  />
  <span
    v-else
    class="set-symbol fallback"
    :style="{ width: size + 'px', height: size + 'px', lineHeight: size + 'px' }"
    :title="`${set?.toUpperCase()} · ${rarity}`"
  >?</span>
</template>

<style scoped>
.set-symbol {
  display: inline-block;
  vertical-align: middle;
  flex-shrink: 0;
  filter: drop-shadow(0 0 1px rgba(255, 255, 255, 0.8));
}
.set-symbol.fallback {
  background: #5b21b6;
  color: #fff;
  font-weight: 700;
  text-align: center;
  border-radius: 50%;
  font-size: 12px;
}
</style>
