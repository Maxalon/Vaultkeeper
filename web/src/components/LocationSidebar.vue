<script setup>
import { ref, nextTick } from 'vue'
import draggable from 'vuedraggable'
import { useCollectionStore } from '../stores/collection'
import LocationModal from './LocationModal.vue'
import ImportModal from './ImportModal.vue'
import IconAllCards from '../assets/icons/all-cards.svg'
import IconDrawer from '../assets/icons/drawer.svg'
import IconBinder from '../assets/icons/binder.svg'
import IconDeck from '../assets/icons/deck.svg'
import IconEdit from '../assets/icons/edit.svg'
import IconChevron from '../assets/chevron-down.svg'

const collection = useCollectionStore()
const importOpen = ref(false)
const modalOpen = ref(false)
const editingLocation = ref(null)

const creatingGroup = ref(false)
const newGroupName = ref('')
const groupInputRef = ref(null)

const editingGroupId = ref(null)
const editingGroupName = ref('')
const groupRenameInputRef = ref(null)

function isActive(loc) {
  return loc.id === collection.activeLocationId
}

function activate(loc) {
  collection.setActiveLocation(loc.id)
}

function showAll() {
  collection.setActiveLocation(null)
}

function openCreate() {
  editingLocation.value = null
  modalOpen.value = true
}

function openEdit(loc) {
  editingLocation.value = loc
  modalOpen.value = true
}

function closeModal() {
  modalOpen.value = false
  editingLocation.value = null
}

function toggleCollapse(groupId) {
  collection.toggleGroupCollapse(groupId)
}

function isCollapsed(groupId) {
  return collection.isGroupCollapsed(groupId)
}

function groupCardCount(group) {
  return group.locations.reduce((sum, l) => sum + (l.card_count || 0), 0)
}

async function startCreateGroup() {
  creatingGroup.value = true
  newGroupName.value = ''
  await nextTick()
  groupInputRef.value?.focus()
}

async function confirmCreateGroup() {
  const name = newGroupName.value.trim()
  if (name) {
    await collection.createGroup(name)
  }
  creatingGroup.value = false
  newGroupName.value = ''
}

function cancelCreateGroup() {
  creatingGroup.value = false
  newGroupName.value = ''
}

async function startEditGroup(group) {
  editingGroupId.value = group.id
  editingGroupName.value = group.name
  await nextTick()
  groupRenameInputRef.value?.focus?.()
  groupRenameInputRef.value?.select?.()
}

async function confirmEditGroup() {
  const name = editingGroupName.value.trim()
  if (name && name !== collection.groups.find((g) => g.id === editingGroupId.value)?.name) {
    await collection.updateGroup(editingGroupId.value, name)
  }
  editingGroupId.value = null
  editingGroupName.value = ''
}

function cancelEditGroup() {
  editingGroupId.value = null
  editingGroupName.value = ''
}

async function deleteGroup(group) {
  if (!confirm(`Delete group "${group.name}"? Its locations will become ungrouped.`)) return
  await collection.deleteGroup(group.id)
}

function onDragEnd() {
  collection.reorderAll()
}

// Unique key across the mixed top-level draggable (groups + top-level
// locations can share ids between the two tables).
function itemKey(item) {
  return `${item.kind}:${item.id}`
}

// put-function for in-group draggables: accept location rows from other
// draggables, but reject group-section drops so a group can't be nested.
function onlyAcceptLocations(_to, _from, dragEl) {
  return !dragEl.classList.contains('group-section')
}

// When a location is added to a group via cross-list drag, always append it
// to the end of the group — regardless of where SortableJS would have placed
// it based on pointer position. This matches the "drop on group header adds
// to the bottom of the group" UX rule. Intra-list reorders within the same
// group fire @update (not @add) and are unaffected.
//
// We locate the added element by REFERENCE (evt.item._underlying_vm_),
// never by index. evt.newIndex is a raw SortableJS DOM index that does NOT
// account for vuedraggable's #header slot, so using it for list splicing
// silently corrupts the array.
function onGroupAdd(evt, group) {
  const added = evt.item?._underlying_vm_
  if (!added) return
  const idx = group.locations.indexOf(added)
  if (idx === -1 || idx === group.locations.length - 1) return
  group.locations.splice(idx, 1)
  group.locations.push(added)
}
</script>

