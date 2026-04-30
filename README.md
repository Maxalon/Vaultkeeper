# Vaultkeeper

Self-hosted MTG (Magic: The Gathering) collection management tool.

> **Deploying to a real server?** See [`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)
> for the prod / staging operator guide. This README covers the dev stack only.

## Layout

```
api/    Laravel 13 PHP backend (JWT auth, MySQL, Horizon)
web/    Vue 3 + Vite frontend (Pinia, Vue Router)
app/    Kotlin/Compose Android client (placeholder)
docker/ Dockerfiles for api (dev + prod) and the prod SPA sidecar,
        plus the dev-only nginx config that fronts api + Vite at :8080
```

## Dev environment

Requires Docker + Docker Compose.

```bash
cp .env.example .env
# Open .env and replace every CHANGEME — at minimum DB_PASSWORD and
# MYSQL_ROOT_PASSWORD. The dev MySQL container binds to 0.0.0.0:3306
# on your host, so defaults would leave your database reachable by
# anything on the same network.

docker compose up -d --build

# Wait for the api container to be up, then:
docker compose exec api php artisan key:generate
docker compose exec api php artisan jwt:secret
docker compose exec api php artisan migrate
docker compose exec api php artisan storage:link

# Create a user (interactive prompts)
docker compose exec api php artisan user:create
```

Open http://localhost:8080 and log in.

The queue worker runs automatically as a Docker service.
To monitor queued jobs:

    docker compose exec api php artisan queue:monitor

To manually retry failed jobs:

    docker compose exec api php artisan queue:retry all

## Routes

| Method | Path                 | Notes                          |
| ------ | -------------------- | ------------------------------ |
| POST   | `/api/auth/login`    | Public, throttled 10/min/IP    |
| POST   | `/api/auth/logout`   | JWT required                   |
| POST   | `/api/auth/refresh`  | JWT required                   |
| GET    | `/api/auth/me`       | JWT required                   |
| `*`    | `/api/{cards,collection,locations,decks}` | Resource controllers (stubs) |
| POST   | `/api/import`        | Stub                           |

## Scheduled Tasks

Vaultkeeper uses Laravel's task scheduler to keep set symbols up to date.
Add this single cron entry to the server running the Docker containers:

    * * * * * docker compose -f /path/to/docker-compose.yml exec -T api php artisan schedule:run >> /dev/null 2>&1

Replace `/path/to/docker-compose.yml` with the actual path to your project.

To manually sync all set symbols (first-time setup or full refresh):

    docker compose exec api php artisan sets:sync
