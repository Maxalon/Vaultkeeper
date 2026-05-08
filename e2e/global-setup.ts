import { request, APIResponse } from '@playwright/test'
import { BASE_URL } from './playwright.config'

const HINT =
  `\n  See e2e/README.md — start the dev stack with \`docker compose up -d\`` +
  `\n  (and ensure podman.socket + DOCKER_HOST are set up first).\n`

export default async function globalSetup() {
  const ctx = await request.newContext()
  let res: APIResponse
  try {
    res = await ctx.get(`${BASE_URL}/api/cards/featured`, { timeout: 5_000 })
  } catch (err) {
    await ctx.dispose()
    throw new Error(
      `\n  Vaultkeeper stack not reachable at ${BASE_URL} (${(err as Error).message}).` + HINT,
    )
  }
  await ctx.dispose()
  if (!res.ok() && res.status() !== 204) {
    throw new Error(
      `\n  Vaultkeeper stack at ${BASE_URL} returned HTTP ${res.status()} from /api/cards/featured.` +
        HINT,
    )
  }
}
