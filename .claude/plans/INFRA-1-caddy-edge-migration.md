# INFRA-1 — Caddy edge migration

Move from per-project nginx + port-based access to a single Caddy edge
proxy fronting every service on the host, with hostname-based routing
under `kontrollzentrale.de`. Strip Vaultkeeper's bundled nginx entirely
("Plan B" / all-at-once); demote other services (Immich, Nextcloud,
Portainer, Cockpit) to the shared Docker edge network so Caddy is the
only public listener on `:443`.

Two repos in play:

- **`maxalon/kontrollzentrale`** (new, empty) — the edge: Caddy build,
  Caddyfile, compose, env. Operator must give the executing session
  write access to this repo.
- **`maxalon/vaultkeeper`** (this repo) — strip nginx, add SPA sidecar,
  switch to subdomain assets, cross-subdomain session cookies, forward-auth
  redirect targets per env. **Develop on a new branch off `main`,
  not** `claude/fix-db-redirect-issue-MITz6` (that branch is for an
  unrelated nginx fix and may or may not be merged when this work starts).

---

## Decisions locked in during planning

1. **Caddy as the single edge on `:443` and `:80`.** Replaces every
   project's per-project TLS listener. Runs in a Docker container on the
   same host as everything else.

2. **Custom Caddy build with `caddy-dns/inwx` plugin.** DNS hosting stays
   at INWX; Let's Encrypt cert issuance via DNS-01 against the INWX API.
   Standard `caddy:latest` does not include the plugin — multi-stage
   `caddy:builder` Dockerfile produces the image we run.

3. **Three wildcard certs**, one Caddyfile site block fronting all routes:
   - `*.kontrollzentrale.de`
   - `*.vault.kontrollzentrale.de`
   - `*.vault-staging.kontrollzentrale.de`

   Two-level wildcards needed because Adminer/Horizon/assets sit one
   label below `vault.*`. Hostnames stay out of CT logs.

4. **Strip Vaultkeeper's nginx entirely.** All six jobs it does (SPA
   static, PHP-FPM fastcgi, MinIO `/storage` proxy, `/db` auth_request,
   rate limit, security headers) move to Caddy or are dropped.

5. **SPA served by a tiny sidecar in Vaultkeeper compose**, not baked
   into the Caddy image. Keeps the SPA build artifact in the project
   that owns the SPA. The sidecar is `nginx:alpine` serving the prebuilt
   `dist/` over plain HTTP on the docker network — Caddy proxies to it
   by container name. (Yes, ironic. It is still 4 lines of nginx
   configuration and zero TLS.)

6. **Adminer and Horizon on their own subdomains**, not paths.
   - `adminer.vault.kontrollzentrale.de`
   - `horizon.vault.kontrollzentrale.de`
   - same with `vault-staging` for staging.

