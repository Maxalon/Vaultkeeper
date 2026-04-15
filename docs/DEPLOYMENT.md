# Deploying Vaultkeeper

This document describes how to run Vaultkeeper in production or staging
on a self-hosted server. It assumes the box is reachable only via
Tailscale and that you want a prod-grade posture regardless.

> The dev stack is documented in the top-level `README.md`. This file is
> for operators standing up prod or staging on a real host — not for
> local development.

## Topology

```
                    ┌──────────────────────────────────────────┐
                    │              Tailscale tailnet            │
                    │                                           │
   operator ──┐     │                                           │
              └──┐  │    ┌───────────── prod host ───────────┐ │
                 └──┴────┤ nginx :80/:443  (TLS via TS cert)  │ │
                         │   ├─ /         → SPA (baked)       │ │
                         │   ├─ /api      → php-fpm api:9000  │ │
                         │   ├─ /horizon  → php-fpm api:9000  │ │
                         │   └─ /storage  → minio:9000        │ │
                         │                                      │ │
                         │   api (php-fpm)         worker      │ │
                         │        │                   │        │ │
                         │        └──────┬────────────┘        │ │
                         │               │                      │ │
                         │       db  redis  minio               │ │
                         └──────────────────────────────────────┘ │
                                                                   │
                                      staging (same host, different│
                                      compose project, ports, env) │
                                                                   │
                                                                   ┘
```

Prod and staging both run from the same `docker-compose.prod.yml` but
with two different compose project names (`-p vaultkeeper_prod` and
`-p vaultkeeper_staging`) and two different env files. Containers,
volumes, and networks are isolated by project — neither stack can see
the other.

## Prerequisites on the host

- Linux with Docker Engine 24+ and the compose plugin.
- Tailscale installed and authenticated; the host has a `<name>.<tail>.
  ts.net` hostname.
- The operator has a GitHub account with pull access to the package
  registry where CI pushes images.
- `sudo` access for cert provisioning and (optionally) systemd timers.

## First-time prod deploy

### 1. Clone the repo and prepare the env file

```bash
sudo mkdir -p /srv/vaultkeeper_prod
sudo chown "$USER":"$USER" /srv/vaultkeeper_prod
git clone <repo-url> /srv/vaultkeeper_prod
cd /srv/vaultkeeper_prod

cp .env.prod.example .env.prod
${EDITOR:-vi} .env.prod
```

Replace every `CHANGEME`. For the two things you cannot invent yourself:

```bash
# APP_KEY
docker run --rm ghcr.io/<owner>/vaultkeeper-api:main \
    php artisan key:generate --show

# JWT_SECRET
docker run --rm ghcr.io/<owner>/vaultkeeper-api:main \
    php artisan jwt:secret --show
```

Paste each value into `.env.prod`. Both must be **different** from dev
and from staging.

### 2. Authenticate to the container registry

```bash
# If your registry is GHCR:
echo $GHCR_PAT | docker login ghcr.io -u <owner> --password-stdin
```

`$GHCR_PAT` is a GitHub Personal Access Token with `read:packages`
scope. This is stored in `~/.docker/config.json` after first login, so
you only do this once per host.

### 3. Provision the TLS cert

```bash
sudo mkdir -p /etc/vaultkeeper/certs
sudo tailscale cert \
    --cert-file /etc/vaultkeeper/certs/cert.pem \
    --key-file  /etc/vaultkeeper/certs/key.pem \
    <host>.<tailnet>.ts.net
sudo chmod 644 /etc/vaultkeeper/certs/cert.pem
sudo chmod 640 /etc/vaultkeeper/certs/key.pem
```

Tailscale certs expire roughly every 90 days. Schedule a systemd
timer or cron entry to renew + reload nginx:

```
# /etc/systemd/system/vaultkeeper-cert-renew.service
[Unit]
Description=Renew Tailscale cert for Vaultkeeper

[Service]
Type=oneshot
ExecStart=/usr/bin/tailscale cert --cert-file /etc/vaultkeeper/certs/cert.pem --key-file /etc/vaultkeeper/certs/key.pem <host>.<tailnet>.ts.net
ExecStartPost=/usr/bin/docker compose -p vaultkeeper_prod -f /srv/vaultkeeper_prod/docker-compose.prod.yml exec nginx nginx -s reload
```

Paired with a `.timer` unit firing every 30 days.

### 4. Add the cert volume override

The base `docker-compose.prod.yml` does NOT mount host certs. Drop a
`docker-compose.override.yml` next to it so prod picks them up:

```yaml
# /srv/vaultkeeper_prod/docker-compose.override.yml
services:
  nginx:
    volumes:
      - /etc/vaultkeeper/certs:/etc/nginx/certs:ro
```

Docker compose merges override files automatically. Staging uses the
same trick with a staging-specific override path.

### 5. Bring up the stack

```bash
docker compose \
    -f docker-compose.prod.yml \
    --env-file .env.prod \
    -p vaultkeeper_prod \
    up -d
```

Watch for all six services to come healthy:

```bash
docker compose -p vaultkeeper_prod ps
```

Expected state: `db`, `redis`, `minio`, `nginx` all `healthy`; `api` and
`worker` `Up`; `minio-init` already `Exited (0)`.

### 6. First-time database setup

```bash
docker compose -p vaultkeeper_prod exec api php artisan migrate --force
docker compose -p vaultkeeper_prod exec api php artisan user:create
```

