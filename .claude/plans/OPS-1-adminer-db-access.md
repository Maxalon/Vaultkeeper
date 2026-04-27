# OPS-1 — Adminer for staging/prod DB access

Self-hosted MySQL web UI gated behind the existing Horizon ops password.
One operator credential covers both `/horizon` and `/db`. Same nginx port
(8080 prod, 8443 staging) as the rest of the app — no new exposed ports.

This plan assumes the Horizon password gate (HorizonAuthController +
RequireHorizonAuth middleware) from the bulk-import branch is already
merged. If it isn't, build that first — this plan reuses the same
session flag (`horizon_authed`) and `authToken()` helper.

---

## Decisions locked in during planning

1. **Adminer over phpMyAdmin.** ~30MB image, single-file PHP app, supports
   MySQL out of the box. phpMyAdmin's extra features (saved queries, user
   admin) aren't needed for read-mostly ops use, and the larger surface
   area is a liability when behind public ports.

2. **Reverse proxy at `/db`, same nginx listener.** No new exposed port,
   no separate cert. nginx adds an internal-only network leg to the
   adminer container.

3. **Two layers of auth.** First Horizon-style ops password gates
   `/db` (RequireHorizonAuth-like middleware). After that, Adminer's own
   form takes the actual MySQL credentials from `.env.<env>`. Anyone
   reaching `/db` from the internet has to know both. The DB creds stay
   server-side environment variables; only typed in the browser at first
   login per environment, remembered via Adminer's cookie thereafter.

4. **Same password as Horizon.** Reuse the `horizon_admins` row and
   session flag. Rationale: both are ops dashboards on the same host,
   one credential per environment is plenty, and adding a second
   independent credential just means more to lose. If we later need to
   split them, the middleware is a one-liner change.

5. **No Adminer-side auth bypass.** Even though the Horizon middleware
   gates the path, we do NOT pre-fill MySQL creds via Adminer's
   `LoginServer` plugin or `ADMINER_DEFAULT_DRIVER`. Forcing the
   operator to type the DB password is one extra speed bump against
   "session-stealer browses to /db" scenarios.

6. **Internal-only adminer container.** No `ports:` mapping. nginx is
   the only thing that talks to it. Removes any "did I forget to
   firewall this off?" footgun.

7. **Staging and prod identical config.** Both gated by the same
   middleware; differ only by which `.env.*` file the operator looks at
   for MySQL creds. `docker-compose.prod.yml` already supports both
   projects (`-p vaultkeeper_staging` / `-p vaultkeeper_prod`).

8. **CSP.** Adminer is a server-rendered PHP app with inline scripts
   for table sorting / column resize. Same approach as Horizon: a
   sister header snippet (`security-headers-db.conf`) included from the
   `/db` location. Restate every header — nginx's add_header inheritance
   rule wipes the server-block headers as soon as a location adds even
   one of its own.

9. **Existing rate limit zone covers /db.** `ratelimit.conf` defines
   `api_general` at 10r/s. Apply it to `/db` too (slow brute-force on
   the inner Adminer login form). 10r/s is plenty for a human clicking
   around.

10. **No DB schema, no Vue changes, no migration.** Pure ops add-on.

---

## Files to change

### `docker-compose.prod.yml`
Add an `adminer` service. Mirror the existing pattern (no host port,
joined to the `vaultkeeper` network, restart policy, modest CPU/memory
limits). Adminer exposes 8080 inside its container.

```yaml
adminer:
  image: adminer:5
  restart: unless-stopped
  environment:
    # No ADMINER_DEFAULT_SERVER — operator types it. See decision #5.
    ADMINER_DESIGN: dracula  # or 'pepa-linha' / 'galkaev', any taste
  depends_on:
    db:
      condition: service_healthy
  networks:
    - vaultkeeper
  deploy:
    resources:
      limits:
        cpus: '0.25'
        memory: 64M
```

Place it next to the `minio` block (also an ops support service).

### `docker/nginx/security-headers-db.conf` (new)

Same pattern as `security-headers-horizon.conf`. Allow inline scripts
and styles since Adminer ships them. Keep all other security headers
identical.

```nginx
add_header Strict-Transport-Security "max-age=15552000; includeSubDomains; preload" always;
add_header X-Frame-Options           "DENY" always;
add_header X-Content-Type-Options    "nosniff" always;
add_header Referrer-Policy           "strict-origin-when-cross-origin" always;
add_header Permissions-Policy        "camera=(), microphone=(), geolocation=(), payment=()" always;
add_header Content-Security-Policy   "default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'" always;
```

