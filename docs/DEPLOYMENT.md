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

## Branch model and GitHub setup

Two long-lived branches drive two deploy targets:

- **`main`** — whatever is here is what runs in prod. Gated by PR + CI.
- **`staging`** — dry-run branch that runs on the staging stack on the
  same host. Looser gate: CI must pass, but you can push directly.

The typical flow for a solo project:

1. Work on short-lived feature branches off `main`.
2. Open a PR into `main`; CI has to be green to merge.
3. Merge to `main` — prod deploy workflow triggers (with a manual
   approval gate; see below).
4. When you want to dry-run something against real infrastructure
   *before* it reaches prod, push it to `staging` instead. The staging
   deploy workflow auto-deploys on every green push.
5. Periodically fast-forward `staging` to `main` so staging doesn't
   drift too far from what's about to ship.

### Creating the `staging` branch

Nothing special — it's an ordinary git branch:

```bash
git checkout main
git pull
git branch staging
git push -u origin staging
```

CI (`.github/workflows/ci.yml`) already triggers on pushes to both
`main` and `staging`, so the suite runs the moment you push.

### Repository settings checklist

Do these **once**, from the GitHub web UI, before the server hardware
is live. Everything here is either free or one click.

**1. Workflow permissions (required for CI to push images later)**

- `Settings → Actions → General → Workflow permissions`
- Select **"Read and write permissions"**

Without this the deploy workflows (round 4) won't be able to push to
ghcr.io — the job step will 403 with no useful error. Set it now and
forget it.

**2. GitHub Actions environments**

- `Settings → Environments → New environment`
- Create **`staging`** — no protection rules, no reviewers.
- Create **`prod`** — tick **"Required reviewers"** and add yourself.
  Every prod deploy workflow run will then pause with a "Review
  deployment" button in the Actions UI, which you click to promote.

