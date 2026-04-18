<script setup>
defineProps({ small: { type: Boolean, default: false } })
</script>

<template>
  <span class="vk-mark" :class="{ small }">
    <span class="vk-mark-icon" />
    Vaultkeeper
  </span>
</template>

<style scoped>
.vk-mark.small { font-size: 16px; }
.vk-mark.small .vk-mark-icon { width: 14px; height: 14px; }
</style>
