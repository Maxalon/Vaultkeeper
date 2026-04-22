import { markRaw, defineAsyncComponent } from 'vue'

/**
 * Map of tab type → component. Used by LeafNode to render the active tab's
 * body. Async imports keep the initial deckbuilder bundle lean.
 */
const CatalogPanel    = defineAsyncComponent(() => import('../CatalogPanel.vue'))
const DeckTabContent  = defineAsyncComponent(() => import('../deck/DeckTabContent.vue'))
const AnalysisTab     = defineAsyncComponent(() => import('../deck/AnalysisTab.vue'))
const IllegalitiesTab = defineAsyncComponent(() => import('../deck/IllegalitiesTab.vue'))

export const tabRegistry = {
  deck:         { component: markRaw(DeckTabContent),  props: () => ({}) },
  side:         { component: markRaw(DeckTabContent),  props: () => ({ zone: 'side' }) },
  maybe:        { component: markRaw(DeckTabContent),  props: () => ({ zone: 'maybe' }) },
  catalog:      {
    component: markRaw(CatalogPanel),
    props: (tab, ctx = {}) => ({
      deckId: ctx.deckId ?? null,
      ...(tab.options || {}),
    }),
  },
  analysis:     { component: markRaw(AnalysisTab),     props: () => ({}) },
  illegalities: { component: markRaw(IllegalitiesTab), props: () => ({}) },
}