7. **Adminer ops-password gate is preserved as Caddy `forward_auth`.**
   The Laravel `/__db_auth` endpoint stays unchanged (returns 204 or
   401). On 401, Caddy redirects to that env's horizon-login subdomain.
   Two layers of auth (ops password + Adminer's own MariaDB login) kept
   for defense in depth.

8. **Cross-subdomain session cookie.** `SESSION_DOMAIN=.vault.kontrollzentrale.de`
   (prod) and `.vault-staging.kontrollzentrale.de` (staging) so a single
   Horizon login covers `vault.*`, `horizon.vault.*`, `adminer.vault.*`,
   `assets.vault.*` for the same env. Prod and staging cookies are
   completely isolated by domain.

9. **Assets on a dedicated subdomain, not a path.**
   - `assets.vault.kontrollzentrale.de` (prod)
   - `assets.vault-staging.kontrollzentrale.de` (staging)

   `ASSETS_PUBLIC_URL` already exists in the Laravel filesystem config
   (`api/config/filesystems.php:77`), currently set to `${APP_URL}/storage`.
   Switch to the absolute assets URL. SPA needs a new build-time
   `VITE_ASSETS_URL` to construct image paths.

10. **Edge rate limits dropped.** Laravel-level throttling stays. Caddy
    rate-limit plugin is third-party and not worth the build complexity
    for a tailnet-only service.

11. **Edge network strategy: shared external Docker network `edge`.**
    Caddy joins it. Vaultkeeper's `api`, `adminer`, `minio`, `spa`
    sidecar join it. Immich, Nextcloud, Portainer join it. Internal
    project networks (Vaultkeeper's mariadb/redis) stay private to the
    project. No service should expose host ports anymore — Caddy is the
    only thing listening on the host.

12. **All services go through Caddy "in one swoop"** including Immich
    and Nextcloud (the operator confirmed they can be down for
    extended periods if needed; this is a private homelab).

13. **TCP healthcheck on the api container.** The current `curl /up`
    healthcheck depended on the local nginx; with nginx gone, a plain
    TCP check on `:9000` (php-fpm) is enough.

14. **Caddy talks plain HTTP to backends over the docker network.**
    Standard reverse-proxy pattern. TLS terminates at Caddy.

15. **Cockpit lives outside Docker** (systemd unit on the host). Caddy
    reaches it via `host.docker.internal:9090` (with
    `extra_hosts: host-gateway` on the Caddy service so the alias
    resolves on Linux).

## End-state architecture

Single host running Docker. One shared external network `edge`. Caddy
is the only process bound to host ports `:80` and `:443`. Nothing else
exposes host ports.

```
Internet (tailnet only) ── 100.113.247.87:443 ──▶ caddy
                                                     │
                       ┌─────────────────────────────┼────────────────────────────┐
                       │                             │                            │
   vault.kontrollzentrale.de                horizon.vault.kontrollzentrale.de
       → vaultkeeper-prod_spa:80              → vaultkeeper-prod_api:9000 (fastcgi)
                                              [forward_auth: status=horizon_authed]

   vault-staging.kontrollzentrale.de         horizon.vault-staging.kontrollzentrale.de
       → vaultkeeper-staging_spa:80            → vaultkeeper-staging_api:9000 (fastcgi)

   adminer.vault.kontrollzentrale.de          adminer.vault-staging.kontrollzentrale.de
       forward_auth → vaultkeeper-prod_api:9000 /__db_auth
                      ↳ 401 → redir https://horizon.vault.kontrollzentrale.de/horizon-login?next={uri}
       → vaultkeeper-prod_adminer:8080         (same pattern, staging env)

   assets.vault.kontrollzentrale.de           assets.vault-staging.kontrollzentrale.de
       → vaultkeeper-prod_minio:9000           → vaultkeeper-staging_minio:9000
       (path-rewrite: prepends /<ASSETS_BUCKET>/)

   immich.kontrollzentrale.de       → immich-server:2283 (or whatever its internal port is)
   cloud.kontrollzentrale.de        → nextcloud:80
   portainer.kontrollzentrale.de    → portainer:9000
   cockpit.kontrollzentrale.de      → host.docker.internal:9090 (host systemd unit)
```

Vaultkeeper's `api`, `worker`, `scheduler` containers all share the same
project-internal network for MariaDB/Redis access. Only `api`, `adminer`,
`minio`, and `spa` join the shared `edge` network for Caddy access.
Worker and scheduler don't need edge access — they're not HTTP-facing.

`api/config/filesystems.php:77` `ASSETS_PUBLIC_URL` switches from
`${APP_URL}/storage` to the absolute `https://assets.vault[-staging].kontrollzentrale.de`.
The SPA receives the same value at build time as `VITE_ASSETS_URL` and
constructs image URLs as `${ASSETS_URL}/symbols/W.svg` etc.

Browser flow: client only ever talks to `:443`. TLS terminates at
Caddy. Caddy proxies plain HTTP to backends over the `edge` network.

## Pre-existing state when this plan starts

Already done by the operator before handing off; do not redo:

1. **Domain `kontrollzentrale.de` registered at INWX** as a private
   individual (Privatperson). Admin-C is the operator. WHOIS does not
   publish personal info.

2. **DNS records at INWX** pointing the apex and wildcard at the host's
   Tailscale IP. Confirmed propagating via Cloudflare's resolver:

   ```
   kontrollzentrale.de.            300  IN  A  100.113.247.87
   *.kontrollzentrale.de.          300  IN  A  100.113.247.87
   ```

   The `100.x.y.z` is the Tailscale IP of the host that runs all
   the Docker services. CGNAT, unreachable from outside the tailnet —
   that is intentional.

3. **INWX API subaccount created** with DNS-edit permissions on
   `kontrollzentrale.de` only. Username + password are what Caddy will
   need in env vars (`INWX_USER`, `INWX_PASSWORD`). 2FA off on this
   subaccount; permissions tightly scoped instead.

4. **`maxalon/kontrollzentrale` repo created on GitHub**, empty.

5. **Tailscale DNS settings:** Global nameservers set to `1.1.1.1` /
   `1.0.0.1` with "Override local DNS" toggled on. MagicDNS still on for
   `.ts.net` resolution. (Done as part of the earlier discussion to
   bypass DNS rebinding filters on hostile networks.)

Things explicitly NOT done yet:

- No Caddy or other edge proxy is running on the host.
- All services are still on their original host ports
  (Vaultkeeper prod `:443`/`:80`, staging `:8443`/`:8080`, Immich
  `:11443`, Nextcloud `:10443`, Portainer `:9443`, Cockpit `:9090`).
- Vaultkeeper still has its bundled nginx as the TLS terminator.
- No external Docker network exists.
- No `assets.*` URLs work yet (404 on Caddy = fine, expected).
- The `claude/fix-db-redirect-issue-MITz6` branch contains an
  `absolute_redirect off;` line in `docker/nginx/default.prod.conf.template`
  that is unrelated to this migration and is moot once nginx is removed.
  Do not block on whether it's merged.

## Work — `kontrollzentrale` repo (new)

Repository is empty at start. Final layout:

```
kontrollzentrale/
├── Dockerfile                  # custom Caddy build with caddy-dns/inwx
├── compose.yml                 # caddy service, joins external `edge` network
├── Caddyfile                   # routes for every service
├── .env.example                # INWX_USER, INWX_PASSWORD, ACME_EMAIL placeholders
├── .gitignore                  # .env, caddy_data/, caddy_config/
└── README.md                   # bring-up + add-a-service instructions
```

### `Dockerfile`

Standard Caddy plugin pattern. Two stages: `caddy:builder` produces a
caddy binary with the INWX plugin, final stage `caddy:alpine` runs it.

```dockerfile
ARG CADDY_VERSION=2

FROM caddy:${CADDY_VERSION}-builder AS builder
RUN xcaddy build \
    --with github.com/caddy-dns/inwx

FROM caddy:${CADDY_VERSION}-alpine
COPY --from=builder /usr/bin/caddy /usr/bin/caddy
```

Pin `CADDY_VERSION` to a real version in compose `build.args` once
chosen (e.g. `2.10.2` or whatever's latest stable when the migration
runs). Do not leave as floating `2`.

### `compose.yml`

```yaml
services:
  caddy:
    build:
      context: .
      args:
        CADDY_VERSION: 2.10.2  # pin in the executing session
    image: kontrollzentrale-caddy:local
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
      - "443:443/udp"   # HTTP/3
    environment:
      INWX_USER: ${INWX_USER}
      INWX_PASSWORD: ${INWX_PASSWORD}
      ACME_EMAIL: ${ACME_EMAIL}
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - caddy_data:/data       # certs, ACME state — DO NOT lose
      - caddy_config:/config
    networks:
      - edge
    extra_hosts:
      - "host.docker.internal:host-gateway"  # for cockpit on host

volumes:
  caddy_data:
  caddy_config:

networks:
  edge:
    external: true
    name: edge
```

The `edge` network is declared `external: true` because every project
on the host references the same network by name. It is created **once,
manually**, before any compose project is brought up:

```sh
docker network create edge
```

### `Caddyfile`

```caddyfile
{
    email {env.ACME_EMAIL}
}

# ─── Vaultkeeper prod ────────────────────────────────────────────────
vault.kontrollzentrale.de {
    tls {
        dns inwx {
            username {env.INWX_USER}
            password {env.INWX_PASSWORD}
        }
    }
    encode zstd gzip
    reverse_proxy vaultkeeper-prod_spa:80
}

horizon.vault.kontrollzentrale.de {
    tls {
        dns inwx {
            username {env.INWX_USER}
            password {env.INWX_PASSWORD}
        }
    }
    php_fastcgi vaultkeeper-prod_api:9000 {
        root /var/www/html/public
    }
}

adminer.vault.kontrollzentrale.de {
    tls {
        dns inwx {
            username {env.INWX_USER}
            password {env.INWX_PASSWORD}
        }
    }
    forward_auth vaultkeeper-prod_api:9000 {
        uri /__db_auth
        method GET
        # On 401, redirect to horizon-login on the prod horizon subdomain
        @denied status 401
        handle_response @denied {
            redir https://horizon.vault.kontrollzentrale.de/horizon-login?next={scheme}://{host}{uri} 302
        }
    }
    reverse_proxy vaultkeeper-prod_adminer:8080
}

assets.vault.kontrollzentrale.de {
    tls {
        dns inwx {
            username {env.INWX_USER}
            password {env.INWX_PASSWORD}
        }
    }
    rewrite * /vaultkeeper-assets{uri}
    reverse_proxy vaultkeeper-prod_minio:9000 {
        header_up Host {upstream_hostport}
    }
    header Cache-Control "public, immutable, max-age=604800"
}

# ─── Vaultkeeper staging ─────────────────────────────────────────────
# (mirror of prod block, with vault-staging hosts and
#  vaultkeeper-staging_* container names. Bucket name from staging env
#  is `vaultkeeper-staging-assets`. Same forward_auth pattern, redirecting
#  to horizon.vault-staging.kontrollzentrale.de on 401.)

# ─── Personal services ───────────────────────────────────────────────
immich.kontrollzentrale.de {
    tls { dns inwx { username {env.INWX_USER} password {env.INWX_PASSWORD} } }
    reverse_proxy immich_server:2283
}

cloud.kontrollzentrale.de {
    tls { dns inwx { username {env.INWX_USER} password {env.INWX_PASSWORD} } }
    reverse_proxy nextcloud_app:80
}

portainer.kontrollzentrale.de {
    tls { dns inwx { username {env.INWX_USER} password {env.INWX_PASSWORD} } }
    reverse_proxy portainer:9000
}

cockpit.kontrollzentrale.de {
    tls { dns inwx { username {env.INWX_USER} password {env.INWX_PASSWORD} } }
    reverse_proxy https://host.docker.internal:9090 {
        transport http {
            tls
            tls_insecure_skip_verify   # cockpit's self-signed cert
        }
    }
}
```

Notes for the executing session:
- Verify exact container names by inspecting the compose project name
  (`docker compose -p vaultkeeper_prod ps` etc.) — the names above
  follow the `<project>_<service>` convention but compose v2 uses
  hyphens by default. Adjust to whatever `docker network inspect edge`
  shows once services are joined.
- Immich's internal HTTP port is `2283` for `immich-server` at time
  of writing; verify against the operator's actual compose. Same for
  Nextcloud (the Linuxserver and official images differ).
- `php_fastcgi` for horizon: confirm the project's fastcgi root path
  in the api container.

### `.env.example`

```
INWX_USER=
INWX_PASSWORD=
ACME_EMAIL=
```

### `.gitignore`

```
.env
caddy_data/
caddy_config/
```

### `README.md`

Cover: prerequisite (`docker network create edge`), `cp .env.example .env`,
fill in INWX credentials, `docker compose up -d --build`. Document how
to add a new service (one new site block in Caddyfile, restart caddy).
Do not document anything specific to Vaultkeeper here — that's a
separate project.

## Work — `vaultkeeper` repo (existing)

New branch off `main`. Suggested name: `claude/caddy-edge-migration`.
Do **not** stack this on `claude/fix-db-redirect-issue-MITz6`.

### 1. Remove the nginx service from `docker-compose.prod.yml`

Currently lines ~315-355. Delete:
- The whole `nginx:` service block
- The `${HTTP_PORT}:80` and `${HTTPS_PORT}:443` host port mappings
  (these are inside the nginx block; gone with it)

### 2. Add a `spa` sidecar to `docker-compose.prod.yml`

Replaces the static-file-serving job nginx used to do. Multi-stage build
that produces a static-only `nginx:alpine` image with the prebuilt SPA
in `/usr/share/nginx/html`.

```yaml
spa:
  image: ${SPA_IMAGE:-vaultkeeper-spa:local}
  build:
    context: .
    dockerfile: docker/spa/Dockerfile.prod
    args:
      VITE_API_BASE_URL: /api
      VITE_ASSETS_URL: ${ASSETS_PUBLIC_URL}
  restart: unless-stopped
  networks:
    - edge
    - internal
  # No host ports. Caddy reaches it as <project>_spa:80 over the edge network.
```

Create `docker/spa/Dockerfile.prod`:

```dockerfile
ARG NODE_VERSION=22
ARG NGINX_VERSION=1.27-alpine

FROM node:${NODE_VERSION}-alpine AS build
WORKDIR /src
COPY web/package.json web/package-lock.json ./
RUN npm ci
COPY web/ ./
ARG VITE_API_BASE_URL
ARG VITE_ASSETS_URL
ENV VITE_API_BASE_URL=${VITE_API_BASE_URL}
ENV VITE_ASSETS_URL=${VITE_ASSETS_URL}
RUN npm run build

FROM nginx:${NGINX_VERSION}
COPY --from=build /src/dist /usr/share/nginx/html
COPY docker/spa/spa.conf /etc/nginx/conf.d/default.conf
```

Create `docker/spa/spa.conf`:

```nginx
server {
    listen 80 default_server;
    server_name _;
    root /usr/share/nginx/html;
    index index.html;

    # Fingerprinted Vite assets — cache forever
    location /assets/ {
        access_log off;
        expires 1y;
        add_header Cache-Control "public, immutable";
        try_files $uri =404;
    }

    # SPA fallback
    location / {
        try_files $uri $uri/ /index.html;
    }

    location = /index.html {
        add_header Cache-Control "no-store, no-cache, must-revalidate" always;
        expires 0;
    }
}
```

### 3. Connect `api`, `adminer`, `minio`, `spa` to the external `edge` network

At the top of `docker-compose.prod.yml`, declare both networks:

```yaml
networks:
  internal:
    driver: bridge
  edge:
    external: true
    name: edge
```

Then on each of `api`, `adminer`, `minio`, `spa`: add `networks: [edge, internal]`.
Other services (`mariadb`, `redis`, `worker`, `scheduler`, `minio-init`)
stay on `internal` only.

### 4. Drop the api healthcheck dependency on local nginx

The current healthcheck is `curl http://localhost/up` which routed
through the local nginx. With nginx gone, replace with a TCP check on
php-fpm directly. In `docker-compose.prod.yml` on the `api` service,
change `healthcheck` to:

```yaml
healthcheck:
  test: ["CMD", "sh", "-c", "nc -z localhost 9000"]
  interval: 30s
  timeout: 5s
  retries: 3
  start_period: 30s
```

(`nc` ships with the api image's base — verify; if not present, swap
for `php-fpm-healthcheck` script or `cgi-fcgi -bind -connect localhost:9000`.)

### 5. Update `api/config/filesystems.php`

Line 86 (`'url' => env('APP_URL').'/storage'`) — change the local
fallback to also use `ASSETS_PUBLIC_URL` so dev and prod build URLs
the same way:

```php
'url' => env('ASSETS_PUBLIC_URL', env('APP_URL').'/storage'),
```

The `s3` branch already uses `ASSETS_PUBLIC_URL` (line 77). No change there.

### 6. Update SPA components to use `VITE_ASSETS_URL`

Three Vue files reference `/storage/` directly:

- `web/src/components/ManaSymbol.vue:17` — `:src="\`/storage/symbols/${clean}.svg\`"`
- `web/src/components/SetSymbol.vue:23` — `\`/storage/sets/${setUpper.value}/${rarityLetter.value}.svg\``
- `web/src/components/CardDetailBody.vue:91` — `'/storage/card-back.jpg'`

Add a small helper `web/src/lib/assets.js`:

```js
const base = (import.meta.env.VITE_ASSETS_URL || '/storage').replace(/\/$/, '')
export const assetUrl = (path) => `${base}${path.startsWith('/') ? path : '/' + path}`
```

Then replace each call site:
- `assetUrl(\`/symbols/${clean}.svg\`)`
- `assetUrl(\`/sets/${setUpper.value}/${rarityLetter.value}.svg\`)`
- `assetUrl('/card-back.jpg')`

Local dev still works because `VITE_ASSETS_URL` falls back to `/storage`,
which Vite's dev server proxies (or which Laravel serves directly in
local mode).

### 7. Env file changes

In `.env.prod.example` and `.env.staging.example`:

- Set `APP_URL=https://vault.kontrollzentrale.de` (prod) /
  `https://vault-staging.kontrollzentrale.de` (staging).
- Add `SESSION_DOMAIN=.vault.kontrollzentrale.de` (prod) /
  `.vault-staging.kontrollzentrale.de` (staging).
- Add `SESSION_SECURE_COOKIE=true`.
- Set `ASSETS_PUBLIC_URL=https://assets.vault.kontrollzentrale.de`
  (prod) / `https://assets.vault-staging.kontrollzentrale.de` (staging).
- Remove `HTTP_PORT` and `HTTPS_PORT` — irrelevant once nginx is gone.
- `MINIO_CONSOLE_PORT` can also go (we won't expose it).

The `MINIO_CONSOLE_PORT: 9001` mapping at
`docker-compose.prod.yml:82` should also be removed — operator can
hit MinIO console via Caddy if/when needed (deferred; not in this plan).

### 8. Drop nginx-related files

Delete the entire `docker/nginx/` directory:
- `docker/nginx/default.conf`
- `docker/nginx/default.prod.conf.template`
- `docker/nginx/Dockerfile.prod`
- `docker/nginx/security-headers.conf`
- `docker/nginx/security-headers-db.conf`
- `docker/nginx/security-headers-horizon.conf`
- `docker/nginx/ratelimit.conf`

Also remove the `NGINX_IMAGE` reference from compose if any remains
post-step-1.

### 9. Routes / controllers — verify, do not delete

The `/__db_auth` route (`api/routes/web.php:33` →
`OpsDbProxyController::check`) **must remain** — Caddy's forward_auth
calls it. The controller already returns 204/401 cleanly.

The `OpsDbProxyController` may still contain X-Accel-Redirect /
proxy logic from a prior iteration — if so, only the `check()` method
matters going forward; the rest can be removed in a follow-up cleanup
PR but is harmless if left.

Horizon middleware (`RequireHorizonAuth`) is unchanged.

### 10. Local dev (`docker-compose.yml`, not `prod.yml`)

Out of scope for this migration. Local dev still uses the existing
nginx container at `:8080`. Only the prod compose changes.

## Work — Immich, Nextcloud, Portainer, Cockpit

These services live in compose files outside both repos in scope. The
executing session should hand the operator small diffs to apply
manually. Pattern for each: **drop host port mappings, join the `edge`
network**.

### Immich

In Immich's `docker-compose.yml`, on `immich-server`:

```yaml
immich-server:
  # ... existing config ...
  ports:           # REMOVE THIS BLOCK
    - "11443:2283"
  networks:
    - default
    - edge          # ADD

networks:
  default:
  edge:
    external: true
    name: edge
```

Caddy proxies to `immich_server:2283` (verify exact container name;
Linuxserver's image vs official differ).

### Nextcloud

Same pattern on the Nextcloud `app` service (the one running
`apache`/`fpm`). Drop `10443:443` (or whatever exists). Add
`edge` network. Caddy proxies to `nextcloud_app:80`.

Important Nextcloud-specific gotcha: edit Nextcloud's
`config/config.php` (or env) to add `kontrollzentrale.de` to
`trusted_domains` and set `overwrite.cli.url` and `overwriteprotocol`
to match the public URL. Without this, Nextcloud will redirect-loop
or refuse the request.

```php
'trusted_domains' => ['cloud.kontrollzentrale.de'],
'overwrite.cli.url' => 'https://cloud.kontrollzentrale.de',
'overwriteprotocol' => 'https',
'overwritehost' => 'cloud.kontrollzentrale.de',
```

### Portainer

Drop `9443:9443`, add `edge` network on the `portainer` service. Caddy
proxies to `portainer:9000` (Portainer's plain HTTP listener — not
9443, which is its self-signed HTTPS).

If Portainer is currently configured to require HTTPS, switch to its
HTTP-only mode (env `--http-enabled` or remove the `--ssl` flags). Caddy
handles TLS.

### Cockpit

Cockpit runs as a systemd service on the host, not in Docker. No
compose changes for it. Caddy reaches it via
`https://host.docker.internal:9090` (the `extra_hosts: host-gateway`
on the Caddy service makes that alias resolve on Linux).

Edit Cockpit's `/etc/cockpit/cockpit.conf` to allow the Caddy origin:

```ini
[WebService]
Origins = https://cockpit.kontrollzentrale.de wss://cockpit.kontrollzentrale.de
ProtocolHeader = X-Forwarded-Proto
AllowUnencrypted = false
```

Restart cockpit: `sudo systemctl restart cockpit`.

### Order of operations per service

For each service: **bring it down**, edit its compose, **bring it up**
attached to `edge`. Caddy will start serving its subdomain as soon as
the upstream container name resolves on the `edge` network.

## Cutover sequence on the host

Order matters because Caddy needs port `:443` free before it can bind,
and the existing Vaultkeeper-prod nginx currently holds it.

1. **Pre-flight on the host:**
   ```sh
   docker network create edge
   ```

2. **Build the new SPA + drop nginx in Vaultkeeper:**
   - Operator pulls the `claude/caddy-edge-migration` branch on the host.
   - Updates `.env.prod` and `.env.staging` with the new vars from the
     plan (APP_URL, SESSION_DOMAIN, ASSETS_PUBLIC_URL, remove HTTP_PORT/
     HTTPS_PORT/MINIO_CONSOLE_PORT).
   - Does **not** restart Vaultkeeper yet.

3. **Build the Caddy image (do not start yet):**
   - In `kontrollzentrale/`: `cp .env.example .env`, fill in INWX
     credentials and ACME email.
   - `docker compose build` (does not start).

4. **Bring Vaultkeeper down, then up on the new compose:**
   ```sh
   docker compose -f docker-compose.prod.yml -p vaultkeeper_prod down
   docker compose -f docker-compose.prod.yml -p vaultkeeper_prod \
       --env-file .env.prod up -d --build
   docker compose -f docker-compose.prod.yml -p vaultkeeper_staging down
   docker compose -f docker-compose.prod.yml -p vaultkeeper_staging \
       --env-file .env.staging up -d --build
   ```

   Vaultkeeper is now running without an edge listener. The site is
   unreachable. This is fine for ~minutes.

5. **Bring up the other services on `edge`:**
   - Apply Immich, Nextcloud, Portainer compose edits per their section.
   - `docker compose down` then `up -d` each project.
   - Restart Cockpit after editing `cockpit.conf`.

6. **Bring up Caddy:**
   ```sh
   cd kontrollzentrale
   docker compose up -d
   docker compose logs -f caddy
   ```

   Watch the log for ACME issuance against INWX. First-time issuance
   takes 30-90 seconds per cert (× 3 wildcards). Retries are automatic
   if INWX API is briefly slow. Look for lines like
   `certificate obtained successfully`.

7. **DNS sanity check from the operator's laptop on the tailnet:**
   ```sh
   dig +short vault.kontrollzentrale.de       # 100.113.247.87
   dig +short adminer.vault.kontrollzentrale.de  # 100.113.247.87
   curl -I https://vault.kontrollzentrale.de  # 200 OK, valid cert
   ```

   If the cert fails, check Caddy logs for INWX API errors before
   anything else — the credentials are the most common failure.

8. **Functional test of every service** (see verification checklist below).

If anything fails for >30 minutes and the operator is blocked: **roll
back** by reverting the Vaultkeeper branch, bringing down Caddy, and
bringing up the previous Vaultkeeper compose with its bundled nginx
(see Rollback section).

### Why no "phase 1 first"

The operator explicitly chose Plan B (all-at-once) over a safer phased
rollout. Service is private, downtime is acceptable, no users besides
the operator. Don't second-guess this; the rollback path is clean.

## Test plan / verification checklist

Run these from a device on the tailnet with Tailscale connected. All
should succeed; flag any that don't.

### Certs

- [ ] `curl -vI https://vault.kontrollzentrale.de 2>&1 | grep -i 'subject\|issuer'`
      — issuer is Let's Encrypt, subject CN matches.
- [ ] Same for `*.vault.kontrollzentrale.de`, `*.vault-staging.*`,
      `immich.*`, `cloud.*`, `portainer.*`, `cockpit.*`.
- [ ] `caddy_data` volume contains the issued certs (operator can
      `docker compose exec caddy ls /data/caddy/certificates`).

### Vaultkeeper prod (`vault.kontrollzentrale.de`)

- [ ] Loads SPA at `/`. Browser shows valid cert.
- [ ] Login flow works. Session cookie domain shows
      `.vault.kontrollzentrale.de` (DevTools → Application → Cookies).
- [ ] `GET /api/up` returns 200.
- [ ] An MTG card detail page loads images successfully — confirms
      `assets.vault.kontrollzentrale.de` is reachable and the SPA's
      `assetUrl()` is wired up.
- [ ] No CSP violations in the browser console.

### Vaultkeeper staging (`vault-staging.kontrollzentrale.de`)

- [ ] Same checks as prod.
- [ ] Logging into staging Horizon does NOT log you into prod
      Horizon. Cookie domains must be different
      (`.vault-staging.kontrollzentrale.de` vs `.vault.kontrollzentrale.de`).

### Adminer auth flow (prod)

- [ ] Hit `https://adminer.vault.kontrollzentrale.de` from a fresh
      private window. Should 302 to
      `https://horizon.vault.kontrollzentrale.de/horizon-login?next=...`.
- [ ] Log in with the ops password on horizon-login.
- [ ] Get redirected back to adminer (the `next` param in the URL).
- [ ] Adminer's MariaDB login form renders.
- [ ] Log in with MariaDB creds — Adminer dashboard appears.
- [ ] In another tab, hit `https://horizon.vault.kontrollzentrale.de/horizon` — already authenticated (same session).
- [ ] Repeat from staging. Confirm staging's auth does not let you
      into prod Adminer.

### Horizon dashboard

- [ ] `horizon.vault.kontrollzentrale.de/horizon` loads after auth.
      Vue UI renders, no inline-script CSP errors. (If CSP errors
      appear, the inherited Caddy headers may be too strict — adjust
      the horizon site block.)

### Assets (`assets.vault.kontrollzentrale.de`)

- [ ] `curl -I https://assets.vault.kontrollzentrale.de/symbols/W.svg`
      returns 200.
- [ ] Cache-Control header on responses says `immutable, max-age=...`.
- [ ] Set symbols and mana symbols render in the SPA.

### Immich / Nextcloud / Portainer / Cockpit

- [ ] Each loads at its respective subdomain.
- [ ] Login works on each.
- [ ] Nextcloud doesn't redirect-loop (check `trusted_domains`,
      `overwriteprotocol`).
- [ ] Cockpit's terminal works (websocket upgrade through Caddy is
      the gotcha; if broken, Caddy's `reverse_proxy` already handles
      WS by default but verify `Origins` line in `cockpit.conf`).

### Negative tests

- [ ] `curl http://vault.kontrollzentrale.de` redirects (308) to
      `https://`. Caddy does this by default.
- [ ] No service is reachable on its old port from the host:
      `nc -zv 127.0.0.1 8443` should fail. Same for 11443, 10443, 9443.
      (Cockpit on 9090 stays — it's host systemd, not docker. Just
      not exposed via Caddy without the subdomain.)
- [ ] Hit `https://vault.kontrollzentrale.de` from a device NOT on the
      tailnet — should fail to connect (CGNAT IP unroutable).

## Rollback

The Vaultkeeper changes are on a branch — reverting on the host is
just a `git checkout main` and rebuilding. No data migration involved
in this whole plan. Volumes (mariadb, redis, minio, caddy_data) are
preserved across rollback.

### To roll back fully

1. Bring Caddy down:
   ```sh
   cd kontrollzentrale && docker compose down
   ```
2. Restore Vaultkeeper to pre-migration:
   ```sh
   cd /path/to/vaultkeeper
   git checkout main   # or whichever commit was prod before migration
   docker compose -f docker-compose.prod.yml -p vaultkeeper_prod down
   docker compose -f docker-compose.prod.yml -p vaultkeeper_prod \
       --env-file .env.prod up -d --build
   # repeat for staging
   ```
   The pre-migration `.env.prod` had `HTTP_PORT=80`, `HTTPS_PORT=443`,
   etc. — make sure the `.env.prod` on disk matches what the old
   compose expects (operator may need to keep a backup of the old env
   file before step 2 of the cutover).
3. For Immich/Nextcloud/Portainer: restore their compose files,
   `down` then `up -d` each.
4. Caddy state lives in the `caddy_data` Docker volume — leave it. If
   re-attempting the migration later, the volume gets reused and
   certs don't have to be re-issued.

### What is NOT a rollback hazard

- DNS records at INWX. `100.113.247.87` works for both
  pre-migration (port-based) and post-migration (Caddy) — leave
  records in place.
- INWX API subaccount. Leave it.
- Tailscale DNS settings (Override local DNS, 1.1.1.1). Leave them.
- The `edge` Docker network. Leave it; harmless when nothing uses it.

### Partial-failure recipes

- **Caddy fails to issue a cert.** Site is down for that hostname
  only. Other sites unaffected. Check Caddy logs for INWX errors;
  most common: typo in `INWX_USER` / `INWX_PASSWORD`. Subaccount needs
  DNS-edit permission on the zone — verify in INWX customer center.
- **Forward-auth redirect loops on Adminer.** Means the
  cross-subdomain session cookie isn't sticking. Verify
  `SESSION_DOMAIN=.vault.kontrollzentrale.de` (with the leading dot)
  is in `.env.prod` and that the api container picked it up
  (`docker compose exec api php artisan config:show session.domain`).
- **SPA loads but card images broken.** SPA build picked up the wrong
  `VITE_ASSETS_URL`. Verify the `args:` block on the `spa` service in
  compose passed it through, and that `import.meta.env.VITE_ASSETS_URL`
  resolves at build time (not runtime — Vite bakes it in).
- **Nextcloud redirect loop.** Almost always `trusted_domains` /
  `overwriteprotocol` not set. Edit `config/config.php`, restart.

### Post-migration follow-ups (not blocking)

- Delete `OpsDbProxyController` methods other than `check()` if any
  X-Accel-Redirect logic remains.
- Consider expanding the SPA build to inject a runtime config so
  staging vs prod doesn't require separate image builds.
- Document the "add a new service" flow in
  `kontrollzentrale/README.md` once a second new service is added (so
  the runbook reflects real practice, not theoretical steps).
- Possibly add `caddy-rate-limit` plugin later if any service ever
  goes public via Funnel.
