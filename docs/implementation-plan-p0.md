# WorldBuilder — P0 Implementation Plan (pre-launch)

**Status:** P0 complete; P1 underway (search + interactive maps Stage 1 done). Last updated 2026-08-05. Full suite green at time of writing (203 tests).

Scope: the four pre-launch initiatives that make the worldbuilding core feel complete and safe to open up, plus the first P1 (search). Everything below was built as *extend, not rebuild* — and investigation showed items 3 and 4 were already largely built, so those became "harden + test" rather than greenfield.

Standards applied throughout: `declare(strict_types=1)`, British English, validate-then-extract (no mass assignment), transactions around multi-row mutations, PHPUnit unit + feature tests with specific-value assertions, Pint on changed PHP.

Legend: ✅ done · 🟡 partial (polish deferred) · ⬜ not started.

---

## Baseline (what already existed)

- **Documents** carry `kind` (category), `content` (markdown), `data` (structured fields, JSON), `summary`, `is_private`, `tags`. Slugs unique per `campaign_id` + `kind`.
- **`DocFields`** resolves per-kind field schemas from the admin-managed `global_attributes` table (`type`, `options`, `required`, `ref_kinds`, `help`), falling back to built-in `DEFAULTS`. Categories were already data-driven.
- **`DocumentLink`** stores typed relations with `outgoingLinks` / `incomingLinks` (backlinks).
- **`EntryRefs`** parses inline compendium embed tokens (`{{monster=id}}`, generic `{{type=id}}`).
- **`WikiLinks::sync`** already mirrored `[[Title]]` links into `document_links` on save.
- **`Secrets`** already split/stripped/revealed `{{secret}}…{{/}}` blocks; the reader already stripped them for players.

---

## 1. Typed query/service layer (foundation) ✅

**What shipped**
- `app/Support/Viewer.php` — `final readonly` value object `{ userId, isOwner, isMember, isGm }` with `authed()` and `seesPrivate()`. Resolved once at the controller boundary; the single source of the "GM sees all, players see public" rule.
- `app/Data/DocumentSummaryData.php` — the one definition of an entry's list shape, with `toReaderCard()` (public pages) and `toManageRow()` (GM dashboard).
- `app/Queries/DocumentQuery.php` — `visibleTo()` (viewer-scoped models, for the reader) and `summariesFor()` (DTOs). `search()` added later (see P1).
- `CampaignCompendiumItem::scopeVisibleTo(Viewer)` — the same visibility rule for compendium items, as an idiomatic model scope.
- Refactored `PublicCampaignController` (resolves a `Viewer`; all reads via `visibleTo`; embed/spell queries via `visibleTo` scope; identical props) and `CampaignController::show` (rows via the DTO).

**Deviations from the original plan**
- No `DocumentDetailData` / `detail()` — the reader still assembles its rich per-article payload (facts, siblings, chronicle, embeds) from models, because those helpers need Eloquent models. Only the *visibility-scoped* reads moved into the query layer; that's where the security value is.
- `CompendiumQuery` became a model **scope** (`scopeVisibleTo`) rather than a separate class — cleaner and correctly typed against relations.
- Naming: `forWorld` → `visibleTo`.

**Tests:** `tests/Unit/DocumentQueryTest.php`, `tests/Unit/CompendiumVisibilityTest.php` — GM sees private, player/guest/owner-as-player do not; DTOs carry visibility. Existing reader/embed/smoke suites stayed green (proves the refactor preserved the Inertia contract).

---

## 2. Article templates by category ✅

**What shipped**
- `Sections::categories()` + `Sections::DESCRIPTIONS` — the canonical category catalogue (kind, label, blurb, `hasTemplate`). Built on `Sections` (which already owned kinds/labels) rather than a new class.
- `CampaignController::show` passes `categories`; **`Campaigns/Show.vue`** replaces the bare `<select>` with a category-card picker (label + description + a "Template" badge, selected-state highlight, inline validation).
- `kind` is validated server-side against the catalogue.

**Already satisfied (verified, not rebuilt)**
- Template fields on create — the field editor renders straight from `DocFields::for($kind)`, so a new "location" opens with its template fields with no seeding.
- Consistent public rendering — the reader already picks the field renderer for template kinds and shows quick-facts via `Facts::for`.

**Tests:** `tests/Unit/SectionsCategoriesTest.php` (catalogue + template flags), `tests/Feature/DocumentCategoryTest.php` (valid create, unknown kind rejected, dashboard exposes the catalogue).

---

## 3. `[[Entry]]` auto-linking 🟡 (engine done + tested; two polish items deferred)