### `docker/nginx/Dockerfile.prod`
Add a `COPY` for the new snippet, alongside the existing two.

```dockerfile
COPY docker/nginx/security-headers-db.conf /etc/nginx/snippets/security-headers-db.conf
```

### `docker/nginx/default.prod.conf.template`
Add a new `location /db` block inside the existing 443 server. Place it
right after the existing `/horizon` location so the ops blocks are
grouped.

```nginx
# Adminer DB UI — proxied to the internal adminer service. Gated at
# the Laravel layer by RequireOpsAuth (same session as /horizon), then
# again by Adminer's own MySQL login form. CSP relaxed for inline
# scripts the same way /horizon does it.
location /db {
    limit_req zone=api_general burst=20 nodelay;
    include /etc/nginx/snippets/security-headers-db.conf;

    # Bounce GETs through Laravel first so the ops password is checked.
    # Laravel sets the session cookie; on success it 302s to /db with
    # an internal redirect that forwards to the upstream adminer.
    auth_request /horizon-auth-check;

    proxy_pass http://adminer:8080/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_set_header X-Forwarded-Prefix /db;

    # Adminer redirects after login etc. — rewrite Location headers so
    # the browser stays under /db.
    proxy_redirect ~^/(.*)$ /db/$1;
}

# Internal-only auth probe used by auth_request above. 200 if the ops
# session is active, 401 otherwise. Routed to the Laravel api so it
# can read the session.
location = /horizon-auth-check {
    internal;
    include fastcgi_params;
    fastcgi_pass api:9000;
    fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT /var/www/html/public;
    fastcgi_param PATH_INFO "";
    fastcgi_param REQUEST_URI /ops/auth-check;
    access_log off;
}
```

Note: `auth_request` in nginx requires the upstream to return 2xx (allow)
or 401 (deny). On 401 the user gets a plain 401 page, which is hostile.
Better: don't use `auth_request` and instead redirect with a Laravel
controller. **Switch to this implementation:**

```nginx
location /db {
    limit_req zone=api_general burst=20 nodelay;
    include /etc/nginx/snippets/security-headers-db.conf;

    # All requests to /db go through Laravel first. The ops controller
    # validates the session and either 302s to /horizon-login or
    # X-Accel-Redirects to the internal adminer location below.
    include fastcgi_params;
    fastcgi_pass api:9000;
    fastcgi_param SCRIPT_FILENAME /var/www/html/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT /var/www/html/public;
    fastcgi_param PATH_INFO "";
}

# Internal location reached only via X-Accel-Redirect from Laravel.
location /__db_internal/ {
    internal;
    proxy_pass http://adminer:8080/;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_redirect ~^/(.*)$ /db/$1;
}
```

This is cleaner: Laravel owns the auth decision, nginx just enforces
that `/__db_internal/` is unreachable from the outside.

### `api/routes/web.php`
Add a catch-all that pushes everything under `/db` through a controller.

```php
Route::any('/db/{path?}', [\App\Http\Controllers\OpsDbProxyController::class, 'proxy'])
    ->where('path', '.*');
```

### `api/app/Http/Controllers/OpsDbProxyController.php` (new)
```php
namespace App\Http\Controllers;

use App\Http\Middleware\RequireHorizonAuth;
use App\Models\HorizonAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class OpsDbProxyController extends Controller
{
    public function proxy(Request $request, ?string $path = null): Response
    {
        $admin = HorizonAdmin::query()->first();
        if (! $admin) {
            return redirect('/horizon-setup');
        }

        $sessionToken = $request->session()->get('horizon_authed');
        if (! is_string($sessionToken) || ! hash_equals(
            HorizonAuthController::authToken($admin->password_hash),
            $sessionToken,
        )) {
            return redirect('/horizon-login?next=' . urlencode($request->getRequestUri()));
        }

        // Auth ok — hand off to the internal nginx location, which
        // proxies to the adminer container. X-Accel-Redirect strips
        // the /db prefix and forwards the rest.
        $rewritten = '/__db_internal/' . ltrim((string) $path, '/');
        if ($request->getQueryString()) {
            $rewritten .= '?' . $request->getQueryString();
        }

        return response('', 200)->withHeaders([
            'X-Accel-Redirect' => $rewritten,
            'Content-Type' => '',  // let upstream set it
        ]);
    }
}
```

