# Caddy edge migration — operator runbook

Step-by-step host-side checklist for cutting over from the bundled
nginx edge to the external Caddy proxy in `maxalon/kontrollzentrale`.

The code-side work is on branch `claude/caddy-edge-migration-OB7vL`
in this repo and the same-named branch in `maxalon/kontrollzentrale`.

## Caveats / known follow-ups

- `docs/DEPLOYMENT.md` was not reviewed during the migration; it
  likely still references the old port-based / bundled-nginx setup.
  Worth a read-through after cutover and a separate PR if it has
  drifted.
- `OpsDbProxyController` still has its old X-Accel-Redirect methods
  alongside `check()`. Harmless — only `check()` is reachable now —
  but worth a follow-up cleanup PR.

## Pre-cutover (laptop / git side)

- [ ] Merge `claude/caddy-edge-migration-OB7vL` on **vaultkeeper** to
      `main` (or `staging` first if you want to test there). CI will
      build & push `vaultkeeper-api:<branch>` and
      `vaultkeeper-spa:<branch>` to GHCR.
- [ ] Merge `claude/caddy-edge-migration-OB7vL` on **kontrollzentrale**
      to `main`.
- [ ] Verify GitHub Actions ran green on the vaultkeeper deploy
      workflow's *build* job. The *deploy* job will fail until the
      host work below is done — that's expected.

## One-time host setup

- [ ] SSH to the deploy host. Back up the env files first:
      ```sh
      cp /srv/vaultkeeper_prod/.env.prod \
         /srv/vaultkeeper_prod/.env.prod.bak
      cp /srv/vaultkeeper_staging/.env.staging \
         /srv/vaultkeeper_staging/.env.staging.bak
      ```
- [ ] Create the shared edge network:
      ```sh
      docker network create edge
      ```
- [ ] Clone `maxalon/kontrollzentrale` to `/srv/kontrollzentrale`
      (or wherever) and check out `main`.

## Edit `.env.prod` on the host

- [ ] Add: `SESSION_DOMAIN=.vault.kontrollzentrale.de`
- [ ] Add: `SESSION_SECURE_COOKIE=true`
- [ ] Add: `ASSETS_PUBLIC_URL=https://assets.vault.kontrollzentrale.de`
- [ ] Change: `APP_URL=https://vault.kontrollzentrale.de`
- [ ] Remove: `HTTP_PORT`, `HTTPS_PORT`, `MINIO_CONSOLE_PORT`,
      `NGINX_IMAGE`
- [ ] Add/replace: `SPA_IMAGE=ghcr.io/maxalon/vaultkeeper-spa:main`
- [ ] If a `docker-compose.override.yml` exists with the old
      `/etc/vaultkeeper/certs` mount for nginx, **delete it** —
      Caddy owns TLS now and the override would just fail to mount
      a path that no longer matters.

## Edit `.env.staging` on the host

Same shape with staging values:

- [ ] `APP_URL=https://vault-staging.kontrollzentrale.de`
- [ ] `SESSION_DOMAIN=.vault-staging.kontrollzentrale.de`
- [ ] `SESSION_SECURE_COOKIE=true`
- [ ] `ASSETS_PUBLIC_URL=https://assets.vault-staging.kontrollzentrale.de`
- [ ] `SPA_IMAGE=ghcr.io/maxalon/vaultkeeper-spa:staging`
- [ ] Drop the same legacy keys.

## Set up kontrollzentrale

- [ ] In `/srv/kontrollzentrale`:
      ```sh
      cp .env.example .env
      ```
- [ ] Fill in `.env`:
  - `INWX_USER` / `INWX_PASSWORD` — the API subaccount credentials
    you already created.
  - `ACME_EMAIL` — any address you read.
- [ ] Build the image but don't start yet:
      ```sh
      docker compose build
      ```

## Other services (manual compose edits per host)

- [ ] **Immich** — drop the `11443:2283` port mapping; add `edge`
      network on `immich-server`. Confirm with `docker compose config`
      that the service still has `immich-server` as an alias on the
      edge network.
- [ ] **Nextcloud** — drop `10443:443` (or whatever HTTPS mapping
      exists); add `edge` network on the `app` service. Then edit
      `nextcloud/config/config.php`:
      ```php
      'trusted_domains' => ['cloud.kontrollzentrale.de'],
      'overwrite.cli.url' => 'https://cloud.kontrollzentrale.de',
      'overwriteprotocol' => 'https',
      'overwritehost' => 'cloud.kontrollzentrale.de',
      ```
- [ ] **Portainer** — drop `9443:9443`; add `edge` network. If
      currently launched with `--ssl` flags, switch to plain-HTTP mode
      (Caddy hits port 9000).
