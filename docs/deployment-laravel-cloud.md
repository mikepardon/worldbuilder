# Deploying Worldbuilder to Laravel Cloud

A runbook for taking this app to production on **Laravel Cloud** (`laravel.com/cloud`).
Scope for the first launch (agreed): realtime VTT **on**, per-world custom domains **required**,
Redis/Valkey for cache + sessions + queue.

> Terminology: Cloud auto-injects env vars for attached resources (DB, cache, WebSockets). Those are
> marked _(auto)_ below — you do not set them by hand. Everything else goes in the environment's
> variables or the org Secrets Manager.

---

## 1. Target architecture

| Concern | Laravel Cloud resource | Notes |
|---|---|---|
| Web | **App compute** (port 8080) | Inertia SSR optional; Octane/FrankenPHP optional |
| Background jobs | **Worker cluster** | Imports, recaps, AI, Deepgram callbacks |
| Realtime | **WebSocket (Reverb) cluster** | Managed, Pusher-compatible |
| Database | **Managed Postgres** (serverless, Neon) | Auto-injects `DB_*`; pgvector available |
| Cache/session/queue | **Managed Valkey** (Redis-compatible) | Auto-injects `CACHE_STORE`/`REDIS_*` |
| File storage | **S3 bucket** | Media + avatars (public) and recap audio (private) share one bucket |
| Scheduler | **Scheduled Tasks** (optional) | App has **no** scheduled command today (scheduled publish is query-time) |

External services still required: an **S3 bucket**, a **TURN server** (WebRTC), a **mail provider**,
**Stripe** (+ webhook), **Anthropic**, **Deepgram**, and a **Laravel Cloud API token** (for the custom-domain
integration — see §6).

---

## 2. Provisioning order

1. Create the app/environment from the Git repo; pick a region near your users.
2. Attach **Postgres**, **Valkey**, and the **WebSocket (Reverb)** cluster (auto-injects their env).
3. Create the **Worker** process: `php artisan queue:work --tries=3 --max-time=3600`.
4. Provision the **S3 bucket** + IAM credentials (§4).
5. Stand up a **TURN** server (§5).
6. Generate a **Cloud API token** for the custom-domain workstream (§6).
7. Set environment variables/secrets (§3), then deploy (§9).

---

## 3. Environment variables

Full production delta from `.env.example`. `_(auto)_` = injected by an attached Cloud resource.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.yourdomain            # your primary app domain

# Database — Postgres (managed)
DB_CONNECTION=pgsql
# DB_HOST / DB_USERNAME / DB_PASSWORD / DB_DATABASE   (auto)

# Cache / sessions / queue — Valkey (managed)
CACHE_STORE=redis                          # (auto, but keep explicit)
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
# REDIS_HOST / REDIS_PASSWORD / REDIS_PORT            (auto)
SESSION_DOMAIN=.yourdomain                 # so the app + its subdomains share sessions

# Storage — S3 (media + avatars public, recap audio private)
FILESYSTEM_DISK=s3
MEDIA_DISK=s3
AWS_ACCESS_KEY_ID=…
AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=…
AWS_BUCKET=…
AWS_URL=https://your-bucket.s3.region.amazonaws.com
MEDIA_CDN_URL=                             # optional CloudFront in front of media/*+avatars/*

# Realtime — Reverb (managed WebSocket cluster)
BROADCAST_CONNECTION=reverb
# REVERB_APP_ID / REVERB_APP_KEY / REVERB_APP_SECRET  (auto)
# REVERB_HOST=ws-xxxx-reverb.laravel.cloud            (auto)
# REVERB_PORT=443  REVERB_SCHEME=https  REVERB_VERIFY_SSL=true   (auto)

# WebRTC voice/video — real TURN server (STUN-only fails on strict NATs)
WEBRTC_STUN_URL=stun:stun.l.google.com:19302
WEBRTC_TURN_URL=turn:your-turn-host:3478
WEBRTC_TURN_USERNAME=…
WEBRTC_TURN_CREDENTIAL=…

# Mail — replace the log driver
MAIL_MAILER=smtp
MAIL_HOST=…  MAIL_PORT=…  MAIL_USERNAME=…  MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=…  MAIL_FROM_NAME="${APP_NAME}"

# Services (secrets)
ANTHROPIC_API_KEY=…
STRIPE_KEY=…  STRIPE_SECRET=…  STRIPE_WEBHOOK_SECRET=…
DEEPGRAM_API_KEY=…                         # confirm exact key name in config/services