<template>
  <aside class="sidebar">
    <header class="brand">
      <h1 class="display">VAULTKEEPER</h1>
    </header>

    <div class="mode-toggle" role="group" aria-label="Display mode">
      <button
        type="button"
        class="mode-btn"
        :class="{ active: collection.displayMode === 'A' }"
        @click="collection.setDisplayMode('A')"
      >A</button>
      <button
        type="button"
        class="mode-btn"
        :class="{ active: collection.displayMode === 'B' }"
        @click="collection.setDisplayMode('B')"
      >B</button>
    </div>

    <nav class="locations">
      <div
        class="all-cards-row"
        :class="{ active: collection.activeLocationId === null }"
        @click="showAll"
      >
        <span class="all-cards-icon" aria-hidden="true">
          <IconAllCards />
        </span>
        <span class="name">All Cards</span>
        <span class="count">{{ collection.totalCount }}</span>
      </div>

      <draggable
        :list="collection.sidebarItems"
        :item-key="itemKey"
        :group="{ name: 'sidebar', pull: true, put: true }"
        handle=".drag-handle"
        :animation="150"
        ghost-class="sortable-ghost"
        chosen-class="sortable-chosen"
        @end="onDragEnd"
        class="sidebar-dropzone"
      >
        <template #item="{ element: item }">
          <div v-if="item.kind === 'group'" class="group-section">
            <draggable
              :list="item.locations"
              item-key="id"
              :group="{ name: 'sidebar', pull: true, put: onlyAcceptLocations }"
              handle=".drag-handle"
              filter=".group-header"
              :animation="150"
              ghost-class="sortable-ghost"
              chosen-class="sortable-chosen"
              @end="onDragEnd"
              @add="(evt) => onGroupAdd(evt, item)"
              class="group-locations"
            >
              <template #header>
                <div
                  class="group-header"
                  :class="{ collapsed: isCollapsed(item.id) }"
                  @click="toggleCollapse(item.id)"
                >
                  <span class="group-handle drag-handle" @click.stop>⠿</span>
                  <IconChevron class="chevron" :class="{ rotated: !isCollapsed(item.id) }" />
                  <template v-if="editingGroupId === item.id">
                    <input
                      ref="groupRenameInputRef"
                      class="group-rename-input"
                      v-model="editingGroupName"
                      @click.stop
                      @keydown.enter.stop="confirmEditGroup"
                      @keydown.escape.stop="cancelEditGroup"
                      @blur="confirmEditGroup"
                    />
                  </template>
                  <template v-else>
                    <span class="group-name">{{ item.name }}</span>
                  </template>
                  <span class="count">{{ groupCardCount(item) }}</span>
                  <span class="group-actions" @click.stop>
                    <button type="button" class="edit-btn" @click="startEditGroup(item)" title="Rename">
                      <IconEdit />
                    </button>
                    <button type="button" class="delete-btn" @click="deleteGroup(item)" title="Delete">×</button>
                  </span>
                </div>
              </template>
              <template #item="{ element: loc }">
                <div
                  v-show="!isCollapsed(item.id)"
                  class="loc-row nested"
                  :class="{ active: isActive(loc) }"
                  @click="activate(loc)"
                >
                  <span class="drag-handle" @click.stop>⠿</span>
                  <span class="loc-icon" aria-hidden="true">
                    <IconDrawer v-if="loc.type === 'drawer'" />
                    <IconBinder v-else-if="loc.type === 'binder'" />
                    <IconDeck v-else-if="loc.type === 'deck'" />
                  </span>
                  <span class="name">{{ loc.name }}</span>
                  <span class="count">{{ loc.card_count }}</span>
                  <span class="loc-actions" @click.stop>
                    <button type="button" class="edit-btn" @click="openEdit(loc)" title="Edit">
                      <IconEdit />
                    </button>
                  </span>
                </div>
              </template>
            </draggable>
          </div>

          <div
            v-else
            class="loc-row"
            :class="{ active: isActive(item) }"
            @click="activate(item)"
          >
            <span class="drag-handle" @click.stop>⠿</span>
            <span class="loc-icon" aria-hidden="true">
              <IconDrawer v-if="item.type === 'drawer'" />
              <IconBinder v-else-if="item.type === 'binder'" />
              <IconDeck v-else-if="item.type === 'deck'" />
            </span>
            <span class="name">{{ item.name }}</span>
            <span class="count">{{ item.card_count }}</span>
            <span class="loc-actions" @click.stop>
              <button type="button" class="edit-btn" @click="openEdit(item)" title="Edit">
                <IconEdit />
              </button>
            </span>
          </div>
        </template>
      </draggable>
    </nav>

    <footer>
      <div v-if="creatingGroup" class="inline-group-input">
        <input
          ref="groupInputRef"
          v-model="newGroupName"
          type="text"
          placeholder="Group name…"
          maxlength="100"
          @keydown.enter="confirmCreateGroup"
          @keydown.escape="cancelCreateGroup"
          @blur="cancelCreateGroup"
        />
      </div>
      <div class="footer-buttons">
        <button type="button" class="new-btn" @click="openCreate">+ Location</button>
        <button type="button" class="new-btn" @click="startCreateGroup">+ Group</button>
      </div>
      <button type="button" class="import-btn" @click="importOpen = true">Import CSV</button>
    </footer>

    <ImportModal v-if="importOpen" @close="importOpen = false" />
    <LocationModal v-if="modalOpen" :location="editingLocation" @close="closeModal" />
  </aside>