Note: `HorizonAuthController::authToken()` is already public static on
the bulk-import branch — no change needed there.

### `api/app/Http/Controllers/HorizonAuthController.php`
Tiny tweak: support `?next=` on `/horizon-login` so a successful login
redirects back to wherever the user came from (e.g. `/db`). Falls back
to `/horizon` when not provided. Replace the two `return redirect('/horizon');`
in `setup()` and `login()` with:

```php
$next = $request->validated()['next'] ?? $request->input('next');
$safeNext = (is_string($next) && str_starts_with($next, '/') && ! str_starts_with($next, '//'))
    ? $next : '/horizon';
return redirect($safeNext);
```

The path-shape check stops open-redirect mischief (`//evil.com`, scheme
URLs, etc.). Add `next` to the validation rules with `nullable|string|max:500`.

Also pass `next` through the login form: in `resources/views/horizon/login.blade.php`, add a hidden field that re-emits the `?next` query param.

### `api/app/Http/Middleware/RequireHorizonAuth.php`
No change needed — the middleware is only used by Horizon's own routes.
The /db proxy controller does its own auth check. (We could DRY by
extracting the check into a trait, but given it's two call sites the
copy is fine for now.)

---

## Testing checklist

1. **Local up** — `docker compose -f docker-compose.prod.yml -p vk_test --env-file .env.local up -d` (or however local mirrors prod).
2. **Cold visit unauthed:** `curl -i https://localhost:8443/db/` → 302 to `/horizon-login?next=/db`.
3. **After Horizon login:** browse to `/db` → Adminer login form. Enter MySQL creds from `.env.staging` (DB_HOST=db, DB_USERNAME, DB_PASSWORD). Should land in the schema browser.
4. **Direct internal probe:** `curl https://localhost:8443/__db_internal/` → 404 (nginx rejects external access to internal locations).
5. **Adminer-side cookie persists** — close tab, reopen `/db`, no MySQL re-login.
6. **Logout from Horizon also kills /db:** POST `/horizon-logout`, then GET `/db` → 302 to login.
7. **CSP** — open browser devtools on `/db`, no CSP violations in console.
8. **Rate limit** — hammer `/db/?wrong=1` 30 times, should start getting 503/429 from nginx.
9. **Path traversal probes** — `GET /db/../horizon-setup` should NOT bypass the proxy (Laravel route catch-all should still match).
10. **Adminer redirects** — log in, click around tables. URLs should stay under `/db/...`, no leakage to bare paths.

---

## Open questions for the operator

1. **Restrict to read-only?** Adminer image doesn't have a built-in
   read-only mode. If we want it, options are:
   - Create a `vaultkeeper_readonly` MySQL user with SELECT-only grants
     and document that operators connect with that user normally.
   - Use the `adminer-loginservices` plugin to enforce a specific user.
   Default in this plan: no restriction. Operators connect as the same
   user the app uses.

2. **Logging.** Adminer logs queries to its container stdout when
   `ADMINER_PLUGINS` includes `dump-json` etc. We're not enabling any
   plugins. Is an audit log of who-ran-what queries needed, or is
   docker-logs sufficient?

3. **Session sharing risk.** Reusing the Horizon session means losing
   the password leaks both Horizon and DB access at once. Acceptable
   for a small-team setup; revisit if scope changes.

4. **Adminer version pinning.** Plan uses `adminer:5`. Pin to a specific
   minor (`5.0.5`) for reproducibility, or rely on the floating `5` tag.

---

## Implementation order

1. compose service + nginx snippet + Dockerfile copy + nginx location.
   Bring `adminer` up locally, confirm `/db` reaches the Adminer login
   form when the proxy controller is bypassed (temporarily set
   middleware to permit-all).
2. Write `OpsDbProxyController` + route. Confirm unauthed → redirect.
3. Add `next` support to `HorizonAuthController`.
4. End-to-end: clear cookies, visit `/db`, walk through full flow.
5. Hostile tests from the checklist above.

---

## Out of scope

- DB schema migrations.
- Frontend changes.
- Adding a separate ops password independent of Horizon.
- Sharing the credential store with Cockpit/Portainer (those use their
  own auth).
- Backups UI / scheduled exports.
- Read-replica routing (Adminer always hits the primary).
