import { useDraggable } from 'vue-draggable-plus'
import { useCollectionStore } from '../stores/collection'

/**
 * Attach SortableJS to a sidebar container without giving it a reactive
 * list to mutate. Sortable is purely an input device here: on drop we
 * read the move out of the SortableEvent (kind/id from data attrs on
 * the dragged element, destination parent from the destination
 * container's `data-parent-id`, position from `newIndex`), then
 * dispatch a single `moveItem` action.
 *
 * The store applies the move immutably AND bumps a render epoch bound
 * to `:key` on the root dropzone, which forces Vue to tear down and
 * rebuild the entire sidebar subtree (along with this very Sortable
 * instance). That's what makes the result visually trustworthy: any
 * DOM that Sortable mid-drag-mutated gets thrown away and rebuilt
 * from the new tree, so phantom copies are impossible by construction.
 *
 * Calling `useDraggable` with no list argument (the third overload of
 * the typings) means the library installs none of its auto-mutation
 * onAdd/onRemove/onUpdate handlers — only our onEnd runs.
 */
export function useSidebarSortable(elementRef) {
  const collection = useCollectionStore()

  useDraggable(elementRef, {
    group: { name: 'sidebar', pull: true, put: true },
    handle: '.drag-handle',
    animation: 150,
    ghostClass: 'sortable-ghost',
    chosenClass: 'sortable-chosen',
    onEnd: (evt) => {
      const item = evt.item
      const kind = item.dataset.kind
      const id = Number(item.dataset.id)
      if (!kind || ! Number.isFinite(id)) return

      const dest = evt.to
      const rawParent = dest.dataset.parentId
      // Root container has no data-parent-id (or empty string); group
      // containers carry the group id as a number.
      const parentId = rawParent ? Number(rawParent) : null

      // newIndex is the destination position in Sortable's draggable
      // child list. Our containers contain only draggable children, so
      // it matches the desired position in the merged sibling list.
      const position = evt.newIndex
      if (typeof position !== 'number' || position < 0) return

      collection.moveItem({ kind, id, parentId, position })
    },
  })
}
