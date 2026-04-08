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

# Create a user (interactive prompts)
docker compose exec api php artisan user:create
```

Open http://localhost:8080 and log in.

## Routes

| Method | Path                 | Notes                          |
| ------ | -------------------- | ------------------------------ |
| POST   | `/api/auth/login`    | Public, throttled 10/min/IP    |
| POST   | `/api/auth/logout`   | JWT required                   |
| POST   | `/api/auth/refresh`  | JWT required                   |
| GET    | `/api/auth/me`       | JWT required                   |
| `*`    | `/api/{cards,collection,locations,decks}` | Resource controllers (stubs) |
| POST   | `/api/import`        | Stub                           |
