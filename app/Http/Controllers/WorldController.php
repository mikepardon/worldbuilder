<?php

namespace App\Http\Controllers;

use App\Data\DocumentSummaryData;
use App\Models\Campaign;
use App\Models\Document;
use App\Models\Media;
use App\Models\Session;
use App\Models\User;
use App\Models\World;
use App\Support\Connections;
use App\Support\NavMenu;
use App\Support\Sections;
use App\Support\WorldNav;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class WorldController extends Controller
{
    /** The guided "new world" wizard. */
    public function create()
    {
        abort_unless($this->canCreateWorld(Auth::user()), Response::HTTP_FORBIDDEN);

        return Inertia::render('Worlds/Create', [
            'systemOptions' => World::GAME_SYSTEMS,
            'featureOptions' => World::WIZARD_FEATURES,
        ]);
    }

    public function store(Request $request)
    {
        $user = Auth::user();

        // Free accounts may own a single world; paid plans raise the limit. Admins are exempt.
        if (! $this->canCreateWorld($user)) {
            return back()->with('error', "Your plan allows {$user->worldLimit()} world. Upgrade to create more.");
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'visibility' => ['sometimes', 'in:private,hidden,public'],
            // A description of the world's people, places and tone — also the AI's seed context.
            'setting' => ['nullable', 'string', 'max:8000'],
            'audience' => ['sometimes', 'in:solo,players'],
            'features' => ['sometimes', 'array'],
            'features.*' => ['string', Rule::in(array_keys(World::WIZARD_FEATURES))],
            'game_system' => ['nullable', 'string', 'max:60'],
            'house_rules' => ['nullable', 'string', 'max:2000'],
        ]);

        $settings = array_filter([
            'default_game_system' => filled($data['game_system'] ?? null) ? $data['game_system'] : null,
            'house_rules' => filled($data['house_rules'] ?? null) ? $data['house_rules'] : null,
            'audience' => $data['audience'] ?? null,
            'features' => $data['features'] ?? null,
            // Inviting players → new campaign members join as players by default.
            'default_join_role' => ($data['audience'] ?? null) === 'players' ? 'player' : null,
        ], fn ($value) => $value !== null);

        $world = $user->worlds()->create([
            'name' => $data['name'],
            'visibility' => $data['visibility'] ?? 'private',
            'setting' => $data['setting'] ?? null,
            'settings' => $settings,
        ]);

        return redirect()->route('worlds.show', $world);
    }

    private function canCreateWorld(User $user): bool
    {
        return $user->isAdmin() || $user->worlds()->count() < $user->worldLimit();
    }

    /** The GM's home for a world — an at-a-glance dashboard, not a table of everything. */
    public function show(Request $request, World $world)
    {
        $this->authorize('manage', $world);

        $world->load('cover');
        $documents = $world->documents()->get(['id', 'kind', 'is_private', 'title', 'slug', 'updated_at']);

        $campaignIds = $world->campaigns()->pluck('id');
        $sessions = Session::whereIn('campaign_id', $campaignIds)
            ->with(['recap:id,session_id,status', 'campaign:id,name'])
            ->get();
        $latest = $sessions
            ->sortByDesc(fn (Session $session) => $session->held_on?->timestamp ?? $session->created_at?->timestamp ?? 0)
            ->first();

        return Inertia::render('Worlds/Show', [
            'world' => WorldNav::for($world),
            'campaign' => $this->detail($world),
            'sections' => Sections::withCounts($documents),
            'stats' => [
                'entries' => $documents->count(),
                'secrets' => $documents->where('is_private', true)->count(),
                'campaigns' => $campaignIds->count(),
                'sessions' => $sessions->count(),
                'recaps' => $sessions->filter(fn (Session $session) => $session->recap?->status === 'done')->count(),
            ],
            // Onboarding: an empty world offers to generate starter content (from its wizard seed, if any).
            'kickstart' => [
                'show' => $documents->isEmpty(),
                'hasSeed' => filled($world->setting),
                'generatorsUrl' => route('generators.index', $world->id),
            ],
            // Setup checklist: the steps that take a fresh world from empty to ready. Each step reflects
            // real state, so it ticks off as the GM actually does the work — and hides once all are done.
            'setup' => $this->setupChecklist($world, $documents, $sessions->isNotEmpty()),
            // Live status of the "build my world from its seed" background job, if one has been run.
            'build' => $world->worldBuilds()->latest()->first()?->toStatusArray(),
            'recent' => $documents->sortByDesc('updated_at')->take(6)->values()->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'kindLabel' => Sections::kindLabel($document->kind),
                'updated_at' => $document->updated_at?->toIso8601String(),
            ]),
            'lastSession' => $latest === null ? null : [
                'title' => $latest->title,
                'campaign' => $latest->campaign?->name,
                'held_on' => $latest->held_on?->toDateString(),
                'recap_status' => $latest->recap?->status,
                'edit_url' => route('sessions.edit', [$world->id, $latest->campaign_id, $latest->id]),
                'view_url' => route('sessions.view', $latest->id),
            ],
        ]);
    }

    /**
     * The "get your world ready" checklist shown on the dashboard until every step is done. Each step's
     * completion is derived from real world state so it can never drift out of sync with what exists.
     *
     * @param  Collection<int, Document>  $documents
     * @return array{complete: bool, done: int, total: int, steps: list<array{key: string, label: string, hint: string, done: bool, href: string}>}
     */
    private function setupChecklist(World $world, mixed $documents, bool $hasSession): array
    {
        $steps = [
            [
                'key' => 'entry',
                'label' => 'Create your first entry',
                'hint' => 'A location, NPC, or anything that lives in your world.',
                'done' => $documents->isNotEmpty(),
                'href' => route('worlds.entries', $world->id),
            ],
            [
                'key' => 'branding',
                'label' => 'Add your branding',
                'hint' => 'Upload a logo and banner so the reader looks like yours.',
                'done' => filled($world->logo_media_id) || filled($world->banner_media_id),
                'href' => route('worlds.settings', $world->id),
            ],
            [
                'key' => 'session',
                'label' => 'Run your first session',
                'hint' => 'Log a session and turn it into a recap.',
                'done' => $hasSession,
                'href' => route('worlds.sessions', $world->id),
            ],
            [
                'key' => 'compendium',
                'label' => 'Build your compendium',
                'hint' => 'Spells, items, and rules your players can browse.',
                'done' => $world->compendiumItems()->exists(),
                'href' => route('compendium.index', $world->id),
            ],
            [
                'key' => 'invite',
                'label' => 'Invite a collaborator',
                'hint' => 'Add a co-GM or editor to help build the world.',
                'done' => $world->members()->exists(),
                'href' => route('worlds.members.index', $world->id),
            ],
        ];

        $done = collect($steps)->where('done', true)->count();

        return [
            'complete' => $done === count($steps),
            'done' => $done,
            'total' => count($steps),
            'steps' => $steps,
        ];
    }

    /** The full entries table for a world, filterable by section — reached from the section nav tabs. */
    public function entries(Request $request, World $world)
    {
        $this->authorize('manage', $world);

        $documents = $world->documents()->latest('updated_at')->get();

        return Inertia::render('Worlds/Entries', [
            'world' => WorldNav::for($world),
            'section' => $request->query('section', 'all'),
            'campaign' => $this->detail($world),
            'sections' => Sections::withCounts($documents),
            'documents' => $documents->map(fn (Document $document) => DocumentSummaryData::fromModel($document)->toManageRow()),
            'allTags' => $documents->pluck('tags')->flatten()->filter()->unique()->sort()->values()->all(),
            'categories' => Sections::categories(),
        ]);
    }

    /** Force-directed "web" of every entry in the world and the connections between them. */
    public function web(World $world)
    {
        $this->authorize('manage', $world);

        return Inertia::render('Worlds/Web', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name],
            'graph' => Connections::graph($world),
        ]);
    }

    public function update(Request $request, World $world)
    {
        $this->authorize('update', $world);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'setting' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['sometimes', 'in:private,hidden,public'],
            'default_campaign_visibility' => ['sometimes', 'in:private,hidden,public'],
            'default_session_private' => ['sometimes', 'boolean'],
            'reader_indexable' => ['sometimes', 'boolean'],
            'reader_show_compendium' => ['sometimes', 'boolean'],
            'reader_allow_notes' => ['sometimes', 'boolean'],
            'default_game_system' => ['sometimes', 'nullable', 'string', 'max:40'],
            'default_recap_detail' => ['sometimes', 'in:brief,comprehensive'],
            'recap_auto_publish' => ['sometimes', 'boolean'],
            'reader_theme' => ['sometimes', 'in:'.implode(',', World::READER_THEMES)],
            'reader_analytics' => ['sometimes', 'nullable', 'string', 'max:120'],
            'reader_footer' => ['sometimes', 'nullable', 'string', 'max:200'],
            'reader_font' => ['sometimes', 'in:'.implode(',', World::READER_FONTS)],
            // Free-choice reader colours (reader-only theming); empty clears back to the default palette.
            'reader_bg' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'reader_heading' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'reader_text' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            // Freeform reader CSS — sanitised and scoped to the reader root at render time.
            'reader_css' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'reader_home_layout' => ['sometimes', 'in:full,minimal'],
            'default_entry_private' => ['sometimes', 'boolean'],
            'reader_date_format' => ['sometimes', 'in:'.implode(',', World::READER_DATE_FORMATS)],
            'reader_number_sessions' => ['sometimes', 'boolean'],
            'default_join_role' => ['sometimes', 'in:player,co-gm'],
            'nav_hidden' => ['sometimes', 'array'],
            'nav_hidden.*' => ['string', 'max:40'],
            'nav_order' => ['sometimes', 'array'],
            'nav_order.*' => ['string', 'max:40'],
            'nav_links' => ['sometimes', 'array', 'max:8'],
            'nav_links.*.label' => ['required', 'string', 'max:40'],
            'nav_links.*.url' => ['required', 'url', 'max:255'],
            // The reader nav menu tree (WordPress-style). Deep-validated + sanitised via NavMenu below,
            // since arbitrary-depth trees can't be expressed with flat array rules.
            'nav_menu' => ['sometimes', 'array'],
            'support_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'support_label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'discord_webhook' => ['sometimes', 'nullable', 'url', 'max:255', 'starts_with:https://discord.com/api/webhooks/,https://discordapp.com/api/webhooks/'],
            'reader_password' => ['sometimes', 'nullable', 'string', 'min:4', 'max:72'],
        ]);

        // Column fields are assigned explicitly; the rest are folded into the settings bag.
        $world->fill(Arr::only($data, ['name', 'description', 'setting', 'visibility']));

        // A blank webhook clears it; anything else is stored (encrypted) on the column.
        if (array_key_exists('discord_webhook', $data)) {
            $world->discord_webhook = blank($data['discord_webhook']) ? null : $data['discord_webhook'];
        }

        // A blank reader password clears the gate; anything else is stored (hashed) on the column.
        if (array_key_exists('reader_password', $data)) {
            $world->reader_password = blank($data['reader_password']) ? null : $data['reader_password'];
        }

        $settings = $world->settings ?? [];
        $settingKeys = [
            'default_campaign_visibility', 'default_session_private',
            'reader_indexable', 'reader_show_compendium', 'reader_allow_notes',
            'default_game_system', 'default_recap_detail', 'recap_auto_publish', 'reader_theme',
            'reader_analytics', 'reader_footer', 'reader_font', 'reader_home_layout', 'default_entry_private',
            'reader_bg', 'reader_heading', 'reader_text', 'reader_css',
            'support_url', 'support_label', 'reader_date_format', 'reader_number_sessions', 'default_join_role',
            'nav_hidden', 'nav_order', 'nav_links',
        ];
        foreach ($settingKeys as $key) {
            if (array_key_exists($key, $data)) {
                $settings[$key] = $data[$key];
            }
        }

        // The nav menu is a tree — sanitise it (reject-by-default) rather than storing raw.
        if (array_key_exists('nav_menu', $data)) {
            $settings['nav_menu'] = NavMenu::sanitise($data['nav_menu']);
        }

        $world->settings = $settings;

        $world->save();

        return back();
    }

    /** Consolidated world settings hub, opened from the world nav's Settings menu. */
    public function settings(Request $request, World $world)
    {
        $this->authorize('update', $world);

        return Inertia::render('Worlds/Settings', [
            'world' => WorldNav::for($world),
            'settings' => [
                'name' => $world->name,
                'description' => $world->description,
                'setting' => $world->setting,
                'visibility' => $world->visibility,
                'public_url' => url("/w/{$world->slug}"),
                'default_campaign_visibility' => $world->defaultCampaignVisibility(),
                'default_session_private' => $world->newSessionsPrivate(),
                'reader_indexable' => $world->readerIndexable(),
                'reader_show_compendium' => $world->readerShowsCompendium(),
                'reader_allow_notes' => $world->readerAllowsNotes(),
                'default_game_system' => $world->defaultGameSystem(),
                'default_recap_detail' => $world->defaultRecapDetail(),
                'recap_auto_publish' => $world->recapAutoPublish(),
                'reader_theme' => $world->readerTheme(),
                'reader_analytics' => (string) data_get($world->settings, 'reader_analytics', ''),
                // Whether a webhook is configured — the secret URL itself is never sent to the browser.
                'discord_connected' => filled($world->discord_webhook),
                // Whether a reader password is set — never send the hash.
                'reader_password_set' => $world->hasReaderPassword(),
                'logo_url' => $world->logo?->url,
                'banner_url' => $world->banner?->url,
                'favicon_url' => $world->favicon?->url,
                'og_url' => $world->og?->url,
                // GM-set "door" images for the reader's section cards, keyed by section slug.
                'section_images' => $world->sectionImages(),
                'support_url' => (string) data_get($world->settings, 'support_url', ''),
                'support_label' => (string) data_get($world->settings, 'support_label', ''),
                'reader_footer' => (string) data_get($world->settings, 'reader_footer', ''),
                'reader_font' => $world->readerFont(),
                'reader_bg' => $world->readerColours()['background'] ?? '',
                'reader_heading' => $world->readerColours()['heading'] ?? '',
                'reader_text' => $world->readerColours()['text'] ?? '',
                'reader_css' => $world->readerCss(),
                'reader_home_layout' => $world->readerHomeLayout(),
                'default_entry_private' => $world->defaultEntryPrivate(),
                'reader_date_format' => $world->readerDateFormat(),
                'reader_number_sessions' => $world->readerNumbersSessions(),
                'default_join_role' => $world->defaultJoinRole(),
                'nav' => $world->readerNav(),
                'ai_model' => $world->ai_model,
            ],
            // Only the sections that actually appear in the reader (have entries), so the nav editor
            // mirrors the live nav rather than listing empty section types the reader never shows.
            'sectionCatalogue' => collect(Sections::withCounts($world->documents()->get(['id', 'kind', 'is_private'])))
                ->map(fn (array $section): array => ['slug' => $section['slug'], 'label' => $section['label']])
                ->values(),
            // The current nav menu tree (or the default), plus everything the "Add item" palette can offer.
            'navMenu' => $world->readerMenuOrDefault(),
            'navPalette' => [
                'pages' => collect(NavMenu::PAGES)->map(fn (string $label, string $target): array => [
                    'target' => $target, 'label' => $label,
                ])->values(),
                'sections' => collect(Sections::SECTIONS)
                    ->map(fn (array $section): array => ['slug' => $section['slug'], 'label' => $section['label']])
                    ->values(),
                'campaigns' => $world->campaigns
                    ->map(fn (Campaign $campaign): array => ['slug' => $campaign->slug, 'name' => $campaign->name])
                    ->values(),
                'entries' => $world->documents()->orderBy('title')->get(['id', 'kind', 'slug', 'title'])
                    ->map(fn (Document $document): array => [
                        'id' => $document->id,
                        'title' => $document->title,
                        'kind' => $document->kind,
                        'kindLabel' => Sections::kindLabel($document->kind),
                        'typeSlug' => Sections::typeSlug($document->kind),
                        'slug' => $document->slug,
                    ]),
            ],
            'isOwner' => $world->user_id === $request->user()->id,
            'links' => [
                'collaborators' => route('worlds.members.index', $world->id),
                'domain' => route('worlds.domain.edit', $world->id),
                'media' => route('media.index', $world->id),
                'webhooks' => route('worlds.webhooks.index', $world->id),
                'attributes' => route('worlds.attributes.index', $world->id),
                'templates' => route('worlds.templates.index', $world->id),
            ],
            'domain' => [
                'custom_domain' => $world->custom_domain,
                'verified' => $world->hasVerifiedDomain(),
            ],
        ]);
    }

    public function destroy(World $world)
    {
        $this->authorize('delete', $world);
        $world->delete();

        return redirect()->route('dashboard');
    }

    /** Set (or clear) the world's cover image from a media item. */
    public function cover(Request $request, World $world)
    {
        $this->authorize('update', $world);

        $data = $request->validate([
            'media_id' => ['nullable', 'exists:media,id'],
        ]);

        $world->update(['cover_media_id' => $data['media_id'] ?? null]);

        return back()->with('success', 'World cover updated.');
    }

    /**
     * Upload a reader-branding image (the header logo or the hero banner) and point the world at it.
     * The file is stored as a normal media item so it counts toward the account's storage quota.
     */
    public function branding(Request $request, World $world)
    {
        $this->authorize('update', $world);

        $data = $request->validate([
            'type' => ['required', 'in:logo,banner,favicon,og'],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('media.accept')), 'max:'.config('media.max_size')],
        ]);

        $user = $request->user();
        $file = $request->file('file');

        if (! $user->canStore((int) $file->getSize())) {
            return back()->withErrors(['file' => 'Storage limit reached — free up space or upgrade your plan for more.']);
        }

        $disk = config('media.disk');
        $path = $file->store('media', $disk);

        $media = Media::create([
            'user_id' => $user->id,
            'world_id' => $world->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        // 'type' is whitelisted to logo|banner, so the column name is not user-controlled.
        $world->update(["{$data['type']}_media_id" => $media->id]);

        return back();
    }

    /** Remove a reader-branding image (the media item itself is left in the library). */
    public function clearBranding(Request $request, World $world)
    {
        $this->authorize('update', $world);

        $data = $request->validate(['type' => ['required', 'in:logo,banner,favicon,og']]);

        $world->update(["{$data['type']}_media_id" => null]);

        return back();
    }

    /**
     * Set a reader section-"door" image. Stored like branding (a media item that counts toward the
     * account's quota), but recorded as a URL in the world's settings bag keyed by section slug, since
     * sections are a fixed catalogue rather than dedicated columns.
     */
    public function sectionImage(Request $request, World $world)
    {
        $this->authorize('update', $world);

        $data = $request->validate([
            'section' => ['required', 'string', Rule::in(array_column(Sections::SECTIONS, 'slug'))],
            'file' => ['required', 'file', 'mimes:'.implode(',', config('media.accept')), 'max:'.config('media.max_size')],
        ]);

        $user = $request->user();
        $file = $request->file('file');

        if (! $user->canStore((int) $file->getSize())) {
            return back()->withErrors(['file' => 'Storage limit reached — free up space or upgrade your plan for more.']);
        }

        $disk = config('media.disk');
        $path = $file->store('media', $disk);

        // Two writes — the media row and the world's settings — so they commit together or not at all.
        DB::transaction(function () use ($user, $world, $disk, $path, $file, $data): void {
            $media = Media::create([
                'user_id' => $user->id,
                'world_id' => $world->id,
                'disk' => $disk,
                'path' => $path,
                'filename' => $file->getClientOriginalName(),
                'mime' => $file->getClientMimeType(),
                'size' => $file->getSize(),
            ]);

            $settings = $world->settings ?? [];
            $images = (array) data_get($settings, 'section_images', []);
            $images[$data['section']] = $media->url;
            $settings['section_images'] = $images;
            $world->settings = $settings;
            $world->save();
        });

        return back();
    }

    /** Remove a reader section-door image (the media item itself is left in the library). */
    public function clearSectionImage(Request $request, World $world)
    {
        $this->authorize('update', $world);

        $data = $request->validate([
            'section' => ['required', 'string', Rule::in(array_column(Sections::SECTIONS, 'slug'))],
        ]);

        $settings = $world->settings ?? [];
        $images = (array) data_get($settings, 'section_images', []);
        unset($images[$data['section']]);
        $settings['section_images'] = $images;
        $world->settings = $settings;
        $world->save();

        return back();
    }

    protected function card(World $world): array
    {
        return [
            'id' => $world->id,
            'code' => $world->code, 'slug' => $world->slug,
            'name' => $world->name,
            'description' => $world->description,
            'visibility' => $world->visibility,
            'cover_media_id' => $world->cover_media_id,
            'cover_url' => $world->cover?->url,
            'updated_at' => $world->updated_at?->toIso8601String(),
        ];
    }

    protected function detail(World $world): array
    {
        return [
            ...$this->card($world),
            'setting' => $world->setting,
            'public_url' => url("/w/{$world->slug}"),
        ];
    }
}
