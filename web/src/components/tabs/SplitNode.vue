<script setup>
import { ref } from 'vue'
import { useTabsStore } from '../../stores/tabs'
import PanelNode from './PanelNode.vue'

const props = defineProps({
  node: { type: Object, required: true },
})

const tabs = useTabsStore()
const root = ref(null)
let pointerActive = false

function onPointerDown(e) {
  if (e.button !== 0) return
  pointerActive = true
  e.preventDefault()
  window.addEventListener('pointermove', onPointerMove)
  window.addEventListener('pointerup', onPointerUp, { once: true })
}

function onPointerMove(e) {
  if (!pointerActive || !root.value) return
  const rect = root.value.getBoundingClientRect()
  const ratio = props.node.direction === 'horizontal'
    ? (e.clientX - rect.left) / rect.width
    : (e.clientY - rect.top)  / rect.height
  tabs.resizePanel(props.node.id, ratio)
}

function onPointerUp() {
  pointerActive = false
  window.removeEventListener('pointermove', onPointerMove)
}
</script>

<template>
  <div
    ref="root"
    class="split-node"
    :class="node.direction === 'horizontal' ? 'split-horizontal' : 'split-vertical'"
  >
    <div class="split-child" :style="sizeStyle(node, 'left')">
      <PanelNode :node="node.left" />
    </div>
    <div
      class="split-handle"
      :class="node.direction === 'horizontal' ? 'handle-vertical-bar' : 'handle-horizontal-bar'"
      @pointerdown="onPointerDown"
    />
    <div class="split-child" :style="sizeStyle(node, 'right')">
      <PanelNode :node="node.right" />
    </div>
  </div>
</template>

<script>
function sizeStyle(node, which) {
  const pct = which === 'left' ? node.splitAt : 1 - node.splitAt
  return node.direction === 'horizontal'
    ? { width: `calc(${pct * 100}% - 3px)` }
    : { height: `calc(${pct * 100}% - 3px)` }
}
</script>

<style>
.split-node {
  display: flex;
  width: 100%;
  height: 100%;
  min-width: 0;
  min-height: 0;
}
.split-horizontal { flex-direction: row; }
.split-vertical   { flex-direction: column; }
.split-child {
  min-width: 0;
  min-height: 0;
  overflow: hidden;
}
.split-handle {
  background: var(--vk-border, #33312c);
  flex: 0 0 6px;
}
.handle-vertical-bar   { cursor: col-resize; }
.handle-horizontal-bar { cursor: row-resize; }
.split-handle:hover { background: var(--vk-accent, #c99d3d); }
</style>
