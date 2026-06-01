<script setup>
import { computed, ref } from 'vue'
import IconSearch from '../assets/icons/search.svg'

const props = defineProps({
  modelValue: { type: String, default: '' },
  placeholder: { type: String, default: 'Syntax: c:red t:creature r:mythic sort:rarity…' },
})

const emit = defineEmits(['update:modelValue', 'help'])

const focused = ref(false)

const tokens = computed(() => {
  return props.modelValue.split(/(\s+)/).map((part, i) => {
    if (/^\s+$/.test(part)) return { kind: 'space', text: part, i }
    const m = part.match(/^(-?)(\w+)(:|>=|<=|>|<|=|!=)(.+)$/)
    if (m) return { kind: 'op', neg: m[1], key: m[2], oper: m[3], val: m[4], text: part, i }
    if (part) return { kind: 'text', text: part, i }
    return { kind: 'empty', text: '', i }
  })
})

function onInput(event) {
  emit('update:modelValue', event.target.value)
}
</script>

<template>
  <div class="vk-syntax-search" :class="{ focused }">
    <IconSearch class="vk-syntax-icon" style="color: var(--ink-50); flex-shrink: 0;" />
    <div class="vk-syntax-field">
      <div class="vk-syntax-tokens" aria-hidden="true">
        <template v-for="t in tokens" :key="t.i">
          <span v-if="t.kind === 'space'" class="sp">{{ t.text }}</span>
          <span v-else-if="t.kind === 'op'" class="tok" :class="'tok-' + t.key">
            <span v-if="t.neg" class="tok-neg">-</span>
            <span class="tok-key">{{ t.key }}</span>
            <span class="tok-op">{{ t.oper }}</span>
            <span class="tok-val">{{ t.val }}</span>
          </span>
          <span v-else-if="t.kind === 'text'" class="tok tok-text">{{ t.text }}</span>
        </template>
      </div>
      <input
        class="vk-syntax-input"
        name="search"
        type="search"
        autocomplete="off"
        :value="modelValue"
        :placeholder="placeholder"
        spellcheck="false"
        @input="onInput"
        @focus="focused = true"
        @blur="focused = false"
      />
    </div>
    <button class="vk-syntax-help" title="Syntax reference" type="button" @click="emit('help')">
      <span style="font-family: var(--font-mono), monospace; font-size: 10px; font-weight: 600; letter-spacing: 0.06em;">?</span>
    </button>
  </div>
</template>
