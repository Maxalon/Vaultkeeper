import { useDraggable } from 'vue-draggable-plus'
import { useCollectionStore } from '../stores/collection'

/**
 * Attach SortableJS to a sidebar container without giving it a reactive
 * list to mutate. Sortable is purely an input device here: on drop we
 * read the move out of the SortableEvent (kind/id from data attrs on the
 * dragged element, destination parent from the destination container's
 * `data-parent-id`, position from the merged sibling DOM order), then
 * dispatch a single `moveItem` action. The store applies the move
 * immutably and Vue re-renders authoritatively, so any DOM that Sortable
 * mutated gets replaced by Vue's render of the new state.
 *
 * Cross-container drops are an edge case for Vue: the moved node has been
 * physically relocated by Sortable into a different v-for's DOM range,
 * which can confuse keyed reconciliation across two separate v-fors. We
 * revert that DOM move before triggering the data update so each v-for
 * sees the world it expected when it last rendered.
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
      // Revert Sortable's cross-container DOM mutation. Vue is about to
      // re-render both v-fors based on the new tree; doing so while a
      // node already lives in the other v-for's range makes Vue either
      // fail to remove it from the source render or duplicate it in the
      // destination render. Putting it back puts both v-fors back in
      // sync with their own data.
      if (evt.from !== evt.to) {
        const ref = evt.from.children[evt.oldIndex] || null
        evt.from.insertBefore(evt.item, ref)
      }

      const item = evt.item
      const kind = item.dataset.kind
      const id = Number(item.dataset.id)
      if (!kind || ! Number.isFinite(id)) return

      const dest = evt.to
      const rawParent = dest.dataset.parentId
      // Root container has no data-parent-id (or empty string); group
      // containers carry the group id as a number.
      const parentId = rawParent ? Number(rawParent) : null

      // Position is the index of the moved item among the destination
      // container's draggable children. For same-container reorders we
      // read it from the post-mutation DOM; for cross-container moves
      // we just reverted, so we use evt.newIndex directly.
      const position = evt.from !== evt.to
        ? evt.newIndex
        : Array.from(dest.children).indexOf(item)
      if (position < 0) return

      collection.moveItem({ kind, id, parentId, position })
    },
  })
}
