# WorldBuilder — Battle Room (VTT) Implementation Plan

**Status:** Core VTT + D&D Beyond character system shipped. This plan covers what's discussed-but-not-built plus proposed additions. Last updated 2026-08-07. Full suite green at time of writing (310 tests).

Scope: the next waves of virtual-tabletop work, taking the battle room from "playable board + full character sheets" toward Roll20/Foundry-class play. Everything below follows *extend, not rebuild*, and the house standards: `declare(strict_types=1)`, British English, validate-then-extract (no mass assignment), transactions around multi-row mutations, FK casts, PHPUnit feature/unit tests with specific-value assertions, Pint on changed PHP, Prettier on changed JS/Vue, realtime via the existing `RoomChanged` broadcast.

Legend: ✅ done · 🟡 partial · ⬜ not started. Effort: **S** (hours) · **M** (1–2 days) · **L** (3–5 days) · **XL** (1–2 weeks).

Confirmed product decisions (from review):

- **Fullscreen board layout** is preferred (Roll20-style), over the current app-chrome-embedded panel. → Phase A1.
- **Freehand pen is a GM tool.** Drawing tools (freehand/line/shape/text) belong on the GM tool rail. → Phase A2.
- **Live player cursors** — show where each online player's cursor is on the board, over the realtime channel. → Phase A4.
- **Combat resolution stays manual** — no initiative auto-advance and no automated attack→damage→save pipeline (players/GM drive it). Only a combat **log** is planned. → Phase F.
- **Monster stat blocks stay as-is** — no structured monster-actions table (E3 dropped).
- **Dynamic vision, walls & lighting is out of scope** (product decision, 2026-08-11) — no Foundry-style line-of-sight, wall tool, or dynamic lighting. **Static fog** (GM-painted reveal/cover) remains the fog-of-war solution. The former Phase C was removed from this plan.
- **Touch/mobile is parked** ("later, not a priority") — kept in the backlog, not scheduled.

---

## Baseline (what already exists)

So the plan below doesn't re-propose shipped work. The battle room already has:

- **Board:** pan/zoom, square grid with snap-to-cell, GM-painted **static fog**, map replace (upload / media library / location map), ruler + radius measurement.
- **Tokens:** from People (`npc` docs), compendium monsters, roster characters, DDB import, or custom; drag+snap, portraits, size, elevation badge, condition icons, right-click context menu (quick conditions, delete, ±elevation), **HP bars** (green-on-red, party-visible / enemy-hidden), **downed** greyscale + 💀, per-owner move permission.
- **AoE templates:** placeable circle/cone/line/square, persistent + shared, creator/GM dismiss (`room_templates`).
- **Initiative tracker:** add-all, roll-all, next/round, whose-turn highlight, GM "players see tracker" toggle.
- **Dice:** `/roll` `/gmroll` `/w`, clickable stat-block + character-sheet rolls, advantage/disadvantage with dropped die, GM-private-unless-Ctrl, monster/character attribution, **nat-1 red / nat-20 green**, d20 totals floored at 1, dice sound on rolls you can see.
- **Chat:** whisper, recipient dropdown (everyone / GM / player), colours, avatars, roll cards.
- **Characters:** deep D&D Beyond extraction (`Ddb::characterSheet` — abilities, skills, saves, senses, defences, spellcasting attack/DC, **spell cards**, spell slots, pact slots, hit dice, features by source, inventory, currency, description) with an in-room **tabbed sheet** (Actions / Skills / Spells table / Features / Inventory / Description); a **Refresh** button re-pulls from DDB.
- **Live combat state (per token):** damage/heal (temp-HP absorb), temp HP, death saves, **exhaustion** (2024 −2/level applied to d20 rolls), spell-slot + pact expenditure, **hit-dice** (server-rolled, healed, posted to chat), short/long rests. All gated server-side; enemy state hidden from players.
- **Presence & A/V:** presence cards for everyone connected, WebRTC mesh voice/video with per-tile controls.
- **Handouts:** GM shares image/note to the table with a lightbox (`room_handouts`).
- **Realtime:** Pusher-protocol (Reverb **or** Pusher/soketi, driver-selected) via the shared `broadcast` prop; `RoomChanged` fan-out.

---

## Phase A — Board UX & drawing

### A1. Fullscreen board layout ✅ — **M**

**What.** Replace the app-chrome-embedded room with a Roll20-style fullscreen editor: the board fills the viewport, a **left icon tool-rail** (select / pan / draw / measure / templates / fog), a **collapsible right side-panel** (the existing Chat/Tracker/GM/Handouts/Settings tabs), and a slim top strip only for room name + collapse toggles. A/V tiles float bottom-left (already do).

