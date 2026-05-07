import api from '@web/lib/api'

/**
 * In-memory adapter for the harness. Replaces axios's default adapter so
 * no real network calls leave the page. Each handler returns the response
 * body the production backend would; adapter wraps it in the axios
 * response envelope.
 *
 * Move events are recorded on `window.__moveLog` and rendered into
 * `#event-log` so Playwright can assert against them.
 */

const moveLog = []

const handlers = {
  // The collection store expects this shape; the harness's main.js also
  // pre-populates the store directly so the very first render doesn't
  // depend on this round-trip.
  'GET /location-groups': () => ({
    items: window.__sandboxTree ?? [],
    total_count: window.__sandboxTotalCount ?? 0,
    review: null,
  }),
  'POST /location-groups/move': (config) => {
    let body = {}
    try { body = JSON.parse(config.data || '{}') } catch { /* harness data is always JSON */ }
    moveLog.push(body)
    window.__moveLog = [...moveLog]
    renderEventLog()
    return null // 204 No Content
  },
}

function renderEventLog() {
  const el = document.querySelector('#event-log')
  if (el) el.textContent = JSON.stringify(moveLog, null, 2)
}

function matchKey(config) {
  const method = (config.method || 'get').toUpperCase()
  // baseURL on the api instance is `/api`; the path passed through is
  // what we match against.
  const url = config.url || ''
  return `${method} ${url}`
}

export function installApiMocks() {
  api.defaults.adapter = async (config) => {
    const key = matchKey(config)
    const handler = handlers[key]
    if (!handler) {
      // Surface unmocked calls clearly so a forgotten handler shows up
      // instead of silently hanging.
      // eslint-disable-next-line no-console
      console.warn('[sandbox] unmocked', key)
      return Promise.reject({
        response: {
          status: 404,
          data: { message: `unmocked ${key}` },
          config,
        },
      })
    }
    const data = handler(config)
    return {
      data,
      status: data === null ? 204 : 200,
      statusText: 'OK',
      headers: {},
      config,
    }
  }
}

export function getMoveLog() {
  return [...moveLog]
}

export function resetMoveLog() {
  moveLog.length = 0
  window.__moveLog = []
  renderEventLog()
}