Environments are also where the deploy secrets live. Add these secrets
to **both** `staging` and `prod` environments (values can differ per
environment, or be the same if you're deploying to a single server):

| Secret              | Value                                                     |
|---------------------|-----------------------------------------------------------|
| `TS_OAUTH_CLIENT_ID`| Tailscale OAuth client ID (see step 6 below)             |
| `TS_OAUTH_SECRET`   | Tailscale OAuth client secret                             |
| `DEPLOY_SSH_KEY`    | SSH **private** key for the deploy user (see step 7)      |
| `DEPLOY_HOST`       | Tailscale hostname of the server (e.g. `vault.tail1234.ts.net`) |
| `DEPLOY_USER`       | SSH username on the server (e.g. `deploy`)                |

Staging creds never leak into prod runs and vice versa.

**3. Branch protection on `main`**

- `Settings → Branches → Add branch ruleset` → target `main`
- Enable:
  - ✅ Require pull request before merging
  - ✅ Require status checks to pass — select the `api / phpunit` and
    `web / vite build` checks from `ci.yml`
  - ✅ Require linear history (no merge commits — keeps the log clean)
  - ✅ Block force pushes
  - ✅ Block deletions

**4. Branch protection on `staging`** (lighter)

- Same UI, target `staging`
- Enable:
  - ✅ Require status checks to pass (same two checks)
  - ✅ Block force pushes
  - ✅ Block deletions

No PR requirement on staging — you want to be able to push experimental
commits straight up so the stack rebuilds on the server without
bureaucracy.

**5. Stub workflows you don't need**

`.github/workflows/android.yml` is a no-op stub left over from the
initial scaffold. It checks for `app/gradlew` and skips if missing,
so it isn't actually doing anything — but if you're sure you don't
want an Android build in this repo, delete the file so the Actions UI
isn't cluttered with skipped runs.

**6. Tailscale OAuth client (required for deploy workflow)**

The deploy workflow connects the GitHub Actions runner to your tailnet
so it can SSH to the server. This uses a Tailscale OAuth client, not
an auth key (OAuth clients don't expire).

1. Go to the [Tailscale admin console](https://login.tailscale.com/admin/settings/oauth)
   → `Settings → OAuth clients → Generate OAuth client`.
2. Give it a description like `github-actions-deploy`.
3. Under **Device write** scope, assign the tag `tag:ci`.
4. Copy the client ID and secret — you'll paste them into the GitHub
   environment secrets above.

You also need to allow the `tag:ci` tag in your Tailscale ACLs. Go to
`Access controls` in the admin console and add:

```jsonc
{
  "tagOwners": {
    "tag:ci": ["autogroup:admin"]
  },
  "acls": [
    // ... your existing rules ...
    // Allow CI runners to reach the deploy server over SSH
    {
      "action": "accept",
      "src":    ["tag:ci"],
      "dst":    ["tag:server:22"]
    }
  ]
}
```

Replace `tag:server` with whatever tag your deploy server uses (or use
the machine's Tailscale IP directly in the `dst` if you don't use
tags on the server). The key point is that `tag:ci` must be allowed to
reach port 22 on the deploy host.

**7. SSH deploy key**

Generate a dedicated key pair for deployments. On your local machine:

```bash
ssh-keygen -t ed25519 -C "vaultkeeper-deploy" -f ~/.ssh/vaultkeeper_deploy -N ""
```

Then add the **public** key to the server:

```bash
# On the deploy server (over your existing SSH session):
sudo useradd -m -s /bin/bash deploy
sudo mkdir -p /home/deploy/.ssh
sudo cp ~/.ssh/authorized_keys /home/deploy/.ssh/  # or paste the pubkey
sudo chown -R deploy:deploy /home/deploy/.ssh
sudo chmod 700 /home/deploy/.ssh
sudo chmod 600 /home/deploy/.ssh/authorized_keys

# The deploy user needs docker access:
sudo usermod -aG docker deploy
```

Paste the contents of `~/.ssh/vaultkeeper_deploy` (the **private** key)
into the `DEPLOY_SSH_KEY` secret in both GitHub environments.

**8. Server directory structure**

On the deploy server, create the directories and clone the repo:

```bash
# As the deploy user:
sudo mkdir -p /srv/vaultkeeper_prod /srv/vaultkeeper_staging
sudo chown deploy:deploy /srv/vaultkeeper_prod /srv/vaultkeeper_staging

# Clone into both (the deploy workflow pulls images, it doesn't git pull —
# but it needs the compose file and env file on disk):
git clone <repo-url> /srv/vaultkeeper_prod
git clone <repo-url> /srv/vaultkeeper_staging
cd /srv/vaultkeeper_staging && git checkout staging

# Copy and fill in env files:
cp /srv/vaultkeeper_prod/.env.prod.example /srv/vaultkeeper_prod/.env.prod
cp /srv/vaultkeeper_staging/.env.staging.example /srv/vaultkeeper_staging/.env.staging
# Edit both and replace every CHANGEME
```

Log in to GHCR so the server can pull images:

```bash
# Create a GitHub PAT with read:packages scope, then:
echo "$GHCR_PAT" | docker login ghcr.io -u <github-username> --password-stdin
```

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

Set symbols are pulled from mtg-vectors (with Scryfall icon fallback) and
mana symbols from Scryfall's symbology endpoint into the MinIO bucket. This
is a one-time-ish operation:

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

### Upgrading (automated)

The deploy workflow (`.github/workflows/deploy.yml`) handles upgrades
automatically:

- **Staging**: auto-deploys on every push to `staging`.
- **Prod**: deploys on push to `main`, but pauses for manual approval
  in the GitHub Actions UI (the "Review deployment" button).

The workflow builds images, pushes them to GHCR, SSHs into the server
via Tailscale, pulls the new images, runs `docker compose up -d`,
executes pending migrations, and gracefully restarts Horizon.

To deploy manually (e.g. if the workflow is down), SSH to the host:

```bash
cd /srv/vaultkeeper_prod
docker compose -f docker-compose.prod.yml --env-file .env.prod -p vaultkeeper_prod pull
docker compose -f docker-compose.prod.yml --env-file .env.prod -p vaultkeeper_prod up -d
docker compose -p vaultkeeper_prod exec -T api php artisan migrate --force
docker compose -p vaultkeeper_prod exec -T api php artisan horizon:terminate
```

`up -d` only recreates containers whose image digest changed. No
downtime for unchanged services.

### Scheduled tasks

Laravel's scheduler needs to fire every minute. One way: a host cron
entry.

```
* * * * * docker compose -p vaultkeeper_prod -f /srv/vaultkeeper_prod/docker-compose.prod.yml exec -T api php artisan schedule:run >/dev/null 2>&1
```

The scheduler runs `sets:sync` nightly so newly-released MTG sets
get their symbols downloaded without manual intervention. The command
is idempotent — existing files are skipped.

### Horizon dashboard

Reachable at `https://<host>.<tailnet>.ts.net/horizon` as soon as the
api and worker containers are up. No publish step, no post-deploy
command — Horizon 5.45+ serves its dashboard assets directly from the
package, and the nginx prod config already proxies `/horizon` to
php-fpm.

Access is gated by `HORIZON_ALLOWED_EMAILS` in `.env.prod` — only
users whose authenticated email appears (comma-separated) in that
variable can load the dashboard. A logged-in user outside the
allowlist gets a 403; an unauthenticated request gets a 401. If you
change the allowlist, restart the api container so Laravel picks up
the new env:

```bash
docker compose -p vaultkeeper_prod --env-file .env.prod up -d api
```

That's a one-second no-downtime recreate because it only touches the
api service.

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
