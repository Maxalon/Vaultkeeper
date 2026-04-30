<script setup>
import { computed, ref } from 'vue'
import { assetUrl } from '../lib/assets.js'

const props = defineProps({
  symbol: { type: String, required: true }, // e.g. "{W}", "{2/W}", "{T}"
})

// Backend stores symbols at /symbols/{NAME}.svg (under the assets host)
// with braces stripped AND slashes flattened — see SyncSets command.
// {2/W} → 2W.svg.
const clean = computed(() => props.symbol.replace(/[{}/]/g, ''))
const src = computed(() => assetUrl(`/symbols/${clean.value}.svg`))
const failed = ref(false)
</script>

<template>
  <img
    v-if="!failed"
    :src="src"
    :alt="symbol"
    :title="symbol"
    class="mana-symbol"
    @error="failed = true"
  />
  <span v-else class="mana-symbol-fallback" :title="symbol">{{ clean }}</span>
</template>

<style scoped>
.mana-symbol {
  display: inline-block;
  width: 1em;
  height: 1em;
  vertical-align: -0.15em;
  margin: 0 1px;
}
.mana-symbol-fallback {
  display: inline-block;
  width: 1em;
  height: 1em;
  font-size: 0.7em;
  border-radius: 50%;
  background: #555;
  color: #eee;
  text-align: center;
  line-height: 1em;
  vertical-align: -0.15em;
}
</style>
