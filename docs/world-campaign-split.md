# World / Campaign split

Separates the single `Campaign` model into a **World** (the setting: lore, people, locations,
timelines, compendium, maps — published at `/w/{slug}`) and one or more **Campaigns** inside it (a
playthrough: players, sessions, battle rooms, characters).

## Locked decisions

1. Rename `campaigns` → `worlds`; add a child `campaigns` table. Each existing world auto-gets one
   **Main Campaign** that adopts its current rooms / players / characters / sessions.
2. Sessions become a dedicated `sessions` table (campaign-scoped), migrated from `documents(kind=session)`.
3. Moved to the **Campaign** layer: players & invites, battle rooms, characters, sessions. Kept on the
   **World**: documents (lore/people/locations/timelines/articles/data/quests), compendium, maps.

## Target schema

- **worlds** (renamed from `campaigns`): unchanged columns — `user_id` (owner), `slug`, `name`,
  `description`, `setting`, `visibility`, `is_sandbox`, `cover_media_id`, `ai_*`, `ddb_*`. Drop `code`
  (join codes move to campaigns).
- **campaigns** (new): `world_id`, `name`, `slug` (unique per world), `code` (join), `description`
  (nullable), timestamps. A world has many campaigns.
- **sessions** (new): `campaign_id`, `title`, `slug`, `summary` (nullable), `body` (markdown, nullable),
  `held_on` (date, nullable), `sort`, `is_private`, timestamps.

### FK changes

- World-owned tables — rename FK `campaign_id` → `world_id`: `documents`, `campaign_compendium_items`,
  `maps`, `campaign_attributes`, `compendium_imports`.
  - _Scope compromise:_ keep the existing table names and model class names (`CampaignCompendiumItem`,
    `CampaignAttribute`) to bound churn; only the column + relations change. Can be renamed later.
- Campaign-owned tables — `campaign_id` now references the new `campaigns` table: `rooms`, `characters`,
  `campaign_members`, `campaign_invites`.

### Data migration (per existing world)

Create `Campaign{ world_id, name: 'Main Campaign', slug: 'main', code: <old world code> }`, then repoint
`rooms/characters/campaign_members/campaign_invites.campaign_id` to it, and move
`documents(kind=session)` into `sessions` (title/summary/body/sort), deleting those documents.

## Models

- `Campaign` → **`World`** (table `worlds`). Relations: documents, compendiumItems, maps, attributes,
  compendiumImports, **campaigns**. Keeps `isPublic()`, slug boot hook, ai/ddb helpers.
- **`Campaign`** (new): belongsTo World; hasMany members, invites, rooms, characters, sessions; `code`
  boot hook; `hasMember()`.
- **`Session`** (new): belongsTo Campaign.
- Update FK/casts + relations on Document, Map, CampaignCompendiumItem, CampaignAttribute,
  CompendiumImport (`world()`), and Room, Character, CampaignMember, CampaignInvite (`campaign()` → new).
- `User`: `worlds()` (owned worlds) + keep `memberships()` (now campaign members).

## Policies

- `CampaignPolicy` → **`WorldPolicy`** (manage = owner or admin).
- **`CampaignPolicy`** (new): manage = can manage the parent world.

## Backend (controllers + routes)

- `CampaignController` → **`WorldController`**: `worlds.*` (index/store/show/update/destroy/cover/web).
  `/campaigns/*` → `/worlds/*`; param `{campaign}` → `{world}`.
- **`CampaignController`** (new): campaigns within a world — index/store/show (campaign dashboard:
  players, sessions, rooms)/update/destroy, nested `/worlds/{world}/campaigns/...`.
- **`SessionController`** (new): CRUD under a campaign.
- Move players (MembersController), characters, rooms **under a campaign** (`/campaigns/{campaign}/...`).
- Public reader stays `/w/{world:slug}` (World). Rebind `PublicCampaignController` to World.
- `SandboxController::clone` clones a **World** (+ a starter "Main Campaign").

## Frontend

- `Pages/Campaigns/*` → `Pages/Worlds/*` (world list + workspace). New `Pages/Campaigns/*` (campaign
  dashboard) + `Pages/Sessions/*`. Update `WorldLayout`/`WorldNav`, `Dashboard`, reader nav, and every
  `route('campaigns.*')` / `/campaigns/` reference.
- Creation flow resolved here: **Dashboard** offers _Create New World_ (blank) and _Create from Seed_
  (clone); a **World** workspace offers _New Campaign_; a **Campaign** owns its players/sessions/rooms.

## Phases (suite is red until each backend/frontend phase lands)

1. **Schema + models + policies + data migration.**
2. **Backend:** WorldController + CampaignController + SessionController; move rooms/players/characters
   under campaign; rebind reader + sandbox clone.
3. **Frontend:** rename/înew pages, nav, route calls; creation flow.
4. **Seeder + sandbox clone** updated (world + Main Campaign + sessions).
5. **Tests** rebuilt/renamed to the two-layer model, green.

Effort: ~a day. Recommend doing it on a dedicated branch.
