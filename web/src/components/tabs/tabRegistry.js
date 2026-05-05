import { markRaw, defineAsyncComponent } from 'vue'

/**
 * Map of tab type → component. Used by LeafNode to render the active tab's
 * body. Async imports keep the initial deckbuilder bundle lean.
 */
const CatalogPanel        = defineAsyncComponent(() => import('../CatalogPanel.vue'))
const DeckTabContent      = defineAsyncComponent(() => import('../deck/DeckTabContent.vue'))
const AnalysisTab         = defineAsyncComponent(() => import('../deck/AnalysisTab.vue'))
const IllegalitiesTab     = defineAsyncComponent(() => import('../deck/IllegalitiesTab.vue'))
const ReviewList = defineAsyncComponent(() => import('../review/ReviewList.vue'))

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
  review:       {
    component: markRaw(ReviewList),
    // Per-deck Review tab — scope the list to copies that came from
    // this deck. The shared component handles its own header/footer
    // chrome; we hide the page-level title since the tab bar already
    // labels it.
    props: (_tab, ctx = {}) => ({
      scope: 'deck',
      deckId: ctx.deckId ?? null,
      hideHeader: true,
    }),
  },
}
