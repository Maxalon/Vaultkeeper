<script setup>
import { useTabsStore } from '../../stores/tabs'
import PanelNode from './PanelNode.vue'

const tabs = useTabsStore()
</script>

<template>
  <div class="tab-system">
    <PanelNode :node="tabs.root" />
  </div>
</template>

<style>
.tab-system {
  position: relative;
  width: 100%;
  height: 100%;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
</style>
