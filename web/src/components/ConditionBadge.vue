<script setup>
const props = defineProps({
  value: { type: String, required: true }, // NM | LP | MP | HP | DMG
})
</script>

<template>
  <span class="condition-badge" :class="`cond-${value.toLowerCase()}`">{{ value }}</span>
</template>

<style scoped>
.condition-badge {
  display: inline-block;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.05em;
  padding: 2px 6px;
  border-radius: 3px;
  color: #0d0f14;
  min-width: 28px;
  text-align: center;
}
.cond-nm  { background: var(--cond-nm); }
.cond-lp  { background: var(--cond-lp); }
.cond-mp  { background: var(--cond-mp); }
.cond-hp  { background: var(--cond-hp); }
.cond-dmg { background: var(--cond-dmg); color: #fff; }
</style>