**Found already built:** `WikiLinks::sync` runs on every content save (`DocumentController::update`), mirroring `[[Title]]` / `[[Title|alias]]` into `document_links` (`source='wikilink'`), **matched by title, case-insensitively, scoped to the world**. The `[[` typeahead (via `useTextareaAutocomplete`) exists in `ArticleEditor` and `BrewEditor`; `ArticleEditor` also has a "Linked from" backlinks panel + mini graph; the reader resolves `[[…]]` against viewer-scoped `wikiTargets`. All three editors post `content`, so linking works everywhere.

> Note vs the original plan: the token resolves by **title** (not slug), and the link source is **`wikilink`** (not `mention`). No `[[kind:slug]]` form.

**What shipped this pass:** the missing test coverage — `tests/Unit/WikiLinksTest.php` (sync links a match, case-insensitive, replaces stale wikilinks while keeping manual links, ignores unmatched, never self-links) and a lifecycle test in `EditorDispatchTest` (a real `PUT` through the editor creates the backlink).

**Deferred polish (both in large, test-less editor components):**
- Inline `[[` typeahead in `FieldEditor` sections (needs a `WikiTextarea` extraction that preserves the caret-based snippet toolbar).
- A backlinks side-panel in the Brew/Field editors (Article has one; backlinks are otherwise discoverable via the world "web" graph).

---

## 4. Inline secrets 🟡 (engine hardened + tested; group-awareness is P1)

**Found already built:** `Secrets::strip/reveal/count/split` over `{{secret}}…{{/}}` blocks (not the planned `:::secret:::` fence), server-side stripping for players in the reader, a GM "secret" snippet in the Brew/Field insert toolbars, and a reveal flow.

**What shipped this pass — a real security fix:** the player-facing `strip` used a non-greedy regex that **leaked an unclosed `{{secret}}`** (a GM typo) and left fragments from nested blocks. Rewrote it as a **depth-aware scanner**: unclosed secrets hide through to end-of-content (fail-safe), nested blocks are removed whole, stray tokens never survive, and multibyte text outside secrets is preserved byte-for-byte (it only cuts at the ASCII token boundaries).

**Tests:** `tests/Unit/SecretsTest.php` (well-formed, unclosed, nested, orphan tokens, adversarial/BLNS content, multibyte, count, reveal) and a reader test proving the secret's **bytes are absent from a player's HTTP response** — not merely hidden client-side.

**Deferred to P1:** group-aware secrets (share a block with a specific player group) — slots into `Secrets::strip` + `Viewer` once subscriber groups exist.

---

## P1 — Full-text search ✅

- `DocumentQuery::search(Campaign, Viewer, term)` — viewer-scoped search over title/summary/content/tags, portable `LOWER(...) LIKE ?` (same on SQLite and Postgres, parameterised).
- `CampaignCompendiumItem::scopeSearch(term)` — name/summary, composes with `visibleTo`.
- `SearchController` + `GET /campaigns/{campaign}/search` — returns matching entries **and** compendium items together; GM-gated. `Search/Index.vue` page, reachable from a "Search" button on the world dashboard.
- Tests (`tests/Feature/SearchTest.php`, `tests/Unit/DocumentQueryTest.php`): finds both kinds, content match is case-insensitive, blank query returns nothing, **search is viewer-scoped**, non-owners forbidden.

---

## P1 — Interactive maps (Stage 1) ✅

The first slice of the VTT port from the roadmap — image maps with entry-linked pins, GM editor + player viewer, fully visibility-scoped.

- **Data model:** `Map` (world-unique slug, optional `Media` image, `is_private`, `sort`) and `MapPin` (percentage `x`/`y`, optional `label`/`note`, optional linked `document_id`). `Map::scopeVisibleTo` reuses the `Viewer` rule.
- **Backend:** `MapController` (index / store / show / update / destroy / uploadImage) and `MapPinController` (store / update / destroy); `{map}` route-binding scoped to `{campaign}`. Guards: pins link only to same-world entries, positions clamped 0–100, deleting a map cascades its pins.
- **GM editor (`Maps/Show.vue`):** upload/replace image, **pan (drag) + zoom (scroll)** via CSS transform (no Leaflet), **click-to-place** pins, **drag-to-reposition**, and a side panel to set label / linked entry / note. Pins counter-scale to stay legible.
- **Reader (`Public/Maps.vue`, `Public/Map.vue`):** `/w/{world}/maps` list + `/w/{world}/maps/{slug}` read-only viewer. A "Maps" nav link shows only when the viewer can see a map (`hasMaps` on the campaign head).
- **Visibility (tested):** private maps 404 for players; the list shows only visible maps; **pins linking to GM-only entries are stripped for players**; GM notes never reach the client.
- **Tests:** `tests/Feature/MapTest.php` — GM CRUD, pin CRUD, cross-world guard, bounds, cascade delete, editor props, and the three reader-visibility cases.

