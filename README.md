# Worldbuilder v2 (Laravel + Vue + Tailwind)

A rebuild of the campaign worldbuilder on Laravel 12 + Inertia + Vue 3 + Tailwind, with SQLite for
local dev. Authorization is enforced with **Laravel Policies** (replacing the previous Supabase RLS).

## The four surfaces

| Surface | Route | Notes |
|---|---|---|
| Marketing site | `/` | Landing page + list of public worlds |
| Public campaign reader | `/c/{code}` | Overview, `/s/{section}`, `/e/{document}`, `/chronicle`. Respects visibility; strips `{{secret}}…{{/}}` for players |
| GM workspace (auth) | `/campaigns`, `/campaigns/{id}`, `/documents/{id}/edit` | Owner-only via `CampaignPolicy` / `DocumentPolicy` |
| Platform admin | `/admin` | Gated by `can:access-admin` (`is_admin` flag) |

## Run it locally

```bash
# from D:\Code\worldbuilder-v2  (Herd provides php/composer; node for the frontend)
php artisan migrate:fresh --seed     # SQLite DB + demo data
npm run build                        # or: npm run dev  (hot reload)
php artisan serve                    # http://127.0.0.1:8000
```

Herd users can also just browse the parked site if `D:\Code` is a parked/linked directory.

### Seeded accounts (password: `password`)

- **admin@worldbuilder.test** — platform admin (sees `/admin`)
- **gm@worldbuilder.test** — owns the demo world **Saltmere & the Sundered Coast** (`/c/salt42`)

## Tests

```bash
php artisan test           # feature tests incl. policy + secret-stripping smoke tests
```

## Data model

`campaigns`, `documents`, `campaign_members`, `campaign_invites`, `campaign_attributes`,
`campaign_compendium_items`, `article_notes`, plus `is_admin` on `users`. Models live in
`app/Models`, section/kind config in `app/Support/Sections.php`.

## Built

- The four surfaces (marketing, public reader, GM workspace, admin) + auth
- Data model + **Policies** (owner/member/admin), fully tested
- Document CRUD with autosave; secret-stripping for players
- **Compendium** with structured D&D **stat blocks** (`app/Support/Statblock.php`), live editor + card,
  Open5e-source rebuild
- **Chronicle** timeline (`app/Support/Chronicle.php`) parsing `| Year | Event |` tables
- **Members, invites & email** — join by code, invite-by-email (Laravel Mailable; `log` driver
  locally, SMTP in prod), token-based accept that works for private worlds
- **Import** — Open5e SRD → compendium (`app/Services/Open5eClient.php`, monsters get a parsed stat
  block), and CritterDB homebrew bestiaries → compendium monsters (`app/Support/CritterDb.php`,
  pasted JSON export or a published-bestiary URL)
- **`[statblock=<id>]` embeds** — any entry can embed a compendium monster's stat block; the reader
  resolves + renders it (private monsters never leak to players)
- **Print / PDF** — a print stylesheet isolates a `.printable` region; the compendium editor prints a
  clean stat-block / entry sheet ("Save as PDF" from the browser dialog)
- **Ask Claude** — an AI assist panel in the document editor (`app/Services/AnthropicClient.php`,
  `AiController`); degrades gracefully with a clear message when no key is set

## AI setup

The Ask-Claude panel needs an Anthropic key. Add it to **this project's** `.env`:

```
ANTHROPIC_API_KEY=sk-ant-...
ANTHROPIC_MODEL=claude-sonnet-4-6   # optional
```

Then restart `php artisan serve` (env is read at boot). Without a key, the panel shows a friendly
"AI isn't configured" message instead of failing.