**Why.** Maximises map real estate — the single biggest quality-of-life win for actual play; every competitor is fullscreen.

**Approach.**
- New `resources/js/Layouts/BoardLayout.vue` (no global nav chrome) used by `Rooms/Show`, or render `Rooms/Show` outside `AuthenticatedLayout` with its own frame. Keep the `← All rooms` link + join code in the GM tab (already relocated there).
- Move the existing toolbar (currently top-left floating) into a dedicated left rail with grouped sections (Tools / Layers / FX), matching the current icon set.
- Right panel gets a collapse/expand affordance (chevron) so the board can go edge-to-edge.
- Persist panel-collapsed + rail state in `localStorage` (like `camSize`).

**Effort M. Deps:** none. **Risk:** the `calc(100vh - …)` height math and z-index of modals/overlays; verify AoE/measure overlays and context menu still position correctly.

### A2. Drawing / annotation layer ✅ — **M**

**What.** A GM drawing layer: **Freehand pen** (confirmed), **Pen/Line**, **Rectangle**, **Ellipse**, **Text**, with a colour swatch and fill toggle, plus **Clear drawings**. Shared to the table in realtime; GM-only to create by default, with an option to let players draw.

**Why.** GMs constantly sketch — traps, movement, "the wall is here." Foundry/Roll20 both have it; the user explicitly wants freehand.

**Approach.**
- New table `room_drawings`: `room_id`, `created_by`, `kind` (freehand|line|rect|ellipse|text), `points` (JSON — array of `{x,y}` % for freehand/line; two corners for rect/ellipse), `color`, `fill` (bool), `text` (nullable), `width` (stroke), `scene_id` (nullable, forward-compat with A3/Phase B), timestamps, index `room_id`. Mirror the `room_templates` model/controller/policy pattern exactly (creator or GM deletes; GM clears all).
- `RoomDrawingController@store/destroy/clear`; routes under the existing room group; `RoomChanged` broadcast; payload adds `drawings` (like `templates`).
- Reuse the AoE SVG-overlay coordinate approach in `Rooms/Show.vue` (percent → px via `toPx`, stroke `2/view.scale`). Freehand captures pointer moves into a point list (throttled), commits on pointer-up.
- Tool lives on the left rail (A1); mirrors the `aoeTool` state machine already in `Show.vue`.

**Effort M. Deps:** A1 (rail) is nice-to-have but not required. **Tests:** store/clear/permission (member vs GM vs bystander), payload shape.

### A3. Map layers ✅ — **M**

**What.** Roll20-style layers: **Map** (background), **Tokens**, **GM** (hidden from players), **Drawings**, **Fog**. A GM layer lets the GM stage tokens/notes players can't see.

**Why.** Prep hidden encounters; standard GM workflow.

**Approach.**
- Add `layer` to `room_tokens` and `room_drawings` (enum: `map|token|gm`, default `token`). Server-side: strip `layer === 'gm'` tokens/drawings from the player payload (extend the existing `seesStats`/gating in `RoomController::token`).
- Left-rail layer selector (which layer new objects go to + which is "active" for editing). Players only ever see non-GM layers.
- Cheap once A2 lands; mostly a payload filter + a selector.

**Effort M. Deps:** A2. **Tests:** a GM-layer token/drawing is absent from the player payload; present for GM.

### A4. Live player cursors ✅ — **S/M**

**What.** See where every online player's cursor is on the board in real time — a small labelled pointer (character name + assigned colour) per connected user, following their mouse, fading when idle.

**Why.** Makes the table feel co-present ("look here"), pairs naturally with the drawing tools, and reuses presence we already track.

**Approach.**
- **Ephemeral, not persisted.** Broadcast cursor moves over the presence channel with `.whisper('cursor', {x, y})` — exactly the mechanism the WebRTC signalling already uses (`presenceChannel.whisper` / `listenForWhisper` in `Rooms/Show.vue`). No DB, no `RoomChanged`, no queue load.
- Coordinates are board **percentages** (like tokens), so they map through the same `toPx`/pan-zoom transform and stay correct across different screens/zoom levels.
- **Throttle** to ~20–30 ms on send; render each remote cursor as an absolutely-positioned pointer inside the transformed board layer; reuse the presence roster (`present`) for name + the assigned nameplate colour (`colorFor`).
- Drop a cursor when its user stops moving for a few seconds or leaves (`leaving` already fires); clear all on unmount.
- Works with either broadcaster since it's presence-channel client events (Pusher/soketi or Reverb) — needs `BROADCAST_CONNECTION` set (now `pusher`) and client events enabled on the app.

