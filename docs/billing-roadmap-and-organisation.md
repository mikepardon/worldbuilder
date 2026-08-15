# WorldBuilder — Billing, Roadmap & Organisation

**Status:** Billing, plans, storage, custom domains and the account/admin UX are **shipped** on `world-campaign-split` (commits `0f97995`, `b5140b2`, `38cf881`); full suite green (408 tests). Roadmap and the Connections/organisation plan below are **not yet built**. Last updated 2026-08-08.

Standards applied throughout: `declare(strict_types=1)`, British English, validate-then-extract (no mass assignment), transactions around multi-row mutations, secrets encrypted at rest and never sent to the client, external boundaries (Stripe, DNS) behind interfaces so they're mockable, PHPUnit feature tests with specific-value assertions, Pint on changed PHP.

Legend: ✅ shipped · 🟡 partial (foundation exists) · ⬜ not started.

---

# Part 1 — Shipped: billing, plans & monetisation

## Plans & entitlements ✅
`App\Support\Plans` defines three tiers; entitlements enforced server-side.

| Plan | Price | Worlds | AI credits/day | Storage | Custom domain |
| --- | --- | --- | --- | --- | --- |
| Free | £0 | 1 | 5 | 0.5 GB | — |
| Basic | £5/mo | 3 | 30 | 5 GB | ✓ |
| Pro | £15/mo | 10 | 100 | 25 GB | ✓ |

- `User::planConfig()` (renamed from `plan()` to avoid colliding with the `plan` column), `worldLimit()`, `dailyAiAllowance()`, `canUseCustomDomain()`, `storageLimitBytes()`.
- World-creation limit enforced in `WorldController@store`; storage limit in `MediaController@store`; admins exempt from both.

## AI credits ✅
- Per-user **daily allowance** (resets each day) + a non-expiring purchased **top-up balance** (`ai_credit_balance`). `User::spendAiCredit()` spends daily first, then top-ups.
- Enforced on every metered AI action: `AiController@ask`, `@draft`, and `DocumentController@writeUp` — return `402` with a friendly message before the Anthropic call, spend one credit on success.
- The compendium drafter keeps its separate admin-granted **per-world** budget (`ai_generation_limit`); unifying the two is a future cleanup.

## Stripe integration ✅
All Stripe access sits behind `App\Services\Billing\BillingGateway` (impl `StripeBillingGateway`), so the app and tests never touch the SDK directly.

- **Checkout** for a first subscription; **prorated immediate upgrade** (`proration_behavior: always_invoice`) when already subscribed; **downgrade scheduled for period end** (`cancel_at_period_end` for →Free, a subscription schedule for Pro→Basic) so paid time is never lost; **in-app "keep my current plan"** undo (`cancelScheduledChange`).
- **One-off credit top-ups** via ad-hoc Checkout line items (`App\Support\TopUps`) — no pre-created Stripe prices needed.
- **Stripe Billing Portal** ("Manage billing").
- **Webhook** at `POST /stripe/webhook` (CSRF-exempt, signature-verified): `checkout.session.completed`, `customer.subscription.updated`, `customer.subscription.deleted`.
- **Self-heal:** every visit to `/billing` reconciles the plan from the customer's live subscription (resolved by **price ID** → plan via `Billing::planForPrice`), and confirms one-off purchases on return — so plans update even if the webhook is late. Fulfilment is idempotent via `processed_checkouts` (`BillingFulfilment`).
- Key files: `app/Services/Billing/*`, `app/Http/Controllers/BillingController.php`, `app/Http/Controllers/StripeWebhookController.php`.

## Admin billing area ✅
`Admin/BillingController` + `Pages/Admin/Billing.vue`:
- **Sandbox/Live mode toggle**; both key sets stored at once so switching never loses them.
- Per mode: publishable key, **secret key**, **webhook signing secret** (secrets `encrypted` cast, never returned to the browser — only a `*_set` boolean), Basic/Pro price IDs.
- Overview: subscriber counts per plan + AI credits used today.
- `App\Support\Billing` resolves the active key set by mode: `mode()`, `publishableKey()`, `secretKey()`, `webhookSecret()`, `priceId()`, `planForPrice()`, `configured()`.

## User billing page ✅
`Pages/Billing/Index.vue` (linked from the account menu): current plan, usage (daily credits, top-up balance, worlds, storage), plan cards with **Upgrade/Downgrade** labels + downgrade confirmation, a **scheduled-change** banner with undo, top-up bundles, "Manage billing", and a one-time post-checkout confirmation.

