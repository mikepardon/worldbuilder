<?php

namespace App\Models;

use App\Support\Sections;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A world: the overarching setting — its lore, people, locations, timelines, compendium and maps. This
 * is what's published at /w/{slug}. Actual play happens in the {@see Campaign}s inside it.
 */
class World extends Model
{
    use HasFactory;

    protected $table = 'worlds';

    protected $fillable = [
        'user_id', 'slug', 'name', 'description', 'setting', 'settings', 'visibility', 'is_sandbox', 'cover_media_id',
        'ai_generation_limit', 'ai_generations_used', 'ai_model', 'ddb_enabled', 'ddb_cobalt', 'ddb_campaign_id',
        'knowledge_ingestion_enabled',
        'custom_domain', 'custom_domain_verified_at', 'discord_webhook',
        'logo_media_id', 'banner_media_id', 'favicon_media_id', 'og_media_id',
        'reader_password', 'field_order',
    ];

    protected $casts = [
        'settings' => 'array',
        'field_order' => 'array',
        'ai_generation_limit' => 'integer',
        'ai_generations_used' => 'integer',
        'is_sandbox' => 'boolean',
        'ddb_enabled' => 'boolean',
        'knowledge_ingestion_enabled' => 'boolean',
        // The Cobalt token is sensitive; Laravel encrypts it at rest and decrypts on access.
        'ddb_cobalt' => 'encrypted',
        'custom_domain_verified_at' => 'datetime',
        // Carries a secret token; Laravel encrypts it at rest and decrypts on access.
        'discord_webhook' => 'encrypted',
        'logo_media_id' => 'int',
        'banner_media_id' => 'int',
        'favicon_media_id' => 'int',
        'og_media_id' => 'int',
        // Hashed at rest; verify reader input with Hash::check.
        'reader_password' => 'hashed',
    ];

    /** The visibility a new campaign in this world inherits (owner-set default; falls back to private). */
    public function defaultCampaignVisibility(): string
    {
        $value = data_get($this->settings, 'default_campaign_visibility', 'private');

        return in_array($value, ['private', 'hidden', 'public'], true) ? $value : 'private';
    }

    /** Whether new sessions in this world start GM-only (private) by default. */
    public function newSessionsPrivate(): bool
    {
        return (bool) data_get($this->settings, 'default_session_private', false);
    }

    /** Whether search engines may index this world's public reader. */
    public function readerIndexable(): bool
    {
        return (bool) data_get($this->settings, 'reader_indexable', false);
    }

    /** Whether players see the Compendium in the reader. */
    public function readerShowsCompendium(): bool
    {
        return (bool) data_get($this->settings, 'reader_show_compendium', true);
    }

    /** Whether signed-in players may keep private notes in the reader. */
    public function readerAllowsNotes(): bool
    {
        return (bool) data_get($this->settings, 'reader_allow_notes', true);
    }

    /** The default game system new campaigns inherit (e.g. "5e"), or null if unset. */
    public function defaultGameSystem(): ?string
    {
        $value = data_get($this->settings, 'default_game_system');

        return filled($value) ? (string) $value : null;
    }

    /** The detail level new recaps default to (brief or comprehensive). */
    public function defaultRecapDetail(): string
    {
        return data_get($this->settings, 'default_recap_detail') === 'brief' ? 'brief' : 'comprehensive';
    }

    /** Whether finished recaps are shown to players immediately, or held for GM review. */
    public function recapAutoPublish(): bool
    {
        return (bool) data_get($this->settings, 'recap_auto_publish', true);
    }

    /** The public reader's accent theme. */
    public function readerTheme(): string
    {
        $value = data_get($this->settings, 'reader_theme', 'teal');

        return in_array($value, self::READER_THEMES, true) ? $value : 'teal';
    }

    /** @var list<string> */
    public const READER_THEMES = ['teal', 'amber', 'crimson', 'violet', 'emerald', 'sky', 'rose'];

    /** Suggested game systems offered by the new-world wizard (the GM may also type their own). */
    public const GAME_SYSTEMS = ['D&D 5e', 'Pathfinder 2e', 'Call of Cthulhu', 'Powered by the Apocalypse', 'System-agnostic'];

    /**
     * Features the new-world wizard asks about, recorded as the GM's intent for the world.
     *
     * @var array<string, string>
     */
    public const WIZARD_FEATURES = [
        'campaigns' => 'Campaign manager — sessions, players and recaps',
        'vtt' => 'Virtual tabletop — maps, tokens and dice in the browser',
        'recaps' => 'Post-game AI recaps — summarise each session automatically',
        'reader' => 'Published wiki — a public reader for your world',
    ];

    /**
     * The reader's privacy-analytics tag, if the GM set one. A value starting "G-" is a GA4
     * measurement id; anything else is treated as a Plausible site domain.
     *
     * @return array{provider: 'ga'|'plausible', id: string}|null
     */
    public function readerAnalytics(): ?array
    {
        $value = trim((string) data_get($this->settings, 'reader_analytics', ''));

        if ($value === '') {
            return null;
        }

        return Str::startsWith($value, 'G-')
            ? ['provider' => 'ga', 'id' => $value]
            : ['provider' => 'plausible', 'id' => $value];
    }