- [ ] **Cockpit** — edit `/etc/cockpit/cockpit.conf`:
      ```ini
      [WebService]
      Origins = https://cockpit.kontrollzentrale.de wss://cockpit.kontrollzentrale.de
      ProtocolHeader = X-Forwarded-Proto
      AllowUnencrypted = false
      ```
      Then `sudo systemctl restart cockpit`.

## Verify upstream container names match the Caddyfile

After bringing vaultkeeper up (next step) but **before** Caddy:

- [ ] Run:
      ```sh
      docker network inspect edge \
        | grep -E 'Name|Aliases' -A2
      ```
- [ ] Confirm these names exist on the network — they're what the
      Caddyfile expects:
      ```
      vaultkeeper_prod-spa-1
      vaultkeeper_prod-api-1
      vaultkeeper_prod-adminer-1
      vaultkeeper_prod-minio-1
      vaultkeeper_staging-spa-1
      vaultkeeper_staging-api-1
      vaultkeeper_staging-adminer-1
      vaultkeeper_staging-minio-1
      immich-server
      nextcloud-app-1
      portainer
      ```
- [ ] If any name differs, edit `kontrollzentrale/Caddyfile` to match
      before bringing Caddy up.

## Cutover

- [ ] Bring vaultkeeper prod down + back up:
      ```sh
      cd /srv/vaultkeeper_prod
      docker compose -f docker-compose.prod.yml -p vaultkeeper_prod down
      docker compose -f docker-compose.prod.yml -p vaultkeeper_prod \
          --env-file .env.prod pull
      docker compose -f docker-compose.prod.yml -p vaultkeeper_prod \
          --env-file .env.prod up -d
      ```
- [ ] Same for staging with `-p vaultkeeper_staging` and `.env.staging`.
- [ ] Bring up Immich / Nextcloud / Portainer
      (`docker compose down && docker compose up -d` per project).
- [ ] Restart Cockpit if not already done.
- [ ] Bring up Caddy and watch for cert issuance:
      ```sh
      cd /srv/kontrollzentrale
      docker compose up -d
      docker compose logs -f caddy
      ```
      Look for `certificate obtained successfully` × 3 (one per
      wildcard). 30–90s each.

## Functional verification

- [ ] `curl -I https://vault.kontrollzentrale.de` returns 200, valid
      Let's Encrypt cert.
- [ ] Browser login flow on `vault.kontrollzentrale.de` works; cookie
      domain is `.vault.kontrollzentrale.de` (DevTools → Application
      → Cookies).
- [ ] An MTG card detail page renders images (proves
      `assets.vault.kontrollzentrale.de` is reachable + bucket is
      anon-readable).
- [ ] `https://adminer.vault.kontrollzentrale.de` from a fresh private
      window 302s to
      `horizon.vault.kontrollzentrale.de/horizon-login?next=…`. After
      ops password, redirects back to Adminer's MariaDB login.
- [ ] `https://horizon.vault.kontrollzentrale.de/horizon` already
      authenticated in another tab.
- [ ] Repeat the auth checks against staging — staging session must
      NOT grant access to prod adminer (cookie domains are isolated).
- [ ] `cloud.kontrollzentrale.de` doesn't redirect-loop. If it does
      → `trusted_domains` / `overwriteprotocol` is wrong.
- [ ] Cockpit's terminal works (websocket through Caddy).
- [ ] `nc -zv 127.0.0.1 8443` and `:11443`, `:10443`, `:9443` all
      fail (nothing exposed on host except `:80`/`:443`/Cockpit's
      `:9090`).
- [ ] From a non-tailnet device:
      `https://vault.kontrollzentrale.de` should fail to connect
      (CGNAT IP, unreachable off-tailnet).

## Cleanup (after a few days running clean)

- [ ] Remove any old Tailscale cert renewal cron / systemd timer
      that fed the bundled nginx.
- [ ] Update bookmarks / `~/.ssh/config` / scripts that pointed at
      the old `:8443` / `:11443` / etc.
- [ ] Read through `docs/DEPLOYMENT.md` — flag a follow-up PR if it
      still talks about ports or bundled nginx.
- [ ] Open the follow-up PR for `OpsDbProxyController` cleanup
      (delete the X-Accel-Redirect methods, keep `check()`).

## Rollback (if cutover fails for >30 min)

```sh
cd /srv/kontrollzentrale && docker compose down

cd /srv/vaultkeeper_prod
git checkout main           # or whatever was deployed before
cp .env.prod.bak .env.prod
docker compose -f docker-compose.prod.yml -p vaultkeeper_prod down
docker compose -f docker-compose.prod.yml -p vaultkeeper_prod \
    --env-file .env.prod up -d --build

# repeat for staging, restore Immich/Nextcloud/Portainer compose,
# restart cockpit
```

The `caddy_data` volume can stay — re-attempting later reuses the
issued certs.
