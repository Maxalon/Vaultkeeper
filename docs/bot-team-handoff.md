# Bot team handoff — Caddy integration

> **For a fresh Claude session with read/write access to the Caddy
> infrastructure repo.** You are picking up a workstream in progress.
> The maintainer is `h.fellisch@gmail.com`; ping them in your first
> message to confirm context, but most of what you need is below.

## Mission

We are standing up an autonomous "app team" — four Claude Code
[Routines](https://code.claude.com/docs/en/routines) (PM + Dev 1/2/3)
that develop the Vaultkeeper Android client. The agents pick up Jira
tickets, write code, open PRs against `maxalon/vaultkeeper`, and
review each other's work. The human (Henrik) checks in via the routine
transcripts at `claude.ai/code/routines` whenever he wants.

This document is about **the Caddy-side of the deployment** for the
shared infrastructure that backs the team. The agent-prompt and
container work happen in `maxalon/vaultkeeper`; you don't need to
touch that.

## Where we are right now

- Android client scaffold landed in `maxalon/vaultkeeper` (PRs #247,
  #248, both merged to `staging`). Beta APK builds + ships via GH
  Releases on every staging push that touches `app/**`.
- Auth flow (login → JWT → home screen) verified end-to-end against
  the live staging API at `vault-staging.kontrollzentrale.de`.
- Five bot identities provisioned in Jira + GitHub (see "Identity
  slate" below). Tokens generated.
- Architecture for the bot team is locked (see "Architecture" below).
- **Not yet built:** the MCP container, the router container, the
  Caddy routes, the Tailscale Funnel exposure, the routine configs in
  Anthropic.

## Architecture (locked)

```
Anthropic cloud
 ┌──────────────────────────────┐
 │ Routines (PM, Dev 1/2/3)     │   one routine per bot identity
 └──────────────┬───────────────┘
                │ Streamable HTTP + headers:
                │   Authorization: Bearer $MCP_SHARED_SECRET
                │   X-Atlassian-Email: $JIRA_EMAIL      (per-bot)
                │   X-Atlassian-Token: $JIRA_TOKEN      (per-bot)
                │   X-GitHub-Token:    $GITHUB_TOKEN    (per-bot)
                ▼
 ┌──────────────────────────────────────────────────────┐
 │ Public ingress (Caddy on the staging host)           │
 │                                                       │
 │   mcp.kontrollzentrale.de       →  mcp:8080          │
 │   hooks.kontrollzentrale.de     →  router:9000       │
 │   (vault-staging.* and vault.* unchanged)            │
 └──────────┬───────────────────────────────────────────┘
            │ tailnet / docker network
            ▼
 ┌──────────────────────────────────────────────────────┐
 │ staging compose host                                 │
 │                                                       │
 │   vaultkeeper_mcp      Self-hosted Atlassian +       │
 │                        GitHub MCP. Stateless         │
 │                        passthrough — uses caller's   │
 │                        per-request headers as the    │
 │                        upstream credentials.         │
 │                                                       │
 │   vaultkeeper_router   Tiny webhook receiver.        │
 │                        Validates Jira HMAC + the     │
 │                        MCP shared secret, fires the  │
 │                        right Anthropic routine via   │
 │                        the Anthropic API.            │
 │                                                       │
 │   existing services    api, web, db, worker, nginx  │
 │                        (unchanged)                   │
 └──────────────────────────────────────────────────────┘
                ▲
                │
                │ inbound Jira Cloud webhooks
                │   POST hooks.kontrollzentrale.de/jira
                │   HMAC signed
                │
 ┌──────────────────────────────┐
 │ Jira Cloud (kontrollzentrale)│
 └──────────────────────────────┘
```

## Why this shape

| Decision | Reason |
|---|---|
| Self-hosted MCP, not Anthropic's managed Atlassian/GitHub connectors | Managed connectors are OAuth'd to a single human account. Every Jira write or git push would be attributed to Henrik instead of the bot identity that performed it. Self-hosted lets us pass per-bot credentials in headers so audit logs show the right author. |
| One MCP container, header-passthrough | One container, four identities. Each routine ships its bot's creds in env, MCP forwards them upstream. No per-bot containers. |
| Router on the same host | Receives Jira webhooks (Atlassian Cloud → public URL), validates HMAC, fires routine via Anthropic API. Doesn't itself call Atlassian — that's the agent's job via MCP. Clean separation. |
| Per-bot secrets live in Anthropic's routine-secret store, not on the host | Host compromise doesn't leak bot creds. Host only holds the shared MCP secret + Jira webhook HMAC secret + an Anthropic API key for the router. |
| Subdomains, not subpaths | `mcp.*` and `hooks.*` are different audiences (Anthropic infrastructure vs. Atlassian webhooks). Subdomains make TLS + future rate-limiting + access logs cleaner. |
| No "orchestrator" agent | Jira workflow + PM assignments are the orchestration. The router is dumb dispatch. |

## Identity slate

Five bots, with `+`-aliased emails:

| Role | Email | GitHub user | Jira role |
|---|---|---|---|
| Orchestrator | `h.fellisch+vk-app-orchestrator@gmail.com` | `vk-app-orchestrator` | — (GH-only) |
| PM | `h.fellisch+vk-app-pm@gmail.com` | — (Jira-only) | Developer on VAULT |
| Dev 1 | `h.fellisch+vk-app-dev-1@gmail.com` | `vk-app-dev-1` | Developer on VAULT |
| Dev 2 | `h.fellisch+vk-app-dev-2@gmail.com` | `vk-app-dev-2` | Developer on VAULT |
| Dev 3 | `h.fellisch+vk-app-dev-3@gmail.com` | `vk-app-dev-3` | Developer on VAULT |

Tokens / PATs generated for each, scoped narrowly (fine-grained GH
PATs, `maxalon/vaultkeeper` only; Jira API tokens with Developer-level
project access). Henrik has the values; ask him where they're stored
(1Password? `.env` on the host?).

The "Orchestrator" identity may not be needed long-term — see the "no
orchestrator agent" decision above. Reserve the identity but don't
spin up a routine for it yet.

## Build phases (you're doing phase 3)

1. **Routine prompt files** — committed to `maxalon/vaultkeeper` under
   `.claude/routines/{pm,dev-1,dev-2,dev-3}.md`. Done by another
   session.
2. **MCP + router containers** — added to Vaultkeeper's
   `docker-compose.yml` and `docker-compose.prod.yml`. Done by another
   session.
3. **Caddy routes + public DNS** — **this is you.**
4. **Routines created in Anthropic** with secrets configured + pointed
   at the public MCP URL. Done by Henrik in the Anthropic console.
5. **Jira webhook configured** to point at
   `hooks.kontrollzentrale.de/jira` with an HMAC secret. Done by
   Henrik in Jira.

## Your job — Caddy integration

Two new public subdomains, both terminated by Caddy on the staging
host. Concrete tasks:

### 1. Add DNS records

Henrik controls the `kontrollzentrale.de` zone. Confirm with him which
DNS provider it lives in, then add:

```
mcp.kontrollzentrale.de.     A     <staging-host-public-IP>
hooks.kontrollzentrale.de.   A     <staging-host-public-IP>
```

(Or `CNAME` to whatever existing record `vault-staging.*` uses — match
the existing pattern.)

### 2. Add Caddy site blocks

Pattern should match the existing site blocks for
`vault-staging.kontrollzentrale.de` and `vault.kontrollzentrale.de`
(automatic HTTPS, ACME via Let's Encrypt). The two new blocks need:

**`mcp.kontrollzentrale.de`** — reverse-proxies to the MCP container.
The container will listen on port `8080` inside the compose network
under the service name `vaultkeeper_mcp` (verify the exact name once
the compose changes land in the Vaultkeeper repo).

- Streamable HTTP transport — supports both POST request/response and
  long-lived SSE streams. Make sure proxy buffering is off for the SSE
  half (`flush_interval -1` or Caddy's equivalent).
- No path stripping needed — MCP servers expect requests at root.
- Bearer-token auth is enforced **inside the MCP container**, not at
  Caddy. Caddy is dumb pass-through. (If you want belt-and-suspenders,
  optionally add a `header_regexp Authorization "^Bearer "` matcher
  to reject anonymous traffic at the edge — but the MCP container will
  reject it anyway.)

**`hooks.kontrollzentrale.de`** — reverse-proxies to the router
container, listening on port `9000` inside the compose network under
the service name `vaultkeeper_router`.

- Plain HTTPS, no SSE needs. Standard reverse-proxy directive.
- HMAC validation happens inside the router.
- Two endpoints will be live: `POST /jira` (Atlassian webhook) and
  `POST /github/ci` (GitHub CI completion webhook for events that
  Routines don't natively subscribe to — CI run conclusions). Both
  validated by HMAC inside the router. Caddy passes through.

### 3. Verify

After the compose stack adds the two services (other session), reload
Caddy and confirm:

```bash
curl -i https://mcp.kontrollzentrale.de/
# expect 401 from the MCP container (missing bearer), NOT a Caddy
# 502/504. A 502 means the proxy can't reach the container.

curl -i https://hooks.kontrollzentrale.de/healthz
# expect 200 from the router (the router should expose a healthz
# endpoint — confirm with the other session if it's missing).
```

### 4. Coordinate back

Once the routes are live and verified, post a brief status update
**as a PR comment on the in-flight Vaultkeeper PR that adds the
compose changes** (Henrik will tell you the PR number — likely the
next one after #248). Include:
- The two URLs are live with valid certs (TLS report, cert expiry).
- `curl` outputs from the verify step above.
- Any deviations from this plan (e.g. if the subdomains conflict with
  an existing wildcard, if Henrik wanted a different naming, etc.)

That's enough for the Vaultkeeper-side session to wire up the routine
configs and the Jira webhook.

## Don't do

- Don't add authentication at the Caddy layer beyond the optional
  Bearer-presence check. Auth is the MCP container's job (per-request
  bot identity) and the router's job (HMAC). Layering Caddy auth on
  top would force every Anthropic Routine call through an extra
  credential, which Anthropic's routine secret store doesn't model
  cleanly.
- Don't expose the MCP or router endpoints on the tailnet-only path.
  Anthropic infrastructure and Atlassian Cloud both originate from
  the public internet — they cannot reach a tailnet-only service.
- Don't merge changes to Vaultkeeper. You don't have access. Confine
  edits to the Caddy repo + DNS.
- Don't generate or rotate any of the bot tokens. Those live in
  Henrik's secret store.

## Open questions to confirm with Henrik before acting

1. **DNS provider** for `kontrollzentrale.de` — which one, and does
   the Caddy repo's deploy pipeline include DNS automation, or is DNS
   manual?
2. **Existing wildcard** — is there a `*.kontrollzentrale.de` cert in
   use already that handles new subdomains automatically, or does
   each subdomain need its own ACME cert? Affects the Caddy site
   block shape.
3. **Hostname convention** — `mcp.*` and `hooks.*` are my proposal.
   Henrik may prefer `bots.*/mcp` and `bots.*/hooks` as subpaths under
   one host if it matches his existing naming.
4. **Tailscale Funnel involvement** — Henrik mentioned Funnel earlier
   as an option. If Caddy already does public ingress, Funnel is
   unnecessary. Confirm before adding any tailnet plumbing.

When this is done, hand control back to Henrik with a one-line
summary; he'll resume the main session that's driving the
Vaultkeeper-side work.
