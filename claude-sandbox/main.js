import { createApp, h } from 'vue'
import { createPinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'

import LocationSidebar from '@web/components/LocationSidebar.vue'
import { useCollectionStore } from '@web/stores/collection'
import { useSettingsStore } from '@web/stores/settings'
import { useAuthStore } from '@web/stores/auth'

import '@web/style.css'

import { installApiMocks, getMoveLog } from './mocks/api.js'
import { mockTree, mockTotalCount } from './mocks/data.js'

// Expose the tree on window so the API mock can read it (and so a test
// can also reset between scenarios if it ever needs to).
window.__sandboxTree = JSON.parse(JSON.stringify(mockTree))
window.__sandboxTotalCount = mockTotalCount

// Minimal router covering the routes LocationSidebar references in
// activate(), openDeck(), goToReview(), and isActive(). We don't need
// real components — just route names.
const StubView = { render: () => h('div') }
const router = createRouter({
  history: createMemoryHistory(),
  routes: [
    { path: '/', name: 'collection', component: StubView },
    { path: '/deck/:id', name: 'deck', component: StubView },
    { path: '/review', name: 'review', component: StubView },
  ],
})

// Wrapper that lays out the sidebar at a realistic width plus a panel
// on the right where we can render the move log for visual debugging.
// Uses a render function instead of a template string — Vite resolves
// `vue` to the runtime-only build, which has no template compiler.
const Harness = {
  render() {
    return h('div', { class: 'harness-shell' }, [
      h(LocationSidebar),
      h('main', { class: 'harness-main' }, [
        h('h2', 'Sidebar harness'),
        h('p', [
          'Production ',
          h('code', 'LocationSidebar'),
          ' mounted with mock Pinia state. Move requests are intercepted in-memory; each one is recorded below.',
        ]),
        h('h3', 'Move log'),
        h('pre', { id: 'event-log' }),
      ]),
    ])
  },
}

const app = createApp(Harness)
const pinia = createPinia()
app.use(pinia)
app.use(router)

// Hydrate auth so api requests (intercepted) carry a fake token.
const auth = useAuthStore()
auth.token = 'sandbox-token'
auth.user = { id: 1, name: 'Sandbox User', email: 'sandbox@local' }

// Settings: hydrate from localStorage defaults, then force-enable the
// drag handle so it's hoverable / clickable in tests. The handle is
// otherwise width:0 and visually hidden until hover, but force-enable
// also matters for keyboard / programmatic targeting.
const settings = useSettingsStore()
settings.hydrate()
settings.sidebarShowDrag = true

// Pre-populate the collection store. fetchGroups is also wired up via
// the API mock — but populating directly means the first render shows
// data immediately and doesn't depend on the round-trip's microtask
// timing.
const collection = useCollectionStore()
collection.sidebarItems = JSON.parse(JSON.stringify(mockTree))
collection.totalCount = mockTotalCount
collection.review = null

// Install the in-memory axios adapter. Must run BEFORE any store action
// that would hit the network.
installApiMocks()

// Expose hooks for Playwright assertions.
window.__sandbox = {
  getMoveLog,
  getCollectionState: () => JSON.parse(JSON.stringify({
    sidebarItems: collection.sidebarItems,
    totalCount: collection.totalCount,
    sidebarExternalEpoch: collection.sidebarExternalEpoch,
  })),
}

router.push('/').then(() => app.mount('#app'))

// Inject a tiny stylesheet for the harness shell layout — kept inline
// so the harness has zero non-Vite asset deps.
const style = document.createElement('style')
style.textContent = `
  .harness-shell {
    display: grid;
    grid-template-columns: 280px 1fr;
    height: 100vh;
    --sidebar-width: 280px;
  }
  .harness-main {
    padding: 24px;
    overflow: auto;
    color: var(--ink-70);
    font-family: var(--font-sans), system-ui, sans-serif;
    background: var(--bg-0);
  }
  .harness-main h2 { margin-top: 0; color: var(--ink-100); }
  .harness-main pre {
    background: var(--bg-2);
    color: var(--ink-100);
    padding: 12px;
    border-radius: 6px;
    font-family: var(--font-mono), ui-monospace, monospace;
    font-size: 12px;
    white-space: pre-wrap;
  }
`
document.head.appendChild(style)
