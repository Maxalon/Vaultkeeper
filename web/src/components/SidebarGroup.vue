<script setup>
import { inject, ref } from 'vue'
import { useSettingsStore } from '../stores/settings'
import { useCollectionStore } from '../stores/collection'
import { confirm as confirmDialog } from '../composables/useConfirm'
import { useSidebarSortable } from '../composables/useSidebarSortable'
import SidebarRow from './SidebarRow.vue'
import GroupModal from './GroupModal.vue'
import IconChevron from '../assets/chevron-down.svg'
import IconEdit from '../assets/icons/edit.svg'

const props = defineProps({
  group: { type: Object, required: true },
})

const settings = useSettingsStore()
const collection = useCollectionStore()
const ctx = inject('sidebarCtx')

const modalOpen = ref(false)

function itemKey(item) {
  return `${item.kind}:${item.id}`
}

function groupCardCount(g) {
  return (g.children || []).reduce((sum, child) => {
    if (child.kind === 'deck') return sum + (child.entry_count || 0)
    if (child.kind === 'location') return sum + (child.card_count || 0)
    if (child.kind === 'group') return sum + groupCardCount(child)
    return sum
  }, 0)
}
function groupCounterValue(g) {
  if (settings.sidebarGroupCounter === 'locations') {
    return (g.children || []).filter((c) => c.kind !== 'group').length
  }
  return groupCardCount(g)
}

async function deleteGroup() {
  const ok = await confirmDialog({
    title: `Delete group "${props.group.name}"?`,
    message: 'Its locations will become ungrouped.',
    confirmText: 'Delete',
    destructive: true,
  })
  if (!ok) return
  await collection.deleteGroup(props.group.id)
}

const childrenContainer = ref(null)
// Destination parent is read off the container's `data-parent-id` attr in
// the composable's onEnd handler, so we don't need to thread the group id
// through here.
useSidebarSortable(childrenContainer)
</script>

<template>
  <div
    class="group-section"
    data-kind="group"
    :data-id="group.id"
  >
    <div
      class="group-header"
      :class="{ collapsed: ctx.isCollapsed(group.id) }"
      @click="ctx.toggleCollapse(group.id)"
    >
      <span v-if="settings.sidebarShowDrag" class="drag drag-handle group-handle" @click.stop title="Drag">⠿</span>
      <span class="chev" :class="{ rotated: !ctx.isCollapsed(group.id) }">
        <IconChevron />
      </span>
      <span class="label">{{ group.name }}</span>
      <span v-if="settings.sidebarGroupCounter !== 'off'" class="num">{{ groupCounterValue(group) }}</span>
      <span class="group-actions" @click.stop>
        <button v-if="settings.sidebarShowEdit" type="button" class="edit-btn" @click="modalOpen = true" title="Edit group">
          <IconEdit />
        </button>
        <button v-if="settings.sidebarShowDelete" type="button" class="delete-btn" @click="deleteGroup" title="Delete">×</button>
      </span>
    </div>

    <div
      v-show="!ctx.isCollapsed(group.id)"
      ref="childrenContainer"
      class="group-locations"
      data-sidebar-container="group"
      :data-parent-id="group.id"
    >
      <template v-for="child in group.children" :key="itemKey(child)">
        <SidebarGroup v-if="child.kind === 'group'" :group="child" />
        <SidebarRow v-else :item="child" :nested="true" />
      </template>
    </div>

    <GroupModal v-if="modalOpen" :group="group" @close="modalOpen = false" />
  </div>
</template>

<style scoped>
.group-section {
  display: flex;
  flex-direction: column;
  margin-top: 3px;
}
.group-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  min-height: 28px;
  box-sizing: border-box;
  border-radius: var(--radius-sm);
  color: var(--ink-70);
  font-size: 12px;
  line-height: 1.2;
  font-weight: 600;
  letter-spacing: 0.02em;
  cursor: pointer;
  transition: color 0.1s ease, background 0.1s ease;
  user-select: none;
}
.group-header.collapsed {
  padding: 2px 10px;
  min-height: 20px;
}
.group-header:hover {
  color: var(--ink-100);
  background: var(--bg-2);
}
.group-header .chev {
  display: inline-flex;
  width: 12px;
  height: 12px;
  align-items: center;
  justify-content: center;
  color: var(--ink-50);
  transition: transform 0.15s ease;
  transform: rotate(-90deg);
}
.group-header .chev.rotated { transform: rotate(0deg); }
.group-header .label {
  flex: 1;
  text-align: left;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.group-header .num {
  font-family: var(--font-mono), monospace;
  font-size: 11px;
  color: var(--ink-50);
  font-weight: 500;
  padding: 1px 8px;
  background: var(--bg-2);
  border-radius: 999px;
}
.group-rename-input {
  flex: 1;
  background: var(--bg-0);
  border: 1px solid var(--amber-lo);
  color: var(--ink-100);
  padding: 3px 6px;
  font-size: 12px;
  font-weight: 600;
  border-radius: 2px;
  outline: none;
  min-width: 0;
}
.group-actions {
  display: none;
  flex-shrink: 0;
  gap: 4px;
  align-items: center;
}
.group-header:hover .group-actions { display: flex; }
.group-header .drag {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: var(--ink-30);
  opacity: 0;
  width: 0;
  margin-left: -6px;
  margin-right: 0;
  cursor: grab;
  font-size: 12px;
  line-height: 1;
  user-select: none;
  padding: 0;
  flex-shrink: 0;
  overflow: hidden;
  background: transparent;
  border: 0;
  transition: opacity 0.12s ease, width 0.15s ease, margin 0.15s ease, color 0.1s ease;
}
.group-header:hover .drag {
  opacity: 0.6;
  width: 10px;
  margin-right: -2px;
}
.group-header .drag:hover {
  opacity: 1;
  color: var(--ink-100);
}
.group-actions .edit-btn,
.group-actions .delete-btn {
  font-size: 12px;
  padding: 1px;
}
.group-actions .edit-btn :where(svg) {
  width: 10px;
  height: 10px;
}
.edit-btn,
.delete-btn {
  background: transparent;
  border: 0;
  color: var(--ink-30);
  padding: 2px;
  border-radius: 3px;
  cursor: pointer;
  display: flex;
  align-items: center;
  font-size: 14px;
  line-height: 1;
}
.edit-btn:hover { color: var(--ink-100); }
.delete-btn:hover { color: #d46a6a; }

.group-locations {
  position: relative;
  padding-left: 12px;
  margin-top: 2px;
  min-height: 8px;
}
.group-locations::before {
  content: '';
  position: absolute;
  left: 16px;
  top: 4px;
  bottom: 4px;
  width: 1px;
  background: repeating-linear-gradient(to bottom, var(--hairline) 0 3px, transparent 3px 6px);
}
</style>
