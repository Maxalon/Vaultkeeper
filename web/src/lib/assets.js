// Single source of truth for static-asset URLs (set symbols, mana
// symbols, card-back fallback). VITE_ASSETS_URL is baked in by Vite
// at build time — in prod it points at the env's assets.* subdomain;
// in dev it's unset and we fall back to /storage so the local Vite
// proxy / Laravel storage:link path still works without changes.
const base = (import.meta.env.VITE_ASSETS_URL || '/storage').replace(/\/$/, '')

export const assetUrl = (path) =>
  `${base}${path.startsWith('/') ? path : '/' + path}`
