<script setup>
import { computed, ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { vDraggable } from 'vue-draggable-plus'
import { useCollectionStore } from '../stores/collection'
import { useSettingsStore } from '../stores/settings'
import LocationModal from './LocationModal.vue'
import ImportModal from './ImportModal.vue'
import ImportDeckModal from './ImportDeckModal.vue'
import BulkImportDeckModal from './BulkImportDeckModal.vue'
import IconAllCards from '../assets/icons/all-cards.svg'
import IconDrawer from '../assets/icons/drawer.svg'
import IconBinder from '../assets/icons/binder.svg'
import IconDeck from '../assets/icons/deck.svg'
import IconEdit from '../assets/icons/edit.svg'
import IconChevron from '../assets/chevron-down.svg'

defineProps({
  collapsed: { type: Boolean, default: false },
})

const collection = useCollectionStore()
const settings = useSettingsStore()
const route = useRoute()
const router = useRouter()

// ── Sidebar resize ──────────────────────────────────────────────────────
// The sidebar lives in column 1 of the shell grid (starts at viewport x=0),
// so the new width during a drag is just the pointer's clientX clamped by
// the store. Pointer capture keeps the drag alive when the cursor briefly
// leaves the 5px handle. The is-resizing-sidebar class on <html> disables
// the shell's grid-template-columns transition so the handle stays glued
// to the cursor instead of easing behind it.
function onResizePointerDown(event) {
  if (event.button !== 0) return
  event.preventDefault()
  const handle = event.currentTarget
  handle.setPointerCapture(event.pointerId)
  document.documentElement.classList.add('is-resizing-sidebar')

  const onMove = (e) => settings.setSidebarWidth(e.clientX)
  const onUp = (e) => {
    handle.removeEventListener('pointermove', onMove)
    handle.removeEventListener('pointerup', onUp)
    handle.removeEventListener('pointercancel', onUp)
    if (handle.hasPointerCapture(e.pointerId)) {
      handle.releasePointerCapture(e.pointerId)
    }
    document.documentElement.classList.remove('is-resizing-sidebar')
    settings.persistSidebar()
  }
  handle.addEventListener('pointermove', onMove)
  handle.addEventListener('pointerup', onUp)
  handle.addEventListener('pointercancel', onUp)
}

const mergedItems = computed(() => collection.sidebarItemsMerged)

function activeDeckId() {
  return route.name === 'deck' ? Number(route.params.id) : null
}

function openDeck(deck) {
  router.push({ name: 'deck', params: { id: deck.id } })
}

function formatShort(f) {
  return ({ commander: 'CMDR', oathbreaker: 'OATH', pauper: 'PAU', standard: 'STD', modern: 'MOD' })[f] || (f || '').toUpperCase()
}
const importOpen = ref(false)
const deckImportOpen = ref(false)
const bulkDeckImportOpen = ref(false)
const deckImportMenuOpen = ref(false)
const modalOpen = ref(false)
const editingLocation = ref(null)

function openBulkDeckImport() {
  deckImportMenuOpen.value = false
  bulkDeckImportOpen.value = true
}
function toggleDeckImportMenu() {
  deckImportMenuOpen.value = !deckImportMenuOpen.value
}
// Close the dropdown on any document click outside the split-button cluster.
function onDocClick(e) {
  if (!e.target.closest?.('.deck-import-split')) {
    deckImportMenuOpen.value = false
  }
}
onMounted(() => document.addEventListener('click', onDocClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocClick))

const creatingGroup = ref(false)
const newGroupName = ref('')
const groupInputRef = ref(null)

const editingGroupId = ref(null)
const editingGroupName = ref('')
const groupRenameInputRef = ref(null)

function isActive(loc) {
  return route.name === 'collection' && loc.id === collection.activeLocationId
}
function activate(loc) {
  collection.setActiveLocation(loc.id)
  if (route.name !== 'collection') router.push({ name: 'collection' })
}
function showAll() {
  collection.setActiveLocation(null)
  if (route.name !== 'collection') router.push({ name: 'collection' })
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

function toggleCollapse(groupId) { collection.toggleGroupCollapse(groupId) }
function isCollapsed(groupId) { return collection.isGroupCollapsed(groupId) }
// `group.locations` after sidebarItemsMerged() interleaves locations and
// decks (kind='location' or kind='deck'). Locations carry `card_count`,
// decks carry `entry_count` (mainboard size) — sum both kinds so a group
// of imported decks doesn't read 0.
function groupCardCount(group) {
  return group.locations.reduce(
    (sum, l) => sum + (l.kind === 'deck' ? (l.entry_count || 0) : (l.card_count || 0)),
    0,
  )
}

async function startCreateGroup() {
  creatingGroup.value = true
  newGroupName.value = ''
  await nextTick()
  groupInputRef.value?.focus()
}
async function confirmCreateGroup() {
  const name = newGroupName.value.trim()
  if (name) await collection.createGroup(name)
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

function onDragEnd() { collection.reorderAll() }

function itemKey(item) { return `${item.kind}:${item.id}` }
function onlyAcceptLocations(_to, _from, dragEl) {
  return !dragEl.classList.contains('group-section')
}
function onGroupAdd(evt, group) {
  // vue-draggable-plus exposes the dragged item's data on evt.data
  // (replacing vuedraggable's evt.item._underlying_vm_).
  const added = evt.data
  if (!added) return
  const idx = group.locations.indexOf(added)
  if (idx === -1 || idx === group.locations.length - 1) return
  group.locations.splice(idx, 1)
  group.locations.push(added)
}

// Sortable options shared by every draggable on the sidebar. Sortable's
// option names are camelCase when supplied via JS (vs the kebab-case
// component props in vuedraggable v4).
const outerOptions = {
  group: { name: 'sidebar', pull: true, put: true },
  handle: '.drag-handle',
  animation: 150,
  ghostClass: 'sortable-ghost',
  chosenClass: 'sortable-chosen',
  onEnd: onDragEnd,
}
function innerOptions(group) {
  return {
    group: { name: 'sidebar', pull: true, put: onlyAcceptLocations },
    handle: '.drag-handle',
    animation: 150,
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    onEnd: onDragEnd,
    onAdd: (evt) => onGroupAdd(evt, group),
  }
}
</script>

<template>
  <aside class="location-sidebar" :class="{ collapsed }">
    <header class="brand">
      <h3>Collections</h3>
      <div class="mode-toggle" role="group" aria-label="Display mode">
        <button
          type="button"
          class="mode-btn"
          :class="{ active: settings.displayMode === 'A' }"
          @click="settings.setDisplayMode('A')"
        >A</button>
        <button
          type="button"
          class="mode-btn"
          :class="{ active: settings.displayMode === 'B' }"
          @click="settings.setDisplayMode('B')"
        >B</button>
      </div>
    </header>

    <nav class="locations">
      <button
        type="button"
        class="all-cards-row sidebar-item top"
        :class="{ active: route.name === 'collection' && collection.activeLocationId === null }"
        @click="showAll"
      >
        <span class="set-sym all-cards-icon" aria-hidden="true">
          <IconAllCards />
        </span>
        <span class="label">All Cards</span>
        <span class="num">{{ collection.totalCount }}</span>
      </button>

      <div
        class="sidebar-dropzone"
        v-draggable="[mergedItems, outerOptions]"
      >
        <template v-for="item in mergedItems" :key="itemKey(item)">
          <div v-if="item.kind === 'group'" class="group-section">
            <div
              class="group-header"
              :class="{ collapsed: isCollapsed(item.id) }"
              @click="toggleCollapse(item.id)"
            >
              <span class="drag drag-handle group-handle" @click.stop title="Drag">⠿</span>
              <span class="chev" :class="{ rotated: !isCollapsed(item.id) }">
                <IconChevron />
              </span>
              <template v-if="editingGroupId === item.id">
                <input
                  ref="groupRenameInputRef"
                  class="group-rename-input"
                  name="group-name"
                  type="text"
                  autocomplete="off"
                  v-model="editingGroupName"
                  @click.stop
                  @keydown.enter.stop="confirmEditGroup"
                  @keydown.esc.stop="cancelEditGroup"
                  @blur="confirmEditGroup"
                />
              </template>
              <template v-else>
                <span class="label">{{ item.name }}</span>
              </template>
              <span class="num">{{ groupCardCount(item) }}</span>
              <span class="group-actions" @click.stop>
                <button type="button" class="edit-btn" @click="startEditGroup(item)" title="Rename">
                  <IconEdit />
                </button>
                <button type="button" class="delete-btn" @click="deleteGroup(item)" title="Delete">×</button>
              </span>
            </div>
            <div
              class="group-locations"
              v-draggable="[item.locations, innerOptions(item)]"
            >
              <template v-for="loc in item.locations" :key="loc.id">
                <button
                  v-if="loc.kind === 'deck'"
                  v-show="!isCollapsed(item.id)"
                  type="button"
                  class="loc-row sidebar-item nested sidebar-deck"
                  :class="{ active: activeDeckId() === loc.id }"
                  @click="openDeck(loc)"
                >
                  <span class="drag drag-handle" @click.stop>⠿</span>
                  <span class="set-sym loc-icon" aria-hidden="true"><IconDeck /></span>
                  <span class="label">{{ loc.name }}</span>
                  <span class="format-badge">{{ formatShort(loc.format) }}</span>
                  <span class="num">{{ loc.entry_count }}</span>
                </button>
                <button
                  v-else
                  v-show="!isCollapsed(item.id)"
                  type="button"
                  class="loc-row sidebar-item nested"
                  :class="{ active: isActive(loc) }"
                  @click="activate(loc)"
                >
                  <span class="drag drag-handle" @click.stop>⠿</span>
                  <span class="set-sym loc-icon" aria-hidden="true">
                    <IconDrawer v-if="loc.type === 'drawer'" />
                    <IconBinder v-else-if="loc.type === 'binder'" />
                    <IconDeck v-else-if="loc.type === 'deck'" />
                  </span>
                  <span class="label">{{ loc.name }}</span>
                  <span class="num">{{ loc.card_count }}</span>
                  <span class="edit" @click.stop>
                    <button type="button" class="edit-btn" @click="openEdit(loc)" title="Edit">
                      <IconEdit />
                    </button>
                  </span>
                </button>
              </template>
            </div>
          </div>

          <button
            v-else-if="item.kind === 'deck'"
            type="button"
            class="loc-row sidebar-item sidebar-deck"
            :class="{ active: activeDeckId() === item.id }"
            @click="openDeck(item)"
          >
            <span class="drag drag-handle" @click.stop>⠿</span>
            <span class="set-sym loc-icon" aria-hidden="true"><IconDeck /></span>
            <span class="label">{{ item.name }}</span>
            <span class="format-badge">{{ formatShort(item.format) }}</span>
            <span class="num">{{ item.entry_count }}</span>
          </button>

          <button
            v-else
            type="button"
            class="loc-row sidebar-item"
            :class="{ active: isActive(item) }"
            @click="activate(item)"
          >
            <span class="drag drag-handle" @click.stop>⠿</span>
            <span class="set-sym loc-icon" aria-hidden="true">
              <IconDrawer v-if="item.type === 'drawer'" />
              <IconBinder v-else-if="item.type === 'binder'" />
              <IconDeck v-else-if="item.type === 'deck'" />
            </span>
            <span class="label">{{ item.name }}</span>
            <span class="num">{{ item.card_count }}</span>
            <span class="edit" @click.stop>
              <button type="button" class="edit-btn" @click="openEdit(item)" title="Edit">
                <IconEdit />
              </button>
            </span>
          </button>
        </template>
      </div>
    </nav>

    <footer>
      <div v-if="creatingGroup" class="inline-group-input">
        <input
          ref="groupInputRef"
          name="new-group-name"
          autocomplete="off"
          v-model="newGroupName"
          type="text"
          placeholder="Group name…"
          maxlength="100"
          @keydown.enter="confirmCreateGroup"
          @keydown.esc="cancelCreateGroup"
          @blur="cancelCreateGroup"
        />
      </div>
      <div class="footer-buttons">
        <button type="button" class="mini-btn" @click="openCreate">+ Location</button>
        <button type="button" class="mini-btn" @click="startCreateGroup">+ Group</button>
      </div>
      <button type="button" class="import-btn" @click="importOpen = true">
        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M6 2v6M3 5l3 3 3-3M2 10h8" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        Import CSV
      </button>
      <div class="deck-import-split">
        <button type="button" class="import-btn split-main" @click="deckImportOpen = true">
          <svg width="12" height="12" viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M6 2v6M3 5l3 3 3-3M2 10h8" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
          Import Deck
        </button>
        <button
          type="button"
          class="import-btn split-chevron"
          :class="{ open: deckImportMenuOpen }"
          @click="toggleDeckImportMenu"
          aria-haspopup="menu"
          :aria-expanded="deckImportMenuOpen"
          aria-label="More import options"
        >
          <svg width="10" height="10" viewBox="0 0 10 10" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M2 4l3 3 3-3" stroke-linecap="round" stroke-linejoin="round" />
          </svg>
        </button>
        <div v-if="deckImportMenuOpen" class="split-menu" role="menu">
          <button type="button" class="split-menu-item" role="menuitem" @click="openBulkDeckImport">
            Bulk import from user…
          </button>
        </div>
      </div>
    </footer>

    <ImportModal v-if="importOpen" @close="importOpen = false" />
    <ImportDeckModal v-if="deckImportOpen" @close="deckImportOpen = false" />
    <BulkImportDeckModal v-if="bulkDeckImportOpen" @close="bulkDeckImportOpen = false" />
    <LocationModal v-if="modalOpen" :location="editingLocation" @close="closeModal" />

    <div
      v-if="!collapsed"
      class="resize-handle"
      role="separator"
      aria-orientation="vertical"
      :aria-valuenow="settings.sidebarWidth"
      :aria-valuemin="settings.sidebarMin"
      :aria-valuemax="settings.sidebarMax"
      title="Drag to resize sidebar"
      @pointerdown="onResizePointerDown"
    />
  </aside>
</template>

<style scoped>
.location-sidebar {
  display: flex;
  flex-direction: column;
  background: var(--bg-1);
  border-right: 1px solid var(--hairline);
  overflow: hidden;
  height: 100%;
  position: relative; /* anchors the resize handle to the right edge */
}

/* Drag affordance pinned to the sidebar's right edge. Sits on top of the
   border so the user picks up "the edge" itself. The hover/active tint
   is subtle on purpose — it's a power-user control. */
.resize-handle {
  position: absolute;
  top: 0;
  right: 0;
  width: 5px;
  height: 100%;
  cursor: col-resize;
  background: transparent;
  transition: background 120ms ease;
  z-index: 5;
  touch-action: none;
}
.resize-handle:hover,
.resize-handle:active {
  background: color-mix(in oklab, var(--amber) 35%, transparent);
}

.brand {
  padding: 14px 16px 10px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-shrink: 0;
}
.brand h3 {
  margin: 0;
  font-size: 10px;
  font-weight: 600;
  letter-spacing: 0.12em;
  text-transform: uppercase;
  color: var(--ink-50);
  font-family: var(--font-sans), sans-serif;
}

.mode-toggle {
  display: flex;
  background: var(--bg-2);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  padding: 3px;
}
.mode-btn {
  width: 26px;
  height: 22px;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 0.06em;
  color: var(--ink-50);
  border: 0;
  background: transparent;
  border-radius: 3px;
  padding: 0;
  cursor: pointer;
  transition: all 0.12s ease;
}
.mode-btn:hover { color: var(--ink-100); }
.mode-btn.active {
  background: var(--amber);
  color: #1a1408;
}

.locations {
  flex: 1;
  overflow-y: auto;
  padding: 4px 8px 0;
  display: flex;
  flex-direction: column;
}

/* ── Sidebar item — shared by All Cards, top-level loc, and nested loc ── */
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

.sidebar-item.top {
  font-weight: 500;
  margin-bottom: 4px;
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

/* Hover-revealed drag handle + edit (shared between top-level + nested rows) */
.sidebar-item .drag,
.sidebar-item .edit {
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
.sidebar-item .edit {
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
.sidebar-item:hover .edit {
  opacity: 0.6;
  width: 14px;
  margin-left: 2px;
}
.sidebar-item .drag:hover,
.sidebar-item .edit:hover {
  opacity: 1;
  color: var(--ink-100);
}
.sidebar-item .edit-btn {
  background: transparent;
  border: 0;
  color: inherit;
  padding: 0;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
}

/* ── Group section ──────────────────────────────────────────────── */
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

/* Nested-row tree connector lines */
.group-locations {
  position: relative;
  padding-left: 12px;
  margin-top: 2px;
  min-height: 8px;
}
.group-header.collapsed + .group-locations {
  display: none;
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
.sidebar-dropzone {
  min-height: 40px;
  padding-bottom: 40px;
}

/* SortableJS ghost / drag states — applied at runtime by vue-draggable-plus */
/*noinspection CssUnusedSymbol*/
:deep(.sortable-ghost) {
  opacity: 0.4;
  background: rgba(240, 195, 92, 0.08);
}
/*noinspection CssUnusedSymbol*/
:deep(.sortable-chosen) {
  background: var(--bg-2);
}

/* ── Footer ─────────────────────────────────────────────────────── */
footer {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  background: var(--bg-1);
  position: relative;
  flex-shrink: 0;
}
footer::before {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  top: -32px;
  height: 32px;
  background: linear-gradient(to bottom, transparent, var(--bg-1));
  pointer-events: none;
}

.inline-group-input input {
  width: 100%;
  background: var(--bg-0);
  border: 1px solid var(--amber-lo);
  color: var(--ink-100);
  padding: 6px 10px;
  font-size: 12px;
  border-radius: 3px;
  outline: none;
}
.footer-buttons {
  display: flex;
  gap: 6px;
}
.mini-btn {
  flex: 1;
  height: 28px;
  font-size: 11px;
  color: var(--ink-70);
  border: 1px dashed var(--hairline);
  background: transparent;
  border-radius: var(--radius-sm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 4px;
  cursor: pointer;
  transition: all 0.12s ease;
}
.mini-btn:hover {
  color: var(--ink-100);
  border-color: var(--ink-30);
  border-style: solid;
}
.import-btn {
  margin-top: 4px;
  height: 36px;
  background: var(--amber);
  color: #1a1408;
  border: 0;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  cursor: pointer;
  transition: background 0.12s ease;
}
.import-btn:hover {
  background: color-mix(in oklab, var(--amber) 85%, white);
}

/* ── Split-button (Import Deck + dropdown) ──────────────────────── */
.deck-import-split {
  position: relative;
  display: flex;
  margin-top: 4px;
  gap: 1px;
}
.deck-import-split .import-btn {
  margin-top: 0;
}
.deck-import-split .split-main {
  flex: 1;
  border-top-right-radius: 0;
  border-bottom-right-radius: 0;
}
.deck-import-split .split-chevron {
  flex: 0 0 auto;
  width: 32px;
  padding: 0;
  border-top-left-radius: 0;
  border-bottom-left-radius: 0;
  gap: 0;
}
.deck-import-split .split-chevron svg {
  transition: transform 0.15s ease;
}
.deck-import-split .split-chevron.open svg {
  transform: rotate(180deg);
}
.split-menu {
  position: absolute;
  bottom: calc(100% + 6px);
  right: 0;
  min-width: 200px;
  background: var(--bg-1);
  border: 1px solid var(--hairline, var(--border));
  border-radius: var(--radius-sm, 4px);
  box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
  padding: 4px;
  z-index: 30;
}
.split-menu-item {
  display: block;
  width: 100%;
  padding: 8px 10px;
  font-size: 12px;
  color: var(--ink-90, var(--ink-100));
  background: transparent;
  border: 0;
  text-align: left;
  cursor: pointer;
  border-radius: 3px;
}
.split-menu-item:hover {
  background: var(--bg-2);
  color: var(--ink-100);
}

</style>
