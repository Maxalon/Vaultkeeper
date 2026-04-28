<script setup>
import VaultMarkIcon from '../assets/icons/vault-mark.svg'

defineProps({
  small: { type: Boolean, default: false },
  // `compact` hides the wordmark text and only renders the icon — used
  // when the sidebar collapses and the brand area shrinks to a strip
  // that still needs to surface the logo + expand control.
  compact: { type: Boolean, default: false },
})
</script>

<template>
  <span class="vk-mark" :class="{ small, compact }">
    <VaultMarkIcon class="vk-mark-icon" />
    <span v-if="!compact" class="vk-mark-text">Vaultkeeper</span>
  </span>
</template>

<style scoped>
.vk-mark.small { font-size: 16px; }
.vk-mark.small .vk-mark-icon { width: 14px; height: 14px; }
.vk-mark.compact { gap: 0; }
</style>
