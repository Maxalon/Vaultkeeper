# Local development on Fedora / rootless Podman

The dev stack is Docker-Compose-shaped but runs on **rootless Podman** on
the maintainer's Fedora workstation. The CLI you call is `docker compose`,
but it talks to a Podman compose provider through the user-level Podman
socket. This page documents the boot dance and the one local-only override
needed to make bind-mounted source files writable from inside containers.

## Per-boot setup

```bash
# One-shot: enable the user Podman socket so `docker compose` can talk to it.
systemctl --user enable --now podman.socket

# Add to ~/.bashrc so future shells pick it up automatically.
export DOCKER_HOST=unix:///run/user/$UID/podman/podman.sock
```

`docker compose` calls without `DOCKER_HOST` set will fail with
`failed to connect to the docker API at unix:///var/run/docker.sock` — that
path is the rootful Docker daemon socket, which doesn't exist on a Podman
box. The export above redirects compose to the user-level Podman socket.

## `docker-compose.override.yml` (gitignored)

Compose loads `docker-compose.override.yml` automatically when it sits next
to `docker-compose.yml`. Ours is **local-only** (gitignored) and contains
two Podman-specific tweaks:

```yaml
services:
  api:
    userns_mode: "keep-id"
  worker:
    userns_mode: "keep-id"
    runtime: runc
  web:
    userns_mode: "keep-id"
```

### `userns_mode: "keep-id"`

Rootless Podman maps host UIDs into a container user-namespace via the
subuid range. Without this, container UID 1000 maps to a *different* host
UID — so a process running as 1000 inside the container can't write to
`./api` or `./web` on the host (both owned by host UID 1000). Symptoms:

- `npm install` fails with `EACCES` on `node_modules/@rollup/...`.
- Laravel can't append to `storage/logs/laravel.log`, so every API request
  returns a 500 with "Failed to open stream: Permission denied".

`keep-id` maps container UID 1000 ↔ host UID 1000 directly, fixing both.

### `runtime: runc` on `worker`

`crun 1.27` unconditionally tries to write the kernel default
`net.ipv4.ping_group_range=0 2147483647` at container start. Inside a
single-UID-mapped userns the high gid `2147483647` isn't mapped, so crun
aborts with:

```
crun: write to `/proc/sys/net/ipv4/ping_group_range`
(are all the IDs mapped in the user namespace?): Invalid argument
```

`api` and `web` hit the same code path but happen to start before the
namespace lock-out; `worker` errors deterministically. Switching the worker
to `runc` (which handles this sysctl gracefully) sidesteps the bug.

## End-to-end testing (`e2e/`)

The Playwright E2E suite requires the dev stack up and the DB seeded:

```bash
docker compose up -d
docker compose exec api php artisan migrate:fresh --seed --force
```

Then from `web/`:

```bash
npm run e2e          # headless
npm run e2e:headed   # browser opens on your display
npm run e2e:ui       # Playwright UI runner
npm run e2e:reset    # reseed DB
```

See `e2e/README.md` for the full rundown.

## Cheat sheet for first-time-after-reboot

```bash
systemctl --user start podman.socket
export DOCKER_HOST=unix:///run/user/$UID/podman/podman.sock
cd /mnt/games/Vaultkeeper
docker compose up -d
docker compose exec api php artisan migrate:fresh --seed --force
# stack ready on http://localhost:8080
```