    /** The GM's custom footer line for the public reader, or null to use the default credit. */
    public function readerFooter(): ?string
    {
        $value = trim((string) data_get($this->settings, 'reader_footer', ''));

        return $value === '' ? null : $value;
    }

    /** @var list<string> */
    public const READER_FONTS = ['serif', 'sans', 'mono'];

    /** The reader's reading font family (uses already-bundled fonts only). */
    public function readerFont(): string
    {
        $value = data_get($this->settings, 'reader_font', 'serif');

        return in_array($value, self::READER_FONTS, true) ? $value : 'serif';
    }

    /**
     * The reader's custom colours — background, headings and body text — each a validated #rrggbb hex,
     * or null to fall back to the built-in palette. Free choice, so a world can look nothing like the app.
     *
     * @return array{background: string|null, heading: string|null, text: string|null}
     */
    public function readerColours(): array
    {
        return [
            'background' => $this->readerColour('reader_bg'),
            'heading' => $this->readerColour('reader_heading'),
            'text' => $this->readerColour('reader_text'),
        ];
    }

    /** A stored reader colour if it is a well-formed #rrggbb hex; null otherwise (reject by default). */
    private function readerColour(string $key): ?string
    {
        $value = mb_strtolower(trim((string) data_get($this->settings, $key, '')));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /**
     * The GM's custom CSS for the public reader. Stored raw; it is scoped to the reader root and stripped
     * of unsafe constructs at render time (see resources/js/lib/readerCss.js), so it can only restyle the
     * reader, never break out of it or execute.
     */
    public function readerCss(): string
    {
        return trim((string) data_get($this->settings, 'reader_css', ''));
    }

    /** How the public reader home leads: 'full' (hero + start-here cards) or 'minimal'. */
    public function readerHomeLayout(): string
    {
        return data_get($this->settings, 'reader_home_layout') === 'minimal' ? 'minimal' : 'full';
    }

    /**
     * GM-set images for the reader's section "doors", keyed by section slug and stored as media URLs so
     * the reader renders them directly. Unknown slugs and non-string values are dropped (reject by default).
     *
     * @return array<string, string>
     */
    public function sectionImages(): array
    {
        $stored = (array) data_get($this->settings, 'section_images', []);
        $slugs = array_column(Sections::SECTIONS, 'slug');

        $images = [];
        foreach ($stored as $slug => $url) {
            if (in_array($slug, $slugs, true) && is_string($url) && $url !== '') {
                $images[$slug] = $url;
            }
        }

        return $images;
    }

    /** Whether new entries start GM-only (private) by default. */
    public function defaultEntryPrivate(): bool
    {
        return (bool) data_get($this->settings, 'default_entry_private', false);
    }

    /** @var list<string> */
    public const READER_DATE_FORMATS = ['medium', 'long', 'short'];

    /** How reader-facing dates are formatted. */
    public function readerDateFormat(): string
    {
        $value = data_get($this->settings, 'reader_date_format', 'medium');

        return in_array($value, self::READER_DATE_FORMATS, true) ? $value : 'medium';
    }

    /** Whether the reader shows "Session N" numbers on the sessions timeline. */
    public function readerNumbersSessions(): bool
    {
        return (bool) data_get($this->settings, 'reader_number_sessions', false);
    }

    /** The role a player is given when joining a campaign by link (owner-set default; 'player' otherwise). */
    public function defaultJoinRole(): string
    {
        return data_get($this->settings, 'default_join_role') === 'co-gm' ? 'co-gm' : 'player';
    }

    /**
     * Reader nav customisation: section slugs to hide, the custom section order, and extra external links.
     *
     * @return array{hidden: list<string>, order: list<string>, links: list<array{label: string, url: string}>}
     */
    public function readerNav(): array
    {
        $links = collect((array) data_get($this->settings, 'nav_links', []))
            ->map(fn ($link): array => [
                'label' => (string) ($link['label'] ?? ''),
                'url' => (string) ($link['url'] ?? ''),
            ])
            ->filter(fn (array $link): bool => $link['label'] !== '' && $link['url'] !== '')
            ->values()
            ->all();

        return [
            'hidden' => array_values(array_map('strval', (array) data_get($this->settings, 'nav_hidden', []))),
            'order' => array_values(array_map('strval', (array) data_get($this->settings, 'nav_order', []))),
            'links' => $links,
        ];
    }

    /** Whether this world has a custom domain that's been confirmed as pointing at us. */
    public function hasVerifiedDomain(): bool
    {
        return filled($this->custom_domain) && $this->custom_domain_verified_at !== null;
    }

    /** AI generations this world has left before it hits the admin-set limit. */
    public function aiGenerationsRemaining(): int
    {
        return max(0, $this->ai_generation_limit - $this->ai_generations_used);
    }

    public function canUseAi(): bool
    {
        return $this->aiGenerationsRemaining() > 0;
    }

    /** The effective D&D Beyond cobalt token: the world's own, else its owner's personal key. */
    public function ddbCobalt(): ?string
    {
        return $this->ddb_cobalt ?: $this->owner?->ddb_cobalt;
    }

    protected static function booted(): void
    {
        static::creating(function (World $world) {
            if (empty($world->slug)) {
                $owner = User::find($world->user_id);
                $base = trim(Str::slug($world->name).'-'.Str::slug((string) $owner?->name, ''), '-') ?: 'world';
                $slug = $base;
                $n = 2;
                while (static::where('slug', $slug)->exists()) {
                    $slug = $base.'-'.$n++;
                }
                $world->slug = $slug;
            }
        });

        // A world always has at least one campaign to hold its play (rooms, players, sessions).
        static::created(function (World $world) {
            if (! $world->campaigns()->exists()) {
                $world->campaigns()->create(['name' => 'Main Campaign']);
            }
        });
    }

    public function isPublic(): bool
    {
        return in_array($this->visibility, ['public', 'hidden'], true);
    }

    /** Whether a user plays in this world (a member of any campaign within it). */
    public function hasMember(User $user): bool
    {
        return $this->campaigns()
            ->whereHas('members', fn ($query) => $query->where('user_id', $user->id))
            ->exists();
    }

    /** Who runs the GM workspace: its owner or any invited collaborator (co-author or moderator). */
    public function isManagedBy(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->members()->where('user_id', $user->id)->exists();
    }

    /** Who may edit the world's lore (entries, compendium, maps, media): its owner or a co-author. */
    public function canEditContent(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->members()->where('user_id', $user->id)->where('role', 'editor')->exists();
    }

    /** Who may run the world's play (campaigns, sessions, rooms): its owner or a moderator. */
    public function canManagePlay(User $user): bool
    {
        return $this->user_id === $user->id
            || $this->members()->where('user_id', $user->id)->where('role', 'moderator')->exists();
    }

    /** @return HasMany<WorldMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorldMember::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cover(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'cover_media_id');
    }

    public function logo(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'logo_media_id');
    }

    public function banner(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'banner_media_id');
    }

    public function favicon(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'favicon_media_id');
    }

    public function og(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'og_media_id');
    }

    /** The social-share (Open Graph) image, falling back to the hero banner. */
    public function socialImageUrl(): ?string
    {
        return $this->og?->url ?? $this->banner?->url;
    }

    /** The GM's support link (Patreon/Ko-fi/etc.) for the reader, or null. */
    public function supportUrl(): ?string
    {
        $value = trim((string) data_get($this->settings, 'support_url', ''));

        return $value === '' ? null : $value;
    }

    public function supportLabel(): string
    {
        $value = trim((string) data_get($this->settings, 'support_label', ''));

        return $value === '' ? 'Support this world' : $value;
    }

    /** Whether this world's reader is gated behind a password (only meaningful for unlisted worlds). */
    public function hasReaderPassword(): bool
    {
        return filled($this->reader_password);
    }

    /** Whether the given user may bypass the reader password (the owner or any campaign member). */
    public function readerPasswordBypassedBy(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->user_id === $user->id
            || $this->campaigns()->whereHas('members', fn ($query) => $query->where('user_id', $user->id))->exists();
    }

    /** @return HasMany<Campaign, $this> */
    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    /** @return HasMany<Webhook, $this> */
    public function webhooks(): HasMany
    {
        return $this->hasMany(Webhook::class)->chaperone();
    }

    /** @return HasMany<WorldAttribute, $this> */
    public function customFields(): HasMany
    {
        return $this->hasMany(WorldAttribute::class)->chaperone();
    }

    /** @return HasMany<WorldTemplate, $this> */
    public function templates(): HasMany
    {
        return $this->hasMany(WorldTemplate::class)->chaperone();
    }

    /** @return HasMany<RollTable, $this> */
    public function rollTables(): HasMany
    {
        return $this->hasMany(RollTable::class)->chaperone();
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function documentLinks(): HasMany
    {
        return $this->hasMany(DocumentLink::class);
    }

    public function attributes(): HasMany
    {
        return $this->hasMany(CampaignAttribute::class);
    }

    public function compendiumItems(): HasMany
    {
        return $this->hasMany(CampaignCompendiumItem::class);
    }

    public function compendiumImports(): HasMany
    {
        return $this->hasMany(CompendiumImport::class);
    }

    /** @return HasMany<WorldBuild, $this> */
    public function worldBuilds(): HasMany
    {
        return $this->hasMany(WorldBuild::class);
    }

    /** @return HasMany<WorldIngestion, $this> */
    public function worldIngestions(): HasMany
    {
        return $this->hasMany(WorldIngestion::class);
    }

    public function maps(): HasMany
    {
        return $this->hasMany(Map::class);
    }

    /** @return HasMany<Calendar, $this> */
    public function calendars(): HasMany
    {
        return $this->hasMany(Calendar::class)->chaperone();
    }

    /** @return HasMany<GeneratorBatch, $this> */
    public function generatorBatches(): HasMany
    {
        return $this->hasMany(GeneratorBatch::class)->chaperone();
    }
}
