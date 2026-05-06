import { useDragAndDrop } from '@formkit/drag-and-drop/vue'
import { useCollectionStore } from '../stores/collection'

/**
 * Bind drag-and-drop to one sidebar container (the root or one group's
 * children) using @formkit/drag-and-drop. Unlike the previous setup
 * with vue-draggable-plus / Sortable.js, this library mutates the
 * values it manages in a single pass on drop rather than mutating the
 * DOM continuously during the drag, so Vue's vnode references stay in
 * sync with the actual DOM and the phantom-copy / snap-back bugs that
 * dogged the previous iterations are gone by construction.
 *
 * Architecture
 * ------------
 * The library OWNS the rendered values ref for its container — we
 * render directly from the ref it returns. On drop we dispatch a
 * `moveItem` so the Pinia store stays in sync (the canonical tree is
 * read by location dropdowns, deck targets, etc. elsewhere in the
 * app). We do NOT watch Pinia from this composable — that would race
 * with the library's own mutations and reintroduce the same class of
 * bugs we just escaped.
 *
 * External mutations (createDeck, deleteEntry, fetchGroups, ...) bump
 * `collection.sidebarExternalEpoch`, which is bound to a `:key` on the
 * sidebar dropzone. That forces Vue to tear down and remount the drag
 * containers with fresh initial values from Pinia. Move actions don't
 * bump the epoch, so an in-progress drag is never disturbed.
 *
 * @param {Array<Object>} initial - children of this container at mount
 *   time (top-level items for the root, `group.children` for a group).
 *   Used once for initialisation; subsequent updates flow through the
 *   external-epoch remount.
 * @param {() => number|null} parentIdGetter - returns the destination
 *   parent group id for moves that land inside this container, or null
 *   for the top-level container.
 * @returns {[Ref<HTMLElement|undefined>, Ref<Array<Object>>]} the
 *   `[parentRef, valuesRef]` to bind to the container element and to
 *   render from in the v-for.
 */
export function useSidebarSortable(initial, parentIdGetter) {
  const collection = useCollectionStore()

  // Plain shallow copies so the library never mutates Pinia state by
  // accident. The library is the single owner of the returned values
  // ref from this point on.
  const seed = (initial || []).map((item) => ({ ...item }))

  return useDragAndDrop(seed, {
    group: 'sidebar',
    dragHandle: '.drag-handle',

    // Same-container reorder. `position` is the index in the destination
    // values array — the merged sibling list — which is exactly what
    // the backend's `move` endpoint wants.
    onSort: (data) => {
      const moved = data.draggedNodes[0].data.value
      const parentId = parentIdGetter()
      collection.moveItem({
        kind: moved.kind,
        id: moved.id,
        parentId,
        position: data.position,
      })
    },

    // Cross-container transfer. The destination parent id is read from
    // the target container's `data-parent-id` attribute (root has none,
    // group containers carry the group id).
    onTransfer: (data) => {
      const moved = data.draggedNodes[0].data.value
      const targetEl = data.targetParent.el
      const raw = targetEl?.dataset?.parentId
      const targetParentId = raw ? Number(raw) : null
      collection.moveItem({
        kind: moved.kind,
        id: moved.id,
        parentId: targetParentId,
        position: data.targetIndex,
      })
    },
  })
}