# Custom domains via Cloud Domains API (replaces the fixed-IP model — see §6)
LARAVEL_CLOUD_API_TOKEN=…                  # org bearer token
LARAVEL_CLOUD_ENVIRONMENT_ID=…             # target environment id
# CUSTOM_DOMAIN_IP is retired — do not set it
```

Secrets (API keys, Stripe secret, DB/Redis passwords) belong in the **org Secrets Manager**, linked to the
environment; non-secret config in plain environment variables. Because Echo reads its host from the
server-shared `broadcast` prop (not `import.meta.env`), the `VITE_REVERB_*` vars Cloud injects are
**not used by this app** and can be ignored — no rebuild is tied to the Reverb endpoint.

---

## 4. Storage (S3) — no code change, one careful bucket policy

Every upload already routes through `config('media.disk')`, and URLs are built from
`Storage::disk()->url()` (or `MEDIA_CDN_URL`). Switching `MEDIA_DISK=s3` is **config-only**.

**The one subtlety — mixed visibility on a single bucket:**

| Prefix | Access | How it's served |
|---|---|---|
| `media/*` | **public** | unsigned `->url()` (world banners, section images, entry/map images) |
| `avatars/*` | **public** | unsigned `->url()` (user avatars) |
| `recaps/*` | **private** | **signed** `temporaryUrl()` (session audio; Deepgram) |

So do **not** make the whole bucket public and do **not** set disk-level `visibility: public` (it would
expose recap audio). Grant public read **scoped to the two public prefixes** via a bucket policy:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "PublicReadMediaAndAvatars",
    "Effect": "Allow",
    "Principal": "*",
    "Action": "s3:GetObject",
    "Resource": [
      "arn:aws:s3:::YOUR_BUCKET/media/*",
      "arn:aws:s3:::YOUR_BUCKET/avatars/*"
    ]
  }]
}
```

Keep "Block Public Access" **on** for ACLs; grant read through this **policy** only. `recaps/*` stays private
and continues to work via signed URLs. AWS credentials are required regardless of the media disk, because
recaps/transcription hardcode `Storage::disk('s3')`.

Alternatively: a private bucket behind **CloudFront** (OAC) over `media/*`+`avatars/*`, with `MEDIA_CDN_URL`
set to the distribution — better caching for a public wiki.

---

## 5. Realtime — Reverb + WebRTC

- **Reverb** is a managed WebSocket cluster. Attach it and Cloud injects `REVERB_*` (id/key/secret/host/
  port/scheme). Set `BROADCAST_CONNECTION=reverb`. Confirm `config/broadcasting.php`'s `reverb` connection
  reads those env vars (stock Laravel does).
- **Allowed origins (multi-tenant gotcha):** add every browser origin to the cluster's allowed origins or
  connections fail **silently**. That includes the app domain **and any per-world custom domain** whose
  public reader uses realtime (e.g. `Public/Map.vue`). If custom-domain readers need live maps, this list
  has to include those domains — reconcile with §6.
- **WebRTC:** set a real **TURN** server (`WEBRTC_TURN_*`); the default is STUN-only and fails on
  strict/symmetric NATs. Readers are HTTPS, so browsers permit camera/mic.

---

## 6. Custom domains — BACKLOG (deferred) ⚠️

**Deferred for the first launch.** Per-world custom domains do **not** function on Laravel Cloud until the
Domains API integration below is built, because the current fixed-IP model can't port. Launch on the primary
app domain (and/or `*.yourwiki.app` subdomains via a single wildcard); ship this as a fast-follow. The design
is captured here so it's ready to pick up.

**This is the one item that needs code changes, not just config.**

**Today:** a world sets `custom_domain`; the customer adds an **A record → one fixed IPv4**
(`CUSTOM_DOMAIN_IP` / `config('domains.ip')`); `WorldDomainController::verify` checks the domain resolves to
that IP; `HomeController` serves the world's reader when `getHost()` matches a verified `custom_domain`.

**Why it breaks on Cloud:** there is **no single stable ingress IP** to hard-code. Cloud fronts apps with
Cloudflare + AWS load balancing; the published IPs are many, per-region, and explicitly "subject to change".
Cloud (not the app) owns domain verification and auto-provisions TLS.

**New model — use the Cloud Domains API** (`https://cloud.laravel.com/api`, `Authorization: Bearer <token>`):

1. When a GM sets/verifies a `custom_domain`:
   `POST /environments/{environment}/domains` with `{ name, allow_downtime, verification_method }`.
   Store the returned domain **id** on the world; surface the returned **`dns_records`** (CNAME/TXT) to the GM.
2. Poll `POST /domains/{domain}/verify` (or `GET /domains/{domain}`); treat
   `hostname_status`/`ssl_status = verified` as **live**. **TLS is automatic** — remove all cert/IP logic.
3. On removal/offboarding: `DELETE /domains/{domain}`.
4. Routing in `HomeController` is **unchanged** — Cloud routes all attached domains to the same environment;
   "look up world by `Host` header, serve its reader" still works.

**Code touch-points:**
- Rework `app/Http/Controllers/WorldDomainController.php` (`update`/`verify`/`destroy`) to call the API
  instead of DNS-to-IP checks.
- Retire `config/domains.php` (`ip`) and `CUSTOM_DOMAIN_IP`; store the Cloud domain id (add a nullable
  `custom_domain_cloud_id` column, or reuse settings).
- Update `resources/js/Pages/Worlds/Domain.vue` to show the API-returned DNS records + live status.
- Add a small Cloud API client (token + environment id from env/secrets).

**Operational limits:** domain caps are **per organisation** (Business 250; extra domains **$0.25/domain/mo**),
with an explicit "contact us for multi-tenant" note — **contact Laravel Cloud early** if many worlds will use
custom domains. For your own subdomains (`*.yourwiki.app`) a single **wildcard** domain suffices; each
customer's own apex domain is a separate attached domain that counts toward the cap.

> Recommendation: if this integration isn't ready, launch on the app domain / subpaths and ship custom
> domains as the immediate fast-follow — it's isolated to the files above.

---

## 7. Database — Postgres

Migration audit (all 112 migrations): **0 blockers** on Postgres. Notes:

- **`->json()` → `->jsonb()`** — **DONE.** All 38 project migrations converted (57 columns). Beyond
  indexing/perf, this is a **correctness** fix: `PublicWorldController` uses `whereJsonContains('slug_aliases', …)`,
  and Postgres containment requires `jsonb` (the `json` type doesn't support it). SQLite maps `jsonb()` to
  TEXT, so dev is unaffected.
- **Test two `->change()` migrations** on Postgres during the first migrate: `…add_external_key_to_media_table`
  (`user_id` → nullable, `nullOnDelete`) and `…make_worlds_code_nullable`.
- `->after()` (40+ spots) is ignored by Postgres — harmless.
- **Seeding:** deploy runs `migrate --force` only. If you seed, run `CompendiumSourceSeeder` +
  `GlobalAttributeSeeder` (idempotent, `updateOrCreate`). **Do NOT run `DatabaseSeeder`** in production — it
  creates a test admin + GM user.
- **Pre-flight:** run the test suite against a **Postgres** instance (not SQLite) once, to catch any JSON-query
  differences.

---

## 8. Queue & jobs

- Run a dedicated **Worker cluster** (`php artisan queue:work`). Jobs include compendium/DDB imports, world
  seeding, AI recaps and the Deepgram callback path — some are **long-running**.
- **Scale-to-zero caveat:** a sleeping worker can interrupt in-flight jobs. Use Cloud's managed-queue guidance
  / a worker sized to stay warm, and set a generous `--max-time`/timeout above the longest job
  (`DB_QUEUE_RETRY_AFTER` is 300s today; on Redis, tune `retry_after` similarly).

---

## 9. Deploy pipeline

Deploy commands:

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize            # config/route/view/event cache
```

- Health check → **`/up`** (already defined in `bootstrap/app.php`).
- Trusted proxies are already `*`, so HTTPS URL generation behind the LB is correct.
- `env()` is only used in config files, so `config:cache` is safe.

---

## 10. Stripe & mail

- Register the **production Stripe webhook** endpoint + secret (`STRIPE_WEBHOOK_SECRET`). CSRF is already
  excluded for `stripe/webhook` and `webhooks/deepgram/*` in `bootstrap/app.php`.
- Replace `MAIL_MAILER=log` with a real transport before launch — password resets, campaign invites and
  player notifications depend on it.

---

## 11. Pre-flight checklist

- [ ] Postgres attached; `migrate --force` clean (watch the two `->change()` migrations)
- [ ] Test suite green **against Postgres**
- [ ] Valkey attached; cache/session/queue on `redis`
- [ ] Worker cluster running and draining the queue
- [ ] S3 bucket + IAM; `media/*`+`avatars/*` public via policy; `recaps/*` private; upload → view a banner/image
- [ ] Reverb cluster attached; app **and** custom-domain origins in allowed origins; a live map/room syncs
- [ ] TURN server reachable; WebRTC voice/video connects across networks
- [ ] Real mailer sends (trigger a password reset)
- [ ] Stripe webhook verified end-to-end
- [ ] `APP_DEBUG=false`, `APP_ENV=production`, fresh `APP_KEY`, all API keys set as secrets
- [ ] Custom-domain flow: attach a test domain via the API, records shown, status → verified, reader served

---

## 12. Work summary

**Required before (or at) launch**
- All config/env/infra in §3–§10.

**Fast-follows (non-blocking)**
- **Custom-domain rework** to the Cloud Domains API (§6) — deferred to backlog; launch without per-world
  custom domains.
- CloudFront/CDN in front of public media prefixes (§4).
- Inertia SSR / Octane on the App cluster if you want the perf.

**Done**
- `->json()` → `->jsonb()` migration pass (§7).

**References:** Laravel Cloud docs — Domains, Network, Compute, Queues, WebSockets, Postgres, Valkey, Secrets
(`laravel.com/cloud/docs/*`).