</template>

<style scoped>
.sidebar {
  display: flex;
  flex-direction: column;
  background: var(--bg-1);
  border-right: 1px solid var(--border);
  height: 100vh;
  overflow: hidden;
}
.brand {
  padding: 26px 22px 22px;
}
.brand h1 {
  font-size: 24px;
  letter-spacing: 0.18em;
  color: var(--gold);
  text-align: center;
}

.mode-toggle {
  display: flex;
  margin: 12px 18px 14px;
  border: 1px solid var(--border);
  border-radius: 4px;
  overflow: hidden;
  background: var(--bg-0);
}
.mode-btn {
  flex: 1;
  background: transparent;
  border: none;
  border-radius: 0;
  padding: 6px 0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  color: var(--text-dim);
  font-family: var(--font-display);
  transition: background 120ms ease, color 120ms ease;
}
.mode-btn:hover {
  background: var(--bg-2);
  color: var(--text);
  border-color: transparent;
}
.mode-btn.active {
  background: var(--gold);
  color: var(--bg-0);
}

.locations {
  flex: 1;
  overflow-y: auto;
  padding: 8px 0;
  display: flex;
  flex-direction: column;
}

/* "All Cards" pinned row — intentionally independent from .loc-row so
 * location-row tweaks (padding, drag handle alignment, etc.) don't
 * affect this special entry. */
.all-cards-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  padding: 11px 18px;
  color: var(--text-dim);
  font-size: 14px;
  cursor: pointer;
  transition: background 100ms ease, color 100ms ease;
}
.all-cards-row:hover {
  background: var(--bg-2);
  color: var(--text);
}
.all-cards-row.active {
  background: var(--bg-2);
  color: var(--text);
  box-shadow: inset 3px 0 0 var(--gold);
}
.all-cards-row.active .count {
  background: var(--gold);
  color: var(--bg-0);
  font-weight: 700;
}

.loc-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: 12px;
  width: 100%;
  background: transparent;
  border: none;
  border-radius: 0;
  padding: 11px 16px 11px 10px;
  color: var(--text-dim);
  text-align: left;
  font-size: 14px;
  cursor: pointer;
  transition: background 100ms ease, color 100ms ease;
}
.loc-row:hover {
  background: var(--bg-2);
  color: var(--text);
}
.loc-row.active {
  background: var(--bg-2);
  color: var(--text);
  box-shadow: inset 3px 0 0 var(--gold);
}
/* Nested (grouped) row styling is driven by the PARENT container, not a
 * .nested class. This way a row being dragged between groups and ungrouped
 * picks up / loses its indent and tree lines based on where it currently
 * lives in the DOM — matching what the state will be after release. */
.group-locations .loc-row {
  padding-left: 28px;
}
/* Tree connector lines for rows inside a group.
 * ::before = horizontal branch at row center
 * ::after  = vertical line segment through the row */
