# Deploy notes

Practical notes for deploying worldbuilder (Laravel + Inertia/Vue + Vite). Written to capture the gotchas
we've actually hit.

## Build & release steps

```bash
git pull

# PHP deps (see "composer install fails with GitHub 504" if this errors)
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# Front-end
npm ci --audit false
npm run build            # emits to public/build — deploy this

# Apply schema changes, then clear/rebuild caches
php artisan migrate --force
php artisan config:clear   # or: php artisan config:cache
php artisan route:clear    # or: php artisan route:cache

# Reload PHP so the new code is actually used (OPcache caches the old classes otherwise)
sudo systemctl reload php8.3-fpm   # (or your process manager)
```

> **OPcache:** reloading php-fpm matters. Without it, edited PHP classes (e.g. `AnthropicClient`) keep
> running from OPcache and your fix appears "not deployed."

## A queue worker MUST be running

The AI-heavy and long-running features run on the queue, not in the web request:

- Session recaps — `TranscribeRecap`, `AnalyseRecap`
- Build-a-world-from-notes — `PopulateWorldFromSeed`
- Add-knowledge ingestion — `PlanWorldIngestion`, `ApplyWorldIngestion`

Without a worker these sit "queued" forever. Run a supervised worker:

```bash
php artisan queue:work --tries=1 --timeout=1800
```

(Use Supervisor/systemd to keep it alive. `--timeout` must exceed the longest job — transcription is 1800s.)

## composer install fails with GitHub 504

Symptom during build:

```
Failed to download <pkg> from dist:
  The "https://api.github.com/.../zipball/..." file could not be downloaded (HTTP/2 504)
Source fallback is disabled. Not trying alternative sources.
```

This is a **transient GitHub API problem**, not the app. GitHub was timing out (or the unauthenticated API
rate limit — ~60 req/hr — was hit).

1. **Retry the deploy first.** These almost always clear on a re-run.
2. **Add a GitHub token to the build environment** so Composer authenticates (~5,000 req/hr, far more
   reliable). Set this as a build secret/env var — **do not commit it**:

   ```
   COMPOSER_AUTH={"github-oauth":{"github.com":"<GitHub PAT>"}}
   ```

   A classic PAT with `public_repo` scope is enough (all deps are public). Composer reads `COMPOSER_AUTH`
   automatically.
3. **Let Composer fall back to source** when a zipball download fails, so it self-heals — drop
   `--prefer-dist`, or wrap the install in a retry:

   ```bash
   for i in 1 2 3; do composer install --no-dev --no-interaction --optimize-autoloader && break; sleep 15; done
   ```

## Runtime config that bites

### Sessions — keep them off the `cookie` driver
Set `SESSION_DRIVER=database` (or `redis`) in production. On the `cookie` driver the whole session is
serialised into the cookie; once it passes the browser's 4096-byte cookie limit the browser silently drops
it, breaking CSRF/auth and causing POSTs to 302-redirect or fail. The `database` driver keeps the cookie to
a ~40-byte session id.

```
SESSION_DRIVER=database
```

Ensure the `sessions` table exists (`php artisan migrate`). After changing, `php artisan config:clear` and
have users clear the stale oversized cookie for the domain once.

### AI (Anthropic) and Cloudflare 504s
`config('services.anthropic.key')` must be set for any AI feature to work (recaps, Muse, generators,
ingestion). Synchronous web AI calls are capped below Cloudflare's ~100s origin limit
(`AnthropicClient::WEB_MAX_TIMEOUT`), so a slow generation returns a clean "took too long, try again" error
instead of a gateway 504 — this only takes effect once the updated `AnthropicClient` is deployed and
php-fpm reloaded.

### Storage (media, recap audio)
Media and recap audio go to S3 (`MEDIA_DISK=s3` + the `AWS_*` vars). `symfony/filesystem` and
`league/flysystem-aws-s3-v3` must be installed (they're in `composer.json`).

## Admin-gated features
Some features are off by default per world and enabled from the admin **Worlds** page:

- **D&D Beyond import** (`ddb_enabled`)
- **Add knowledge / ingestion** (`knowledge_ingestion_enabled`)

Newly deployed columns start `false`; grant access per world from the admin panel.
