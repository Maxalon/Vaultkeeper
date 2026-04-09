# Vaultkeeper

Self-hosted MTG (Magic: The Gathering) collection management tool.

## Layout

```
api/    Laravel 11 PHP backend (JWT auth, MySQL)
web/    Vue 3 + Vite frontend (Pinia, Vue Router)
app/    Kotlin/Compose Android client (placeholder)
docker/ Nginx + PHP-FPM Dockerfiles
```

## Dev environment

Requires Docker + Docker Compose.

```bash
cp .env.example .env
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

To manually trigger the daily new-sets check:

    docker compose exec api php artisan sets:sync-new
