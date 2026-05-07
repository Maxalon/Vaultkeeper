/**
 * Realistic-shape sidebar tree for the harness.
 *
 * Every drag scenario we've hit a regression on is represented:
 *   - top-level location  ('Main Drawer')
 *   - top-level group with mixed children (concept-drawer + concept-deck)
 *   - top-level group with a NESTED group (assembled-decks > blank-box)
 *     containing its own deck — the case that bit us with phantom copies
 *   - top-level deck row (so we can drop into a group)
 *
 * Shape mirrors what `LocationGroupController@index` returns so the
 * components see exactly the data they would in production.
 */
export const mockTree = [
  {
    kind: 'location',
    id: 1,
    group_id: null,
    type: 'drawer',
    name: 'Main Drawer',
    set_codes: null,
    description: null,
    sort_order: 0,
    card_count: 42,
  },
  {
    kind: 'group',
    id: 10,
    name: 'Concepts',
    parent_group_id: null,
    sort_order: 1,
    children: [
      {
        kind: 'location',
        id: 2,
        group_id: 10,
        type: 'drawer',
        name: 'Concept Drawer',
        set_codes: null,
        description: null,
        sort_order: 0,
        card_count: 5,
      },
      {
        kind: 'deck',
        id: 3,
        deck_id: 100,
        group_id: 10,
        sort_order: 1,
        name: 'Concept Deck',
        format: 'commander',
        color_identity: ['G'],
        entry_count: 99,
        illegality_count: 0,
        commander1: null,
      },
    ],
  },
  {
    kind: 'group',
    id: 11,
    name: 'Assembled Decks',
    parent_group_id: null,
    sort_order: 2,
    children: [
      {
        kind: 'group',
        id: 12,
        name: 'Blank Box',
        parent_group_id: 11,
        sort_order: 0,
        children: [
          {
            kind: 'deck',
            id: 4,
            deck_id: 101,
            group_id: 12,
            sort_order: 0,
            name: 'Blank Deck',
            format: 'commander',
            color_identity: ['U'],
            entry_count: 100,
            illegality_count: 0,
            commander1: null,
          },
        ],
      },
      {
        kind: 'location',
        id: 5,
        group_id: 11,
        type: 'binder',
        name: 'Side Binder',
        set_codes: null,
        description: null,
        sort_order: 1,
        card_count: 12,
      },
    ],
  },
  {
    kind: 'deck',
    id: 6,
    deck_id: 102,
    group_id: null,
    sort_order: 3,
    name: 'Top Pet Deck',
    format: 'modern',
    color_identity: ['R'],
    entry_count: 60,
    illegality_count: 0,
    commander1: null,
  },
]

export const mockTotalCount = 158
