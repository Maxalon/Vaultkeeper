/**
 * Friends + Notifications endpoint mock fixtures.
 *
 * Consumed by:
 *   1. The claude-sandbox axios adapter (claude-sandbox/mocks/api.js) — import
 *      installFriendsMocks() and call it alongside installApiMocks().
 *   2. Vitest unit tests — import the named fixture objects directly.
 *
 * Every shape mirrors the real API contract documented in .claude/plans/friends-list.md.
 */

import friendsFixture from './friends.json'
import requestsIncomingFixture from './requests-incoming.json'
import requestsOutgoingFixture from './requests-outgoing.json'
import userSearchFixture from './user-search.json'
import privacySettingsFixture from './privacy-settings.json'
import notificationsFixture from './notifications.json'
import friendCollectionFixture from './friend-collection.json'

export {
  friendsFixture,
  requestsIncomingFixture,
  requestsOutgoingFixture,
  userSearchFixture,
  privacySettingsFixture,
  notificationsFixture,
  friendCollectionFixture,
}

/**
 * Install friends/notifications route handlers into an existing axios
 * in-memory adapter `handlers` map (the plain object from claude-sandbox).
 *
 * Pattern-matched routes (e.g. `/users/{user}/collection`) use a simple
 * prefix-match helper since the sandbox adapter uses string keys.
 *
 * Usage in claude-sandbox/mocks/api.js:
 *
 *   import { friendHandlers } from '@web/__mocks__/friends/index.js'
 *   Object.assign(handlers, friendHandlers)
 */
export const friendHandlers = {
  // ── Friend graph ──────────────────────────────────────────────────────────
  'GET /friends': () => friendsFixture,

  'DELETE /friends/2': () => null,
  'DELETE /friends/3': () => null,
  'DELETE /friends/4': () => null,

  // ── Friend requests ───────────────────────────────────────────────────────
  'GET /friends/requests': (config) => {
    const url = config.url || ''
    const direction = new URLSearchParams(url.split('?')[1] || '').get('direction')
    if (direction === 'outgoing') return requestsOutgoingFixture
    return requestsIncomingFixture
  },

  'POST /friends/requests': () => ({
    id: 99,
    addressee: { id: 8, username: 'greta_sealed', display_name: 'Greta' },
    status: 'pending',
    created_at: new Date().toISOString(),
  }),

  'PATCH /friends/requests/10': () => ({ id: 10, status: 'accepted' }),
  'PATCH /friends/requests/11': () => ({ id: 11, status: 'declined' }),
  'PATCH /friends/requests/20': () => ({ id: 20, status: 'accepted' }),

  'DELETE /friends/requests/10': () => null,
  'DELETE /friends/requests/11': () => null,
  'DELETE /friends/requests/20': () => null,

  // ── User search ───────────────────────────────────────────────────────────
  'GET /users/search': () => userSearchFixture,

  // ── Privacy settings ──────────────────────────────────────────────────────
  'GET /privacy-settings': () => privacySettingsFixture,
  'PATCH /privacy-settings': (config) => {
    let body = {}
    try { body = JSON.parse(config.data || '{}') } catch { /* ok */ }
    return { ...privacySettingsFixture, ...body }
  },

  // ── Notifications ─────────────────────────────────────────────────────────
  'GET /notifications': () => notificationsFixture,

  'POST /notifications/1/read': () => null,
  'POST /notifications/2/read': () => null,
  'POST /notifications/3/read': () => null,
  'POST /notifications/4/read': () => null,
  'POST /notifications/read-all': () => null,

  // Action gateway — accept friend request
  'POST /notifications/1/actions/accept': () => ({ ok: true }),
  'POST /notifications/1/actions/decline': () => ({ ok: true }),
  // Action gateway — undo bulk import
  'POST /notifications/3/actions/undo': () => ({ ok: true }),
  // Action gateway — stale actions (return 409 to simulate unavailable)
  'POST /notifications/4/actions/sold_or_discarded': () => null,
  'POST /notifications/4/actions/move_to_drawer': () => null,

  // ── Friend collection (read-only) ─────────────────────────────────────────
  'GET /users/2/collection': () => friendCollectionFixture,
  'GET /users/3/collection': () => friendCollectionFixture,
  'GET /users/4/collection': () => friendCollectionFixture,
}