### 7. First-time asset sync

Set symbols and mana symbols are pulled from Scryfall / Hexproof into
the MinIO bucket. This is a one-time-ish operation:

```bash
docker compose -p vaultkeeper_prod exec api php artisan sets:sync
```

Takes a few minutes. Nightly updates are driven by the Laravel scheduler
(see `Scheduled tasks` below).

### 8. Smoke test

From any machine on your tailnet:

```bash
curl -v https://<host>.<tailnet>.ts.net/
curl -v https://<host>.<tailnet>.ts.net/up
curl -v https://<host>.<tailnet>.ts.net/api/collection  # expect 401
```

## Staging deploy

Identical to prod except:

- Use `/srv/vaultkeeper_staging` as the working dir.
- Copy `.env.staging.example` → `.env.staging`.
- Pass `-p vaultkeeper_staging --env-file .env.staging`.
- Provision a second TLS cert for a staging hostname
  (`staging-<host>.<tailnet>.ts.net`) into a staging-specific cert dir.
- Pull images from the `staging` tag instead of `main`.

Staging and prod can run simultaneously on the same host; the compose
project namespace keeps all their containers, volumes, and networks
separate.

## Ongoing operations

### Upgrading

CI pushes a new image whenever a commit lands on `main` (prod) or
`staging` (staging). On the host:

```bash
cd /srv/vaultkeeper_prod
docker compose -p vaultkeeper_prod --env-file .env.prod pull
docker compose -p vaultkeeper_prod --env-file .env.prod up -d
docker compose -p vaultkeeper_prod exec api php artisan migrate --force
```

`up -d` only recreates containers whose image digest changed. No
downtime for unchanged services.

### Scheduled tasks

Laravel's scheduler needs to fire every minute. One way: a host cron
entry.

```
* * * * * docker compose -p vaultkeeper_prod -f /srv/vaultkeeper_prod/docker-compose.prod.yml exec -T api php artisan schedule:run >/dev/null 2>&1
```

The scheduler runs `sets:sync-new` nightly so newly-released MTG sets
get their symbols downloaded without manual intervention.

### Horizon dashboard

Reachable at `https://<host>.<tailnet>.ts.net/horizon`. Access is gated
by `HORIZON_ALLOWED_EMAILS` in `.env.prod` — only users whose
authenticated email is in that list can view it.

### MinIO console

Reachable at `http://<host>.<tailnet>.ts.net:9001` (or whatever port
`MINIO_CONSOLE_PORT` is set to). Log in with `MINIO_ROOT_USER` /
`MINIO_ROOT_PASSWORD` from `.env.prod`. Useful for:

- Inspecting the `vaultkeeper-assets` bucket contents
- Verifying the bucket policy is `public` download after minio-init ran
- Manually uploading a hotfix asset

### Backups

**MySQL**: run `mysqldump` out of the db container on a timer.

```bash
docker compose -p vaultkeeper_prod exec -T db \
    mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction \
    --routines --triggers $DB_DATABASE \
    | gzip > /var/backups/vaultkeeper/$(date -u +%Y%m%d-%H%M%S).sql.gz
```

**MinIO**: the `minio_data` named volume holds the bucket. Either
`docker run --rm -v vaultkeeper_prod_minio_data:/data -v /var/backups:/backup alpine tar czf /backup/minio-$(date -u +%Y%m%d).tar.gz /data`
on a timer, or use `mc mirror` to replicate to an off-box S3 target.

**Off-box**: neither of the above leaves the host by default. Add a
restic / rclone step to push the tarballs to a second Tailscale node or
to real cloud storage.

### Log inspection

Every container logs to stdout/stderr. Docker's json-file driver keeps
them around by default; tail a specific service with:

```bash
docker compose -p vaultkeeper_prod logs -f --tail 200 nginx
docker compose -p vaultkeeper_prod logs -f --tail 200 api
docker compose -p vaultkeeper_prod logs -f --tail 200 worker
```

Consider adding a `logrotate`-style config to Docker's daemon.json to
cap log size per container.

## Troubleshooting

**Nginx keeps restarting with "certificate" errors**
: The cert files aren't where nginx expects them. Check the override
  file mounts `/etc/vaultkeeper/certs` onto `/etc/nginx/certs:ro`, and
  that both `cert.pem` and `key.pem` exist in that host dir. nginx
  falls back to a self-signed placeholder baked into the image if the
  mount is missing — but browsers will throw a security warning.

**`/horizon` returns 403**
: The authenticated user's email isn't in `HORIZON_ALLOWED_EMAILS`.
  Update the env file, rerun `up -d` (to restart the api container and
  pick up the new env), retry.

**MinIO-init says "bucket already exists"**
: Not an error. The init script uses `mb --ignore-existing`; that
  message is informational.

**"No such file or directory: /var/www/html/bootstrap/cache/..." on
first boot**
: The image ships with that directory pre-created, so this only
  happens if a host bind mount is overlaying `/var/www/html`. The prod
  compose file does NOT mount host source — confirm you're running
  from `docker-compose.prod.yml` and not the dev compose file.

**All six containers say healthy but the SPA loads a blank page**
: Check the browser console. If the HTTP response is 200 but JS fails
  to load, it's usually CSP — the dev console will show the rejected
  script/style source. Update `default.prod.conf`'s Content-Security-
  Policy to whitelist it, rebuild the nginx image, redeploy.