.group-locations .loc-row::before {
  content: '';
  position: absolute;
  left: 15px;
  top: 50%;
  width: 6px;
  height: 1px;
  background: var(--border);
}
.group-locations .loc-row::after {
  content: '';
  position: absolute;
  left: 15px;
  top: 0;
  bottom: 0;
  width: 1px;
  background: var(--border);
}
.group-locations .loc-row:last-child::after {
  bottom: 50%;
}
.loc-icon {
  display: inline-flex;
  margin-left: 2px;
  margin-right: -2px;
  width: 16px;
  flex-shrink: 0;
}
.all-cards-icon {
  display: inline-flex;
  width: 16px;
  flex-shrink: 0;
}
.name {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.count {
  font-variant-numeric: tabular-nums;
  font-size: 12px;
  background: var(--bg-0);
  color: var(--text-dim);
  padding: 2px 8px;
  border-radius: 8px;
  min-width: 26px;
  text-align: center;
  flex-shrink: 0;
}
.loc-row.active .count {
  background: var(--gold);
  color: var(--bg-0);
  font-weight: 700;
}
.loc-actions {
  display: none;
  flex-shrink: 0;
}
.loc-row:hover .loc-actions {
  display: flex;
}
.edit-btn {
  background: transparent;
  border: none;
  color: var(--text-faint);
  padding: 2px;
  border-radius: 3px;
  cursor: pointer;
  display: flex;
  align-items: center;
  font-size: 14px;
  line-height: 1;
}
.edit-btn:hover {
  color: var(--text);
}

.delete-btn {
  background: transparent;
  border: none;
  color: var(--text-faint);
  padding: 2px;
  border-radius: 3px;
  cursor: pointer;
  display: flex;
  align-items: center;
  font-size: 14px;
  line-height: 1;
}
.delete-btn:hover {
  color: #e05555;
}

/* Drag handles */
.drag-handle {
  opacity: 0;
  cursor: grab;
  font-size: 16px;
  color: var(--text-faint);
  width: 10px;
  flex-shrink: 0;
  transition: opacity 100ms ease;
  user-select: none;
  margin-right: -6px;
  margin-left: 0;
  padding: 3px 2px 0 2px;
}
.loc-row:hover .drag-handle,
.group-header:hover .drag-handle {
  opacity: 0.7;
}
.drag-handle:hover {
  opacity: 1 !important;
}
.drag-handle:active {
  cursor: grabbing;
}

/* Group header */
.group-section {
  display: flex;
  flex-direction: column;
}
.group-header {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 18px;
  background: var(--bg-2);
  color: var(--text-dim);
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  user-select: none;
  border-top: 1px solid var(--border);
  border-bottom: 1px solid var(--border);
  margin-top: 4px;
  transition: padding 180ms ease, font-size 180ms ease, background 100ms ease, color 100ms ease;
}
.group-header:hover {
  background: var(--border);
  color: var(--text);
}
.group-header.collapsed {
  padding: 5px 18px;
  font-size: 12px;
}
.group-name {
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.group-rename-input {
  flex: 1;
  background: var(--bg-0);
  border: 1px solid var(--gold);
  color: var(--text);
  padding: 3px 6px;
  font-size: 14px;
  font-weight: 600;
  border-radius: 2px;
  outline: none;
  min-width: 0;
}
.chevron {
  width: 10px;
  flex-shrink: 0;
  color: var(--text-dim);
  transform: rotate(-90deg);
  transform-origin: center;
  transition: transform 180ms ease;
}
.chevron.rotated {
  transform: rotate(0deg);
}
.group-actions {
  display: none;
  flex-shrink: 0;
  gap: 2px;
}
.group-header:hover .group-actions {
  display: flex;
}
.group-locations {
  min-height: 8px;
}
.sidebar-dropzone {
  min-height: 40px;
  padding-top: 8px;
  padding-bottom: 40px;
}

/* SortableJS ghost / drag states */
:deep(.sortable-ghost) {
  opacity: 0.4;
  background: rgba(201, 162, 39, 0.08);
  border-left-color: var(--gold) !important;
}
:deep(.sortable-chosen) {
  background: var(--bg-2);
}

/* Footer */
footer {
  padding: 14px 18px 18px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.inline-group-input input {
  width: 100%;
  background: var(--bg-0);
  border: 1px solid var(--gold-dim);
  color: var(--text);
  padding: 8px 10px;
  font-size: 13px;
  border-radius: 3px;
  outline: none;
  transition: border-color 120ms ease;
}
.inline-group-input input:focus {
  border-color: var(--gold);
}
.footer-buttons {
  display: flex;
  gap: 8px;
}
.footer-buttons .new-btn {
  flex: 1;
}
.import-btn {
  width: 100%;
  background: var(--gold);
  border: 1px solid var(--gold);
  color: var(--bg-0);
  font-size: 13px;
  font-weight: 600;
  padding: 10px;
}
.import-btn:hover {
  filter: brightness(1.1);
}
.new-btn {
  background: transparent;
  border: 1px dashed var(--gold-dim);
  color: var(--gold);
  font-size: 13px;
  padding: 10px;
}
.new-btn:hover {
  background: rgba(201, 162, 39, 0.07);
  border-color: var(--gold);
}
</style>
