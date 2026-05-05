<script setup>
import { provide, ref, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { vDraggable } from 'vue-draggable-plus'
import { useCollectionStore } from '../stores/collection'
import { useSettingsStore } from '../stores/settings'
import { confirm as confirmDialog } from '../composables/useConfirm'
import LocationModal from './LocationModal.vue'
import ImportModal from './ImportModal.vue'
import ImportDeckModal from './ImportDeckModal.vue'
import ImportDeckCsvModal from './ImportDeckCsvModal.vue'
import BulkImportDeckModal from './BulkImportDeckModal.vue'
import AssembleDeckModal from './AssembleDeckModal.vue'
import GroupModal from './GroupModal.vue'
import SidebarGroup from './SidebarGroup.vue'
import SidebarRow from './SidebarRow.vue'
import IconAllCards from '../assets/icons/all-cards.svg'
import IconDrawer from '../assets/icons/drawer.svg'

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

function activeDeckId() {
  return route.name === 'deck' ? Number(route.params.id) : null
}

function openDeck(deck) {
  router.push({ name: 'deck', params: { id: deck.deck_id } })
}

function goToReview() {
  if (route.name !== 'review') router.push({ name: 'review' })
}

const importOpen = ref(false)
const deckImportOpen = ref(false)
const csvDeckImportOpen = ref(false)
const bulkDeckImportOpen = ref(false)
const deckImportMenuOpen = ref(false)
// `assembleDeck` is the deck whose AssembleDeckModal is currently open
// (or null). Driven by the @assemble emit on the import modals — when
// the user pre-tickled "already assembled" we hand off the new deck
// here once the import POST resolves.
const assembleDeck = ref(null)
const modalOpen = ref(false)
const editingLocation = ref(null)
const groupModalOpen = ref(false)

function onImportAssemble(deck) {
  assembleDeck.value = deck
}
function closeAssembleModal() {
  assembleDeck.value = null
}

function openBulkDeckImport() {
  deckImportMenuOpen.value = false
  bulkDeckImportOpen.value = true
}
function openCsvDeckImport() {
  deckImportMenuOpen.value = false
  csvDeckImportOpen.value = true
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

async function deleteRow(loc) {
  if (loc.kind === 'deck') {
    const ok = await confirmDialog({
      title: 'Delete deck?',
      message: `Remove "${loc.name}" permanently?`,
      confirmText: 'Delete',
      destructive: true,
    })
    if (!ok) return
    await collection.deleteDeck(loc.deck_id)
    return
  }
  const cardCount = loc.card_count ?? 0
  const result = await confirmDialog({
    title: 'Delete location?',
    message:
      cardCount > 0
        ? `Remove "${loc.name}" permanently? Its ${cardCount} card${cardCount === 1 ? '' : 's'} will be unassigned.`
        : `Remove "${loc.name}" permanently?`,
    confirmText: 'Delete',
    destructive: true,
    checkbox: cardCount > 0
      ? {
          label: `Also delete ${cardCount} entr${cardCount === 1 ? 'y' : 'ies'} in this location (permanent)`,
          dangerous: true,
        }
      : null,
  })
  const confirmed = typeof result === 'object' ? result.confirmed : result
  const deleteEntries = typeof result === 'object' ? result.checkboxChecked : false
  if (!confirmed) return
  await collection.deleteLocation(loc.id, { deleteEntries })
}

provide('sidebarCtx', {
  isActive,
  activate,
  openDeck,
  openEdit,
  deleteRow,
  isCollapsed,
  toggleCollapse,
  activeDeckId,
})

const outerOptions = {
  group: { name: 'sidebar', pull: true, put: true },
  handle: '.drag-handle',
  animation: 150,
  ghostClass: 'sortable-ghost',
  chosenClass: 'sortable-chosen',
  onEnd: () => collection.reorderAll(),
}

function itemKey(item) { return `${item.kind}:${item.id}` }
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

      <button
        v-if="collection.review"
        type="button"
        class="pending-row sidebar-item top"
        :class="{ active: route.name === 'review' }"
        @click="goToReview"
      >
        <span class="set-sym all-cards-icon" aria-hidden="true">
          <IconDrawer />
        </span>
        <span class="label">Review</span>
        <span class="num">{{ collection.review.card_count }}</span>
      </button>

      <div
        class="sidebar-dropzone"
        v-draggable="[collection.sidebarItems, outerOptions]"
      >
        <template v-for="item in collection.sidebarItems" :key="itemKey(item)">
          <SidebarGroup v-if="item.kind === 'group'" :group="item" />
          <SidebarRow v-else :item="item" />
        </template>
      </div>
    </nav>

    <footer>
      <div class="footer-buttons">
        <button type="button" class="mini-btn" @click="openCreate">+ Location</button>
        <button type="button" class="mini-btn" @click="groupModalOpen = true">+ Group</button>
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
          <button type="button" class="split-menu-item" role="menuitem" @click="openCsvDeckImport">
            Import from CSV…
          </button>
          <button type="button" class="split-menu-item" role="menuitem" @click="openBulkDeckImport">
            Bulk import from user…
          </button>
        </div>
      </div>
    </footer>

    <ImportModal v-if="importOpen" @close="importOpen = false" />
    <ImportDeckModal
      v-if="deckImportOpen"
      @close="deckImportOpen = false"
      @assemble="onImportAssemble"
    />
    <ImportDeckCsvModal
      v-if="csvDeckImportOpen"
      @close="csvDeckImportOpen = false"
      @assemble="onImportAssemble"
    />
    <BulkImportDeckModal v-if="bulkDeckImportOpen" @close="bulkDeckImportOpen = false" />
    <AssembleDeckModal
      v-if="assembleDeck"
      :deck="assembleDeck"
      @close="closeAssembleModal"
    />
    <LocationModal v-if="modalOpen" :location="editingLocation" @close="closeModal" />
    <GroupModal v-if="groupModalOpen" @close="groupModalOpen = false" />

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

/* The All Cards / Pending row use the same .sidebar-item class that
   SidebarRow ships scoped styles for; we duplicate the essentials here so
   they keep working when rendered directly in this template. */
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
  border: 1px solid var(--hairline);
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
