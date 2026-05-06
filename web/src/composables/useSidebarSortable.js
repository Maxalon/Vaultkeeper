import { watch } from 'vue'
import { useDragAndDrop } from '@formkit/drag-and-drop/vue'
import { useCollectionStore } from '../stores/collection'

/**
 * Bind drag-and-drop to one sidebar container (the root or one group's
 * children) using @formkit/drag-and-drop. Unlike the previous setup
 * with vue-draggable-plus / Sortable.js, this library mutates the
 * values ref it manages in a single pass on drop rather than mutating
 * the DOM continuously during the drag, so Vue's vnode references stay
 * in sync with the actual DOM and the phantom-copy / snap-back bugs
 * that dogged the previous iterations are gone by construction.
 *
 * Architecture
 * ------------
 * The library OWNS the rendered values ref for its container — we
 * render directly from the ref it returns. On drop we dispatch a
 * `moveItem` so the Pinia store stays in sync (the canonical tree is
 * read by location dropdowns, deck targets, etc. elsewhere).
 *
 * For external mutations (createDeck, fetchGroups, deleteGroup, …) we
 * watch `collection.sidebarExternalEpoch` and re-read the source
 * getter, replacing the values ref. Crucially we do NOT remount the
 * container element on external changes — the library's parent binding
 * is set up once via a self-stopping watch and only ever fires for the
 * first mount of the parent ref. Tearing down and remounting the
 * dropzone div would leave the library bound to the orphaned old div,
 * with zero drag handlers on the new one. Rebinding values via this
 * watcher keeps the same div live forever.
 *
 * Move actions don't bump `sidebarExternalEpoch`, so an in-progress
 * drag is never disturbed by our own dispatch.
 *
 * @param {() => Array<Object>} sourceGetter - returns the children of
 *   this container (top-level items, or `group.children`) from the
 *   canonical Pinia tree.
 * @param {() => number|null} parentIdGetter - returns the destination
 *   parent group id for moves that land inside this container, or null
 *   for the top-level container.
 * @returns {[Ref<HTMLElement|undefined>, Ref<Array<Object>>]} the
 *   `[parentRef, valuesRef]` to bind to the container element and
 *   render from in the v-for.
 */
export function useSidebarSortable(sourceGetter, parentIdGetter) {
  const collection = useCollectionStore()

  // Spread to a fresh array so the library never mutates Pinia's array
  // reference. Item identities are preserved — the library uses `===`
  // to track items, and Vue's keyed v-for needs stable references for
  // its reconciliation to reuse DOM nodes.
  const seed = [...(sourceGetter() || [])]

  const [parent, values] = useDragAndDrop(seed, {
    group: 'sidebar',
    // The library's validateDragHandle does an unbounded
    // querySelectorAll within a node, so a flat `.drag-handle` selector
    // would let a group node "claim" a click on any nested row's handle
    // and start dragging the whole group. We scope the selector to the
    // shapes of our two draggable elements:
    //   - SidebarGroup roots:  <div.group-section> > <div.group-header> > .drag-handle
    //   - SidebarRow buttons:  <button.loc-row>     > .drag-handle
    // `:scope >` keeps the search bounded to the node's own immediate
    // structure, so a group only matches its own header handle and a
    // row only matches its own handle — nested rows inside a group no
    // longer trigger the group's drag.
    dragHandle: ':scope > .group-header > .drag-handle, :scope > .drag-handle',

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

  // External-mutation refresh: when something OUTSIDE drag-and-drop
  // changes the tree, the store bumps `sidebarExternalEpoch`. Rehydrate
  // values from Pinia so creates/deletes/refetches show up. Our own
  // moveItem doesn't bump the epoch, so we never clobber an in-flight
  // drag with this watcher.
  watch(
    () => collection.sidebarExternalEpoch,
    () => { values.value = [...(sourceGetter() || [])] },
  )

  return [parent, values]
}
