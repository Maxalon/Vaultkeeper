<script setup>
import SplitNode from './SplitNode.vue'
import LeafNode from './LeafNode.vue'

defineProps({
  node: { type: Object, required: true },
})
</script>

<template>
  <SplitNode v-if="node.type === 'split'" :node="node" />
  <LeafNode v-else :node="node" />
</template>
