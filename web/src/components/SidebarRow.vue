<script setup>
import { inject } from 'vue'
import { useSettingsStore } from '../stores/settings'
import IconDrawer from '../assets/icons/drawer.svg'
import IconBinder from '../assets/icons/binder.svg'
import IconDeck from '../assets/icons/deck.svg'
import IconEdit from '../assets/icons/edit.svg'

const props = defineProps({
  item: { type: Object, required: true },
  nested: { type: Boolean, default: false },
})

const settings = useSettingsStore()
const ctx = inject('sidebarCtx')

function formatShort(f) {
  return ({ commander: 'CMDR', oathbreaker: 'OATH', pauper: 'PAU', standard: 'STD', modern: 'MOD' })[f]
    || (f || '').toUpperCase()
}
function shouldShowCount(loc) {
  if (loc.kind === 'deck') return settings.sidebarShowCountDeck
  if (loc.type === 'drawer') return settings.sidebarShowCountDrawer
  if (loc.type === 'binder') return settings.sidebarShowCountBinder
  return true
}
function onClick() {
  if (props.item.kind === 'deck') ctx.openDeck(props.item)
  else ctx.activate(props.item)
}
function isActive() {
  return props.item.kind === 'deck' ? ctx.activeDeckId() === props.item.deck_id : ctx.isActive(props.item)
}
</script>

<template>
  <button
    type="button"
    class="loc-row sidebar-item"
    :class="{ nested, 'sidebar-deck': item.kind === 'deck', active: isActive() }"
    @click="onClick"
  >
    <span v-if="settings.sidebarShowDrag" class="drag drag-handle" @click.stop>⠿</span>
    <span class="set-sym loc-icon" aria-hidden="true">
      <IconDeck v-if="item.kind === 'deck'" />
      <IconDrawer v-else-if="item.type === 'drawer'" />
      <IconBinder v-else-if="item.type === 'binder'" />
    </span>
    <span class="label">{{ item.name }}</span>
    <span v-if="item.kind === 'deck' && settings.sidebarShowFormatBadge" class="format-badge">
      {{ formatShort(item.format) }}
    </span>
    <span v-if="shouldShowCount(item)" class="num">
      {{ item.kind === 'deck' ? item.entry_count : item.card_count }}
    </span>
    <span v-if="settings.sidebarShowEdit" class="edit" @click.stop>
      <button type="button" class="edit-btn" @click="ctx.openEdit(item)" title="Edit">
        <IconEdit />
      </button>
    </span>
    <span v-if="settings.sidebarShowDelete" class="del" @click.stop>
      <button type="button" class="delete-btn" @click="ctx.deleteRow(item)" title="Delete">×</button>
    </span>
  </button>
</template>

<style scoped>
.sidebar-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 8px 10px;
  border-radius: var(--radius-sm);
  background: transparent;
  border: 0;
  color: var(--ink-70);
  font-size: 13px;
  text-align: left;
  cursor: pointer;
  transition: all 0.1s ease;
  font-family: var(--font-sans), sans-serif;
}
.sidebar-item:hover {
  background: var(--bg-2);
  color: var(--ink-100);
}
.sidebar-item.active {
  background: var(--bg-2);
  color: var(--ink-100);
}
.sidebar-item.active::before {
  content: '';
  position: absolute;
  left: 0;
  top: 8px;
  bottom: 8px;
  width: 2px;
  background: var(--amber);
  border-radius: 0 2px 2px 0;
}
.sidebar-item .set-sym {
  width: 16px;
  height: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--ink-50);
  flex-shrink: 0;
}
.sidebar-item.nested .set-sym { color: var(--ink-30); }
.sidebar-item.nested.active .set-sym { color: var(--ink-70); }
.sidebar-item .label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sidebar-item .num {
  font-family: var(--font-mono), monospace;
  font-size: 11px;
  color: var(--ink-50);
  letter-spacing: 0.02em;
  padding: 1px 8px;
  background: var(--bg-2);
  border-radius: 999px;
  min-width: 26px;
  text-align: center;
  flex-shrink: 0;
}
.sidebar-item.active .num {
  background: var(--amber);
  color: #1a1408;
  font-weight: 600;
}
.sidebar-deck .format-badge {
  font-family: var(--font-mono), monospace;
  font-size: 9px;
  color: var(--ink-50);
  background: var(--bg-2);
  padding: 1px 5px;
  border-radius: 3px;
  letter-spacing: 0.04em;
}

.sidebar-item .drag,
.sidebar-item .edit,
.sidebar-item .del {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--ink-30);
  opacity: 0;
  transition: opacity 0.12s ease, width 0.15s ease, margin 0.15s ease, color 0.1s ease;
  flex-shrink: 0;
  overflow: hidden;
  background: transparent;
  border: 0;
}
.sidebar-item .drag {
  width: 0;
  margin-left: -6px;
  margin-right: 0;
  cursor: grab;
  font-size: 14px;
  user-select: none;
  padding: 0;
}
.sidebar-item .edit,
.sidebar-item .del {
  width: 0;
  margin-left: 0;
  margin-right: -4px;
  padding: 0;
}
.sidebar-item:hover .drag {
  opacity: 0.6;
  width: 10px;
  margin-right: -2px;
}
.sidebar-item:hover .edit,
.sidebar-item:hover .del {
  opacity: 0.6;
  width: 14px;
  margin-left: 2px;
}
.sidebar-item .drag:hover,
.sidebar-item .edit:hover,
.sidebar-item .del:hover {
  opacity: 1;
  color: var(--ink-100);
}
.sidebar-item .del:hover { color: #d46a6a; }
.sidebar-item .edit-btn,
.sidebar-item .delete-btn {
  background: transparent;
  border: 0;
  color: inherit;
  padding: 0;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}
.sidebar-item .delete-btn {
  font-size: 16px;
  line-height: 1;
}
</style>