**Effort S–M. Deps:** presence (already live). **Note:** presence client-events must be enabled on the Pusher/soketi app (they are for private/presence channels by default in soketi). No server or test surface beyond a channel-auth check that already exists.

### A5. Diff-based realtime (broadcast data, apply client-side) 🟡 — **L**

**Shipped so far:** **token moves** patch in place via a typed `TokenMoved` event (position only, non-GM-layer; a `TokenMoved` listener updates the token's coords and clears the optimistic `localPos`). GM-layer moves and any non-position change still use the scoped `RoomChanged` reload (so hidden tokens never leak on the public channel).

**Payload split (done, 2026-08-07):** the room payload's four live collections — `tokens`, `templates`, `drawings`, `messages` — were lifted out of the monolithic `room` prop into **independent, closure-backed Inertia props**, and `RoomChanged` now carries an **`only` scope** naming which of them changed (empty = full bundle). A chat message reloads only `messages`; a token edit only `tokens` (plus `messages` when it posts a concentration prompt); drawings/templates likewise. Because the collections are closures, a scoped reload doesn't even *compute* the excluded props — so a chat message no longer re-serialises every token's character sheet. The client (`onRoomChanged`) unions scopes across the 60 ms debounce and reloads exactly the named props. Per-viewer gating is untouched (the reload still runs server-side filtering). Verified: full suite green.

**Remaining (optional):** true zero-reload in-place patching for the non-sensitive diffs (HP-for-party, conditions, initiative, chat append) as the next step below — the scoped closure reload already removes most of the cost, so this is now a polish item rather than a necessity.

**What.** Replace the current *poke → full reload* realtime with **broadcasting the actual change** and applying it to local reactive state — no HTTP round-trip per update. Token moves, HP/condition changes, initiative, chat, drawings, and cursors all patch in place.

**Why.** Today every `RoomChanged` triggers `router.reload({only:['room']})`, which rebuilds the entire room payload server-side (tokens *with* character sheets, messages, etc.) and diffs it — a real cost on every poke. Broadcasts are already synchronous (`ShouldBroadcastNow`, done — see Known issues), so the remaining latency is that reload. Applying diffs directly is what makes it feel native/instant, and it's the same mechanism A4 (cursors) already uses.

**Approach.**
- Broadcast **typed, minimal events** carrying the changed data, not a bare poke: e.g. `TokenMoved {id, x, y}`, `TokenUpdated {id, …fields}`, `TokenRemoved {id}`, `MessagePosted {message}`, `DrawingAdded {drawing}`, `TrackerChanged {active_token_id, round}`. Client listeners patch the matching entry in `room.tokens` / `room.messages` reactively (the detail modal already re-syncs from `room.tokens`).
- **Security is the crux.** The current reload is *viewer-scoped* — the server filters per viewer (enemy HP/AC, GM-layer, private whispers). A public channel broadcast can't filter per-recipient. Two-tier rule:
  - **Non-sensitive diffs** (token x/y/size/elevation/conditions, public rolls, drawings, tracker order, party-visible HP) → broadcast the data and apply directly.
  - **Sensitive/gated changes** (enemy HP/AC, GM-layer objects, whispers, character sheets) → either keep the scoped **reload** as the fallback for those, or move to **per-user private channels** so the server sends each client only what they may see. Simplest first step: broadcast the cheap positional/roll diffs directly, fall back to reload only for gated changes — most updates (movement) become instant while security stays server-side.
- Keep an **on-demand reconciliation reload** (e.g. on reconnect) so a dropped event can't leave a client permanently out of sync.
- Coalesce rapid diffs (drag) client-side; broadcast on commit (drop), preview locally via `localPos` (already done).

**Effort L. Deps:** builds on the synchronous broadcasts already in place; pairs with A4. **Tests:** each typed event carries the expected minimal payload; a player never receives a sensitive field over a public diff (gated changes still go via the scoped reload). **Risk:** getting the security tiering right — default to reload when unsure.

---

## Phase B — Scenes

### B1. Multiple scenes per room + switching ✅ — **L**

**What.** A room owns several **scenes** (maps); the GM prepares many and switches the **active** one; each scene has its own map image, grid, fog, tokens, templates, drawings.

**Why.** Today a room is one map. Real sessions move between locations; re-uploading and re-placing every time is painful.

**Approach.**
- New `room_scenes` table: `room_id`, `name`, `image_media_id`, grid/unit fields (move the per-room map+grid fields here), `fog`/`fog_enabled`, `sort`, timestamps. `rooms.active_scene_id`.
- Add `scene_id` FK to `room_tokens`, `room_templates`, `room_drawings` (cascade on scene delete). Payload returns only the **active scene's** objects.
- Migration path: create one scene per existing room from its current map/grid/fog, repoint existing tokens/templates. Provide a `down` only where reversible.
- GM scene manager in the GM tab: add/rename/duplicate/delete, set active (broadcasts `RoomChanged`; players' boards swap).

**Effort L. Deps:** touches every board object's ownership — do after A2/A3 so drawings are included from the start. **Risk:** payload/query changes are broad; lean on the test suite. **Tests:** switching active scene changes the tokens/fog players receive; per-scene isolation.

---

## Phase D — Audio

### D1. Ambient audio / music + SFX ⬜ — **M**

**What.** GM shares ambient tracks / music to the table (play/stop/loop/volume, synced), plus event SFX beyond the existing dice sound (hit/miss/crit, turn-start chime).

**Approach.**
- `room_audio` (or a `rooms.audio` JSON): current track URL/media id, playing, loop, volume, `started_at` (server timestamp for rough sync). Broadcast changes; clients play/seek. Reuse the media library for uploads; also allow a URL (respect autoplay policy — needs a user gesture, already true mid-session).
- SFX: a small client sound map keyed by event; the sound set already lives in `public/sounds`. Server can flag `roll.sfx` (e.g. crit) that the client plays.

**Effort M. Deps:** none. **Note:** keep files small; consider streaming rather than shipping large music in the repo.

---

## Phase E — Character sheet depth

### E1. Editable sheet state 🟡 — **M**

**What.** Make the sheet writable where DDB is: toggle **prepared** spells, **equip/attune** items, add/remove inventory + adjust quantities, edit **currency**, edit description/notes. Persist as an overlay on the token (session) or the character (persistent) — decide per field.

**Approach.** Extend the token combat-state pattern (`spell_slots_used`, `hit_dice_used`) with `prepared_overrides`, `equipped_overrides`, and a small `inventory_delta`; or, for persistence across sessions, write to `Character`. Emit from `CharacterSheet.vue` (already emits `update`/`spend-hit-dice`) to new endpoints. Re-pull from DDB should not clobber live overrides mid-session (merge rules).

**Done (this pass).** Added a single `sheet_state` JSON column on `room_tokens` (`{prepared, equipped, attuned, currency}`) plus reuse of the existing `notes` column. `CharacterSheet.vue` reads each value as an override layered over the DDB base (`preparedFor`/`equippedFor`/`attunedFor`, effective `currency`), so a Refresh re-pulls the sheet without clobbering choices. UI: clickable prepared dots, equip/attune toggle chips, editable coin fields, and a new **Notes** tab (owner/GM only, saved on blur). All gated server-side (`seesStats`) — players never see enemies' sheet edits. Validated in `RoomTest` (persist + gating).

**Remaining.** Add/remove inventory items and adjust quantities (mutating the sheet's item list, not just booleans) — deferred; and editing the free-text **description** fields.

**Effort M. Deps:** none (builds on existing sheet).

### E2. Concentration tracking ✅ — **S/M**

**What.** When a character casts a **concentration** spell (the sheet knows `sp.concentration`), mark them concentrating on it; taking damage prompts a **CON save (DC 10 or half damage)**; dropping to 0/failing clears it.

**Approach.** `room_tokens.concentrating_on` (spell name/id, nullable). Casting a conc spell from the sheet sets it (and clears any prior). The **token update endpoint** detects damage server-side (drop in the HP+temp pool) on a concentrating token, so **every** damage path — the sheet's damage box, the GM edit, the tracker — triggers a check consistently (and a single request, avoiding the earlier Inertia-cancel race that reverted the HP). It posts a **pending save prompt to chat as a whisper** (owner + GM only) with **Dis / Roll / Adv** buttons; 0 HP ends concentration outright with no prompt. Rolling is server-authoritative (`1d20`/`2d20kh1`/`2d20kl1` `+ CON save − exhaustion`, floored at 1, DC = max(10, floor(dmg/2)), temp-absorbed damage counted): it replaces the prompt with the result card in place and drops concentration on a failure. A board-visible teal ring + `◎ spell` chip mark a concentrating token. Covered in `RoomTest` (prompt posted + gated, temp-HP DC, 0-HP clear, heal = no prompt, roll resolves + outcome, advantage keeps higher, non-owner forbidden).

**Effort S–M. Deps:** E1 nice-to-have.

> **Dropped (product decision):** a structured monster-actions table — the current stat-block rendering (text with clickable dice) is fine as-is.

---

## Phase F — Combat log

> **Product decision:** initiative and attack resolution stay **manual** — players/GM drive them; no auto-advance turns and no automated targeting/attack→damage→save pipeline.

### F3. Combat log / roll history ❌ — **dropped**

**What.** A filterable log of rolls/damage/turns (the chat already stores rolls; this is a focused view + turn markers).

> **Dropped (product decision, 2026-08-07):** a roll carries no reliable signal for whether it's combat-related vs an ordinary check, so a log would either be noisy or need manual tagging. The chat roll history already serves the recall need. Not building it.

**Effort S. Deps:** none.

---

## Backlog (unscheduled)

- **Touch/mobile** (parked): pinch-zoom, tap-to-move, larger hit targets. — **M**
- **Token rotation/facing** (drag-rotate handle; store `rotation`). — **S**
- **Hex grids** (grid type on scene; hex distance). — **M**
- **Undo/redo** for board actions (token moves, drawings, fog). — **M**
- **Chat @-mentions** in the room (character autocomplete already exists in editors). — **S**
- **Macros / custom roll buttons** per character (saved expressions). — **M**
- **Encounter builder / bulk monster add** (drop N of a monster, auto-name #1..#n, roll all HP). — **M**
- **GM secret notes on tokens** (private per-token GM text; distinct from player notes). — **S**
- **Keyboard shortcuts** for tools/turn advance. — **S**
- **Session export** (chat/roll log to markdown for the chronicle). — **S**

---

## Known issues to investigate

- **`App\Events\RoomChanged` / `MapChanged` fail on the queue — RESOLVED (config/setup).** Root cause found 2026-08-07: the exception is `BroadcastException: Pusher error: <Laravel 404 HTML>`. The server-side Pusher publish POSTs to `http://127.0.0.1:6001/apps/{app}/events`, but **port 6001 is occupied by a Laravel app, not a soketi/Pusher server** (root returns 200, the events path returns Laravel's 404 page; a real soketi returns JSON). This project has **no soketi** dependency/config — the `PUSHER_*` values were copied from another app that runs its own soketi. Fix applied: switched back to Reverb (`BROADCAST_CONNECTION=reverb` + `php artisan reverb:start`) and removed the duplicate `REVERB_*` block from `.env`. (Alternative not taken: run a real soketi server on a free port with matching `PUSHER_*` creds.)

- **Realtime latency — FIXED (broadcast synchronously).** `RoomChanged`/`MapChanged` were **queued** (`ShouldBroadcast`), so each update waited for a `queue:work` poll before publishing — seconds of lag on the database queue. Changed both to **`ShouldBroadcastNow`** (publish during the request, like the old WordPress app did with Pusher) and cut the client reload debounce 200ms→60ms. Live board updates no longer need `queue:work` (only background jobs do). Trade-off: a mutation can error if Reverb is down when it fires. The remaining per-update cost is the scoped reload — see **A5** for the diff-based follow-up that removes it.

## Cross-cutting concerns

- **Payload size.** Scenes + drawings could bloat the `Rooms/Show` payload. Send only the active scene's objects (B1); consider lazy-loading heavy sheets (already sent per token for GM/owner — watch total with many PCs). Character `sheet` JSON can be ~50–100 KB each.
- **Broadcasting load.** Freehand generates frequent updates — throttle client-side and debounce broadcasts; consider client `.whisper` for ephemeral cursor/draw previews (as WebRTC signalling already does) and persist only on commit.
- **Permissions.** Keep every visibility rule server-side (GM layer, enemy combat state) — the client is UX only. Mirror the existing `seesStats`/`seesHp` gating.
- **Testing.** Each phase ships with feature tests asserting persistence, the realtime event, and every permission boundary — matching the current room test coverage.

---

## Suggested sequencing

1. **A1 fullscreen layout** — immediate, visible, unblocks the tool rail.
2. **A2 drawing + A3 layers + A4 live cursors** — high value, self-contained, confirmed wanted; cursors are cheap and reuse presence.
3. **A5 diff-based realtime** — do alongside A4 (shared mechanism); makes movement/rolls feel native and removes the reload cost.
4. **B1 scenes** — per-scene ownership of tokens/drawings/fog.
5. **E1/E2 sheet depth + concentration** — deepen play.
6. **D1 audio**, then backlog by demand.