## Custom domains ✅
A Basic/Pro entitlement, per world. Setup page under the world nav (`Pages/Worlds/Domain.vue`, `WorldDomainController`):
1. Enter a domain → 2. follow the **A-record** instructions (target from `config('domains.ip')`) → 3. **Verify** (resolves A records against the target IP via the mockable `App\Services\Dns\DnsResolver`) → **Connected**.
- A verified domain serves its world's public reader at the site root (`HomeController@index`); existing `/w/{slug}` routes serve sub-pages on the same host.
- Entitlement checked against the **world owner's** plan; domain normalised, hostname-validated, uniquely constrained; verification resets on change.
- **Infra note (out of app scope):** custom domains also need TLS — provision on-demand certs (e.g. Caddy on-demand TLS, or a Let's Encrypt hook) keyed off verified `worlds.custom_domain` values.

## Other recent work ✅
- **Marketing pages:** How-it-works + Pricing (prices formatted with `brick/money`), behind an auth-aware `MarketingShell` nav ("My dashboard" when signed in).
- **Security:** `league/commonmark` bumped to 2.9.0 (six DoS advisories fixed; we render user Markdown).
- **UX:** removed the standalone Worlds page (dashboard covers it); dashboard redesigned to two columns (worlds | characters) with a read-only D&D Beyond character sheet + refresh; Profile page themed to the dark UI + DDB CobaltSession instructions; "Reveal to players" clarity + confirmation; collapsible compendium tag filter; Billing added to the account menu.
- **Dependencies added:** `brick/money`, `stripe/stripe-php`.
- **New env:** `CUSTOM_DOMAIN_IP` (see `config/domains.php`). Stripe keys are admin-managed (DB, encrypted), not env.

---

# Part 2 — Competitive roadmap vs World Anvil

Gap analysis from a codebase audit, ranked by build complexity. **Missing** = net-new; **Improve** = extend an existing foundation. (Visual version: artifact "Closing the gap with World Anvil".)

## Quick wins (~days)
- **AI content generators** (Missing) — names/NPCs/encounters/random tables; reuses the Claude client + credits, so each use is billable.
- **Discord integration** (Missing) — per-world webhook on publish / session recap.
- **Per-world analytics hook** (Missing) — paste a GA/Plausible ID loaded on the reader + custom domain.
- **Editable entry URLs** (Improve) — expose the existing auto-slug for renaming.

## Medium lifts (~1–2 weeks)
- **Co-authors & editors** on a world (Missing) — world-members pivot + policy; distinct from campaign players.
- **Per-entry access control** (Missing) — password / secret share link per article.
- **Reader themes & white-label** (Improve) — lift per-entry brew CSS to a world theme; bundle with custom domains.
- **Advanced map markers** (Improve) — line/polygon/circular markers, groups, labels, compass on `MapPin`.
- **Interactive data tables** (Missing) — sortable/filterable table block in the renderer.
- **Threaded comments** (Improve) — extend `ArticleNote` with replies + moderation.
- **Custom entry templates** (Improve) — GM-defined types/fields per world (DocFields already models this admin-side).
- **World stats dashboard** (Missing) — counts, word totals, views, activity.
- **World export / backup** (Missing) — portable archive; read-only serialiser first.

## Large builds (~multi-week)
- **Custom calendars** (Missing) — world calendars (months/weekdays/moons) wired into timelines; core Anvil feature.
- **Subscribers & access roles** (Missing) — readers follow a world, unlock role-gated content.
- **Relationship diagrams** (Improve) — family trees / diplomacy webs / org trees on the Connections foundation (see Part 3).
- **Whiteboards / mind-maps** (Missing) — free canvas; shares tech with the VTT board.
- **Custom statblock designer** (Improve) — user-defined sheets beyond D&D 5e.
- **Public API & keys** (Missing) — Sanctum tokens + scoped, rate-limited endpoints.

## Major bets (~strategic)
- **Manuscripts / writing suite** (Missing) — novel-writing environment tied to canon; strong AI home.
- **Custom RPG systems** (Improve) — full homebrew systems (dice, sheets, rules); compounds with the VTT.
- **Creator monetisation** (Missing) — sell/gate world access, Patreon import; Stripe layer already exists, needs subscribers/roles first.

## Where we already lead (market, don't rebuild)
Live **VTT** (tokens, fog, templates, initiative, dice, realtime), **AI worldbuilding assistant**, **D&D Beyond + multi-source compendium import**, **GM secrets with one-way reveal**, **custom domains**. World Anvil is a worldbuilding wiki with thin play tooling — the VTT + AI are genuine differentiators.

## Recommended order
1. AI generators (cheap, self-funding) → 2. Co-authors & per-entry access → 3. Custom calendars → 4. Subscribers & access roles → 5. Creator monetisation.

## Deferred to future scope (product decisions, 2026-08-11)
Requested but explicitly parked for later — not to be built in the current world-settings/branding wave:

- **Age gate / content-warning interstitial** — a confirm-before-reader gate for mature worlds. Simple to add later (a world setting + a client interstitial + a per-session cookie).
- **World backup / export** — a portable archive of a world (entries, compendium, media manifest) as Markdown/JSON. Start with a read-only serialiser; a Settings → Advanced "Export" button.
- **Public API & keys** — Sanctum-scoped, rate-limited read endpoints per world/account. The per-world `/w/{slug}/llms.txt` index is a lightweight precursor.

---

# Part 3 — Implementation plan: Connections web & large-scale organisation

**Problem.** On large worlds the force-directed graph (`Worlds/Web.vue` + `ConnectionGraph.vue`, data from `Connections::graph()`) becomes a hairball: every node is shown at once, edges carry no visible meaning (we store `label`/`source` on `document_links` but not a relationship *type*, and the view doesn't surface them), and there's no way to focus. Fixing it is as much data model + interaction as rendering.

**Goals.** Make it obvious *how* things connect; default to **focus, not firehose**; give large worlds a navigable **structure** (World Anvil's edge).

## Current state (what we build on)
- `document_links`: `from/to/label/source` (`source` = `manual` | `wiki`) — `app/Models/DocumentLink.php`.
- `Connections::graph()` returns all nodes + merged edges with a `label` — `app/Support/Connections.php`.
- `WikiLinks::sync` mirrors `[[Title]]` links into `document_links`.
- Force-directed render, kind-coloured legend, click-to-open — `resources/js/Components/ConnectionGraph.vue`.

## Phase 1 — Typed relationships (foundation, ~2–4 days)
- **Migration:** add `relationship` (nullable canonical key) to `document_links`; keep `label` as an optional custom override. Backfill: `source=wiki` → `mentions`, manual → `related_to`.
- **Taxonomy** in a `Relationships` support class (app-layer; no DB CHECK), each with forward + inverse phrasing so both ends read naturally: `located_in`↔"contains", `part_of`/`member_of`↔"has member", `ally_of`, `rival_of`, `family_of`/`parent_of`↔"child of", `owns`↔"owned by", `created_by`↔"creator of", `related_to`/`mentions`. Custom fallback allowed.
- **UI:** manual-link panel gains a relationship dropdown + optional note; `WikiLinks::sync` stamps `mentions`.
- **Validation:** FormRequest accepts a known key or a custom label.

## Phase 2 — Rework the Connections web (headline fix, ~1–2 weeks)
1. **Focus / ego-network by default** — land on search (or the highest-degree hub); show the entity + neighbours at depth 1 (toggle depth 2); click a neighbour to re-centre. Kills the hairball.
2. **Legible edges** — relationship phrase along the line + directional arrowhead; hover reads it as a sentence ("The Gilded Net — controlled by → Lady Merrow"); curved edges; fade the unfocused.
3. **Neighbours side-panel** — the focused entity's links grouped by relationship (Located in · Members · Allies · Mentions), each clickable. Delivers "how things connect" even without reading the graph; keyboard/screen-reader friendly.
4. **Filters + search** — filter by entry type and relationship type (interactive legend); jump-to-search any node.
5. **Layout options** — a **radial hub layout** (rings by hop distance) alongside force; node size by `degree`; labels on hover / for hubs when zoomed out.
6. **Big-graph handling** — above ~N nodes never render everything; start focused, expand on click; zoom/pan + fit.
- **Server:** extend `Connections::graph()` edges with `relationship` + inverse label; add `Connections::subgraph(World, entryId, depth)`; `?focus=&depth=` on the web route so large worlds ship a small subgraph.
- **Client:** `ConnectionGraph` gains `focusId`/`depth`/`filters`; `Web.vue` gets search, filter chips, neighbours panel, layout toggle.

## Phase 3 — Relationships where you work (~2–3 days, builds on P1)
Show a compact grouped **relationships list** (add/remove inline) on the entry editor and public reader, plus an optional small ego-graph. Most organisational value is felt in context.

## Phase 4 — Hierarchical organisation (the broader gap, ~1–2 weeks, separable)
- **User-defined categories** (nested tree), independent of entry kind: `categories` table (`world_id`, `parent_id`, `name`, `sort`) + `documents.category_id`. Collapsible tree in the world sidebar + reader nav. (Anvil's "customizable categories".)
- **Saved views / filters** on the manage list (by category, tag, type).

## Cross-cutting
- **Perf:** index `document_links(world_id, from_document_id)` and `(world_id, to_document_id)`; the subgraph query is the hot path.
- **Visibility:** private entries/links must respect the viewer role if the web ever goes public.
- **Tests:** subgraph builder (depth, edge-merge, inverse labels), relationship validation + backfill, category CRUD + policy, reader visibility.
- Migrations reversible where safe; all value validation stays app-layer.

## Sequencing
**P1 → P2 → P3** ship together as a *"connections that actually work"* release (direct answer to the hairball); **P4** follows as *"organise large worlds"*. Rough total P1–P3: ~2–3 weeks.

## Open decisions (confirm before building)
- [ ] **Relationship taxonomy** — curated set + custom fallback (recommended) vs. fully freeform?
- [ ] **Categories** — single-parent tree (simpler, matches Anvil) vs. multi-tag?
- [ ] **Public reader** — reworked web GM-only for now, or exposed to players?
- [ ] **Renderer** — extend the custom `ConnectionGraph`, or adopt `d3-force`/`cytoscape` (new dependency)?