**Stage 2 so far:**
- ✅ **Grid + measurement** — per-map grid (toggle, squares-across, distance-per-square + unit), shown in the editor and reader; a drag **ruler** reports distance in squares or the configured unit.
- ✅ **Fog of war** — GM paints reveal/cover brushes (+ reveal-all/cover-all) over grid cells; players see unrevealed cells covered opaque. Client-side play aid (full image still delivered) — flagged in the UI; true fog needs server-side tiling.
- ✅ **Tokens** — GM encounter board: place tokens (freeform or linked to a compendium stat block), drag to move, edit label/colour/size/HP; players see them on the read-only viewer.

- ✅ **Real-time sync (Laravel Reverb)** — every map mutation broadcasts a `MapChanged` "poke" on a public `maps.{id}` channel; the reader viewer subscribes via Laravel Echo and does a **viewer-scoped Inertia reload**, so players see pin/token/fog changes live while visibility stays server-enforced (no client state diffs, no private data leak). Config is server-provided via a shared Inertia prop (no `import.meta.env`). Off by default (`BROADCAST_CONNECTION=log`); enable with `BROADCAST_CONNECTION=reverb` + `php artisan reverb:start` + a queue worker.

**Map modes (clarified):** maps split into two purposes —
- ✅ **Location maps (worldbuilding)** — **managed inside the location editor as a "Map" tab** (locations only, one map per location — no standalone Maps section). A map *depicts a location* (`maps.document_id`); markers do one of three things (`map_pins.behavior`): **info popup**, **travel to that location's own map** (continent → city → dungeon), or **open the article**. The reader (`/w/{world}/maps/{slug}`) has an **expand-to-article panel** showing the depicted location (safe `RenderedContent`, secrets stripped). Combat tools (tokens/fog/grid/measure) were stripped from location maps — those live in Rooms. Tested.
- ✅ **Battle Rooms (VTT)** — a `Room` (GM-created, **not tied to a location**) with **join-by-code**, **player-owned tokens** (an owner moves only their own, the GM moves any), a shared pan/zoom board with grid, an **initiative strip** (round + GM Next/Reset), and **real-time** (`RoomChanged` poke → scoped reload). Backend fully tested (create/code, join, ownership rules, broadcast).

Rooms also have **fog of war** (GM paints reveal/cover; players see hidden squares opaque; live sync) and **chat + dice** — a shared message log where `/roll 2d6+3` (or `/r`) is rolled **server-side** (authoritative, `App\Support\Dice`) and broadcast to everyone. Location maps are now worldbuilding-only (combat tools removed) and the obsolete `map_tokens` table/controller were dropped.

**Deferred:** multi-layer maps; `toOthers()` broadcast de-duplication; a room-token initiative auto-roll; whisper/GM-only rolls.

---

## Also shipped alongside P0 (from parallel requests)

- **D&D Beyond import** reworked into a queued background job with a live progress page (fixes the 30s timeout), campaign selection, and **deduplicated image import to S3/CloudFront** (`media.external_key`, per-world override via `image_media_id`).
- **Compendium source labelling:** new `origin` column + `Compendium::sourceLabel` so imports read "D&D Beyond" / "CritterDB" / "Open5e" / "D&D 5e API" instead of "Custom".
- **Bug fix:** `Open5eClient::preview` crashed on records whose `desc` (or facets) arrive as arrays — now coerced safely (`text()` helper), with regression tests.

---

## Status board

| # | Item | Status | Notes |
|---|------|--------|-------|
| 1 | Typed query/service layer | ✅ | `Viewer` + `DocumentQuery` + DTO + compendium scope |
| 2 | Article templates by category | ✅ | category-card picker; templates already applied |
| 3 | `[[Entry]]` auto-linking | 🟡 | engine + Article/Brew done & tested; FieldEditor typeahead + Brew/Field backlinks deferred |
| 4 | Inline secrets | 🟡 | hardened (leak fixed) + tested; group-awareness → P1 |
| P1 | Full-text search | ✅ | viewer-scoped, entries + compendium |
| P1 | Interactive maps (Stage 1) | ✅ | image maps, entry-linked pins, GM editor + reader, drag-to-reposition |

---

## Next candidates

- **Maps Stage 2** — grid overlay + measurement first (Bresenham line/distance ports cleanly, no real-time), then fog of war, tokens/initiative, multi-layer maps, and real-time via Laravel Reverb.
- **Subscriber / player groups** — extends `Viewer` (a group list) and the group-aware `Secrets::strip`; unlocks per-group article visibility and shared secrets.
- **Deferred item-3 polish** — `WikiTextarea` extraction to bring the `[[` typeahead to `FieldEditor`, and backlinks panels in the Brew/Field editors.
