<?php

namespace App\Http\Controllers;

use App\Data\DocumentSummaryData;
use App\Models\ArticleNote;
use App\Models\Campaign;
use App\Models\CampaignCompendiumItem;
use App\Models\Character;
use App\Models\Document;
use App\Models\Map;
use App\Models\MapPin;
use App\Models\RecapEntity;
use App\Models\ScheduleEvent;
use App\Models\ScheduleEventResponse;
use App\Models\Session;
use App\Models\SessionNote;
use App\Models\World;
use App\Models\WorldBlock;
use App\Models\WorldTemplate;
use App\Queries\DocumentQuery;
use App\Support\Bloodline;
use App\Support\Chronicle;
use App\Support\Compendium;
use App\Support\Connections;
use App\Support\DocFields;
use App\Support\EntryRefs;
use App\Support\Facts;
use App\Support\NavMenu;
use App\Support\Secrets;
use App\Support\Sections;
use App\Support\TemplateBlocks;
use App\Support\Viewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class PublicWorldController extends Controller
{
    public function __construct(private readonly DocumentQuery $documents) {}

    /** World overview at /w/{world-slug}. */
    public function show(World $world)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        $documents = $this->documents->visibleTo($world, $viewer);

        // The GM's home-page template, as a block list — the reader renders it block-by-block when set,
        // and falls back to the built-in home layout otherwise.
        $homeTemplate = $world->templates()->where('target', 'home')->first();

        // Playable campaigns this viewer may list — feeds the home page's "campaign spotlight" and
        // "session recaps" blocks. Only computed for a block-driven home (the default layout has neither).
        $listedCampaigns = $homeTemplate === null
            ? collect()
            : $world->campaigns()->orderBy('name')->get()
                ->filter(fn (Campaign $campaign) => $this->campaignIsListed($campaign, $viewer))
                ->values();

        $homeBlocks = $homeTemplate === null ? [] : TemplateBlocks::normalise($homeTemplate->layout, '', 'home');

        return Inertia::render('Public/Campaign', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($documents, $world->sectionImages()),
            'viewer' => $this->viewerProps($world, $viewer),
            'recent' => $documents->sortByDesc('updated_at')->take(9)->values()->map(fn (Document $d) => $this->card($d)),
            // The pool an "Entries" block filters/sorts client-side (by kind, recency, name). Capped and
            // pre-sorted by recency so recency views stay correct on large worlds; only built for a
            // block-driven home, since the default layout uses `recent` above.
            'entries' => $homeTemplate === null
                ? []
                : $documents->sortByDesc('updated_at')->take(250)->values()->map(fn (Document $d) => $this->card($d)),
            // GM-pinned highlights, shown above the "start here" cards.
            'featured' => $documents->filter(fn (Document $d) => $d->is_featured)
                ->sortByDesc('updated_at')->take(6)->values()->map(fn (Document $d) => $this->card($d)),
            'campaigns' => $listedCampaigns->map(fn (Campaign $campaign): array => [
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'description' => $campaign->description,
                'session_count' => $this->visibleSessions($campaign, $viewer)->count(),
            ]),
            'recaps' => $this->homeRecaps($world, $listedCampaigns, $viewer),
            'nextSession' => $this->homeNextSession($world, $listedCampaigns),
            'blocks' => $homeBlocks,
            'reusableBlocks' => $this->resolveReusableBlocks($world, $homeBlocks),
        ]);
    }

    /**
     * A machine-readable llms.txt for a published world at /w/{world-slug}/llms.txt — the world's
     * name, blurb and a link to every publicly-visible entry, grouped by section. Always scoped to
     * the guest view, so private entries never appear regardless of who fetches it.
     */
    public function llms(World $world)
    {
        // Only published (public/unlisted) worlds are discoverable; private ones 404 like the reader.
        abort_unless(in_array($world->visibility, ['public', 'hidden'], true), Response::HTTP_NOT_FOUND);

        $viewer = Viewer::for($world, null, false);
        $documents = $this->documents->visibleTo($world, $viewer);
        $base = url("/w/{$world->slug}");

        $lines = ["# {$world->name}", ''];

        $blurb = Str::squish((string) ($world->description ?: $world->setting));
        if ($blurb !== '') {
            $lines[] = "> {$blurb}";
            $lines[] = '';
        }
        $lines[] = "Public reader: {$base}";
        $lines[] = '';

        foreach (Sections::SECTIONS as $section) {
            $items = $documents->filter(fn (Document $document) => in_array($document->kind, $section['kinds'], true))->values();
            if ($items->isEmpty()) {
                continue;
            }

            $lines[] = "## {$section['label']}";
            foreach ($items as $document) {
                $url = "{$base}/".Sections::typeSlug($document->kind)."/{$document->slug}";
                $note = Str::squish((string) $document->summary);
                $suffix = $note === '' ? '' : ': '.Str::limit($note, 160);
                $lines[] = "- [{$document->title}]({$url}){$suffix}";
            }
            $lines[] = '';
        }

        $campaigns = $world->campaigns->filter(fn (Campaign $campaign) => $this->campaignIsListed($campaign, $viewer))->values();
        if ($campaigns->isNotEmpty()) {
            $lines[] = '## Campaigns';
            foreach ($campaigns as $campaign) {
                $lines[] = "- [{$campaign->name}]({$base}/campaigns/{$campaign->slug})";
            }
            $lines[] = '';
        }

        return response(implode("\n", $lines), 200)
            ->header('Content-Type', 'text/markdown; charset=UTF-8');
    }

    /** An Atom feed of a published world's recently-updated public entries at /w/{world-slug}/feed. */
    public function feed(World $world)
    {
        abort_unless(in_array($world->visibility, ['public', 'hidden'], true), Response::HTTP_NOT_FOUND);

        $viewer = Viewer::for($world, null, false);
        $entries = $this->documents->visibleTo($world, $viewer)
            ->sortByDesc('updated_at')->take(30)->values();

        $base = url("/w/{$world->slug}");
        $self = url("/w/{$world->slug}/feed");
        $updated = ($entries->first()->updated_at ?? now())->toIso8601String();

        $xml = ['<?xml version="1.0" encoding="UTF-8"?>'];
        $xml[] = '<feed xmlns="http://www.w3.org/2005/Atom">';
        $xml[] = '  <title>'.$this->xml($world->name).'</title>';
        if (filled($world->description)) {
            $xml[] = '  <subtitle>'.$this->xml((string) $world->description).'</subtitle>';
        }
        $xml[] = '  <link href="'.$this->xml($base).'"/>';
        $xml[] = '  <link rel="self" href="'.$this->xml($self).'"/>';
        $xml[] = '  <id>'.$this->xml($base).'</id>';
        $xml[] = '  <updated>'.$updated.'</updated>';

        foreach ($entries as $entry) {
            $link = "{$base}/".Sections::typeSlug($entry->kind)."/{$entry->slug}";
            $xml[] = '  <entry>';
            $xml[] = '    <title>'.$this->xml($entry->title).'</title>';
            $xml[] = '    <link href="'.$this->xml($link).'"/>';
            $xml[] = '    <id>'.$this->xml($link).'</id>';
            $xml[] = '    <updated>'.($entry->updated_at ?? now())->toIso8601String().'</updated>';
            $xml[] = '    <category term="'.$this->xml(Sections::kindLabel($entry->kind)).'"/>';
            if (filled($entry->summary)) {
                $xml[] = '    <summary>'.$this->xml((string) $entry->summary).'</summary>';
            }
            $xml[] = '  </entry>';
        }
        $xml[] = '</feed>';

        return response(implode("\n", $xml), 200)
            ->header('Content-Type', 'application/atom+xml; charset=UTF-8');
    }

    /** An iCalendar feed of a campaign's play schedule at /w/{world}/campaigns/{campaign}/schedule.ics. */
    public function campaignIcs(World $world, Campaign $campaign)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($this->canViewCampaign($campaign, $viewer), Response::HTTP_NOT_FOUND);

        $host = request()->getHost();
        $scheduleUrl = url("/w/{$world->slug}/campaigns/{$campaign->slug}/schedule");
        $events = $campaign->scheduleEvents()->orderBy('starts_at')->get();

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Worldbuilder//Schedule//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->ics($campaign->name),
        ];

        foreach ($events as $event) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = "UID:schedule-{$event->id}@{$host}";
            $lines[] = 'DTSTAMP:'.($event->created_at ?? now())->utc()->format('Ymd\THis\Z');
            $lines[] = 'DTSTART:'.$event->starts_at->utc()->format('Ymd\THis\Z');
            $lines[] = 'SUMMARY:'.$this->ics($event->title);
            if (filled($event->notes)) {
                $lines[] = 'DESCRIPTION:'.$this->ics(Str::limit((string) $event->notes, 300));
            }
            $lines[] = 'URL:'.$this->ics($scheduleUrl);
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", $lines)."\r\n", 200)
            ->header('Content-Type', 'text/calendar; charset=UTF-8');
    }

    /** Escape a value for XML text/attribute content. */
    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    /** Escape a value for an iCalendar text field (RFC 5545). */
    private function ics(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n'], $value);
    }

    /**
     * Redirect to a random visible entry, optionally filtered to a set of kinds (?kinds=location,npc).
     * Powers the "Random entry" template block.
     */
    public function random(World $world)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);

        $kinds = array_values(array_filter(explode(',', (string) request()->query('kinds', ''))));
        $documents = $this->documents->visibleTo($world, $viewer)
            ->when($kinds !== [], fn ($items) => $items->filter(fn (Document $d) => in_array($d->kind, $kinds, true)));

        if ($documents->isEmpty()) {
            return redirect()->to(url("/w/{$world->slug}"));
        }

        $document = $documents->random();

        return redirect()->to(url("/w/{$world->slug}/".Sections::typeSlug($document->kind)."/{$document->slug}"));
    }

    /** A section listing at /w/{world-slug}/s/{section}. */
    public function section(World $world, string $section)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        $def = collect(Sections::SECTIONS)->firstWhere('slug', $section);
        abort_if(! $def, Response::HTTP_NOT_FOUND);

        $documents = $this->documents->visibleTo($world, $viewer);

        // The GM's archive template for this section, if any — the reader renders it block-by-block.
        $archive = $world->templates()->where('target', 'archive')->where('section', $section)->first();
        $archiveBlocks = $archive === null ? [] : TemplateBlocks::normalise($archive->layout, '', 'archive');

        // A "table" block can add columns from the section's structured fields — resolve just those field
        // values for each card, plus their labels for the headers.
        $fieldKeys = TemplateBlocks::fieldColumnKeys($archiveBlocks);
        $fieldLabels = $fieldKeys === []
            ? []
            : collect($def['kinds'])
                ->flatMap(fn (string $kind): array => DocFields::for($kind, $world))
                ->mapWithKeys(fn (array $field): array => [$field['key'] => $field['label']])
                ->only($fieldKeys)->all();

        $items = $documents
            ->filter(fn (Document $d) => in_array($d->kind, $def['kinds'], true))
            ->values()
            ->map(function (Document $d) use ($fieldKeys, $documents): array {
                $card = $this->card($d);
                if ($fieldKeys !== []) {
                    $card['fields'] = collect(Facts::for($d, $documents, $fieldKeys))
                        ->mapWithKeys(fn (array $fact): array => [$fact['key'] => $fact['value']])
                        ->all();
                }

                return $card;
            });

        return Inertia::render('Public/Section', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($documents),
            'viewer' => $this->viewerProps($world, $viewer),
            'section' => $def,
            'items' => $items,
            'fieldLabels' => $fieldLabels,
            'blocks' => $archiveBlocks,
            'reusableBlocks' => $this->resolveReusableBlocks($world, $archiveBlocks),
        ]);
    }

    /** An entry at /w/{world-slug}/{type}/{entry-slug}. */
    public function article(World $world, string $type, string $slug)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);

        $kind = Sections::kindForTypeSlug($type);
        abort_unless($kind, Response::HTTP_NOT_FOUND);
        $document = $world->documents()->where('kind', $kind)->where('slug', $slug)->first();

        // A renamed entry keeps its old slugs as aliases: redirect them to the canonical URL.
        if ($document === null) {
            $aliased = $world->documents()->where('kind', $kind)->whereJsonContains('slug_aliases', $slug)->first();
            abort_if($aliased === null, Response::HTTP_NOT_FOUND);

            return redirect()->to(url("/w/{$world->slug}/{$type}/{$aliased->slug}"), Response::HTTP_MOVED_PERMANENTLY);
        }

        // A valid share token grants direct access to this entry — even a GM-only one — for anyone.
        $hasShareToken = filled($document->share_token)
            && hash_equals((string) $document->share_token, (string) request('key'));
        $privileged = $viewer->seesPrivate();

        // GM-only, or still scheduled for a future publish date — hidden from players.
        abort_if(($document->is_private || $document->isScheduled()) && ! $privileged && ! $hasShareToken, Response::HTTP_NOT_FOUND);

        // Password gate: non-privileged visitors without a share token must unlock a protected entry.
        if (! $privileged && ! $hasShareToken && filled($document->access_password)
            && ! session()->get("doc_unlocked.{$document->id}", false)) {
            return $this->lockedArticle($world, $viewer, $document);
        }

        $def = Sections::sectionForKind($document->kind);
        $documents = $this->documents->visibleTo($world, $viewer);
        $siblings = $documents
            ->filter(fn (Document $d) => $d->id !== $document->id && $def && in_array($d->kind, $def['kinds'], true))
            ->values()
            ->map(fn (Document $d) => ['title' => $d->title, 'type' => Sections::typeSlug($d->kind), 'slug' => $d->slug]);

        // GM keeps secrets (rendered as GM-only boxes client-side); players get them stripped.
        $content = $viewer->seesPrivate() ? ($document->content ?? '') : Secrets::strip($document->content);

        // Rough reading stats from the plain text (multibyte-safe word split).
        $plainContent = trim(strip_tags($content));
        $wordCount = $plainContent === '' ? 0 : count(preg_split('/\s+/u', $plainContent) ?: []);

        // The entry's template: the one it picked, or the default for its kind (entries needn't be styled
        // one by one). As a block list — its reference blocks pull in extra compendium embeds beyond those
        // in the content, and its "linked entries" blocks resolve to cards (scoped to what this viewer sees).
        $effectiveTemplate = $document->template ?? $world->templates()
            ->where('target', 'entry')->where('kind', $document->kind)->where('is_default', true)->first();
        $templateBlocks = $effectiveTemplate !== null
            ? TemplateBlocks::normalise($effectiveTemplate->layout, $document->kind)
            : [];
        // The template's right-hand sidebar: its own (flat, common) blocks, and whether the sidebar is
        // hidden altogether (content then runs full-width).
        $sidebarBlocks = $effectiveTemplate !== null
            ? TemplateBlocks::sanitise((array) ($effectiveTemplate->layout['sidebar'] ?? []), true, 'sidebar')
            : [];
        $hideSidebar = (bool) ($effectiveTemplate->layout['hideSidebar'] ?? false);
        $linkedCards = $documents->whereIn('id', TemplateBlocks::linkedEntryIds($templateBlocks))
            ->mapWithKeys(fn (Document $d): array => [$d->id => $this->card($d)])
            ->all();

        // The template's "map" blocks resolve to full MapViewer payloads, keyed by map id and scoped to
        // what this viewer may see (private maps drop out).
        $mapBlocks = $world->maps()->visibleTo($viewer)
            ->whereIn('id', TemplateBlocks::mapIds($templateBlocks))->get()
            ->mapWithKeys(fn (Map $map): array => [$map->id => $this->readerMapPayload($world, $map, $viewer)])
            ->all();

        // "Reusable" blocks resolve to their stored block set (kept current, so editing the set updates
        // every entry that references it), keyed by id.
        $reusableBlocks = $this->resolveReusableBlocks($world, $templateBlocks);

        // Resolve every compendium embed ({{monster=id}}, {{spell=id}}, {{item=id}}, …) in the content
        // plus any referenced by the template's reference blocks, scoped to what this viewer may see.
        $embedItems = $world->compendiumItems()->with('image')
            ->whereIn('id', array_values(array_unique([
                ...EntryRefs::embedIds($content),
                ...TemplateBlocks::referenceIds($templateBlocks),
            ])))
            ->visibleTo($viewer)
            ->get();

        $statblocks = $embedItems
            ->where('item_type', 'monster')
            ->map(fn ($i) => ['id' => $i->id, 'name' => $i->name, 'block' => data_get($i->fields, 'block'), 'image_url' => $i->image?->url])
            ->filter(fn ($s) => $s['block'])
            ->values();

        $embeds = $embedItems->map(fn ($i) => [
            'id' => $i->id,
            'name' => $i->name,
            'item_type' => $i->item_type,
            'typeLabel' => Compendium::label($i->item_type),
            'summary' => $i->summary,
            'block' => $i->item_type === 'monster' ? data_get($i->fields, 'block') : null,
            'image_url' => $i->image?->url,
            'document' => $i->item_type === 'monster' ? null : $i->document,
        ])->values();

        // Spells (id/name/summary) so a monster's spellcasting entries can hover-preview their spell.
        $spells = $world->compendiumItems()->where('item_type', 'spell')->visibleTo($viewer)
            ->get(['id', 'name', 'summary', 'document', 'fields'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'summary' => $s->summary, 'document' => $s->document, 'fields' => $s->fields ?? []])
            ->values();

        // The connections graph (computed once): drives both the "My connections" jump and the
        // "Connections" template block's list of this entry's neighbours.
        $graph = Connections::graph($world, $viewer);
        $connectionNode = collect($graph['nodes'])->firstWhere('id', $document->id);
        $hasConnections = ($connectionNode['degree'] ?? 0) > 0;
        $connections = Connections::neighbours($graph, $document->id);

        // A "comparison" block lines a few entries up by their quick facts — resolve each chosen entry's
        // facts, scoped to what this viewer may see.
        $comparison = $documents->whereIn('id', TemplateBlocks::comparisonEntryIds($templateBlocks))
            ->mapWithKeys(fn (Document $d): array => [$d->id => [
                'id' => $d->id,
                'title' => $d->title,
                'type' => Sections::typeSlug($d->kind),
                'slug' => $d->slug,
                'kindLabel' => Sections::kindLabel($d->kind),
                'image' => $d->card?->url,
                'facts' => Facts::for($d, $documents),
            ]])
            ->all();

        // The reader knobs derived from the template blocks resolved above.
        $layout = $templateBlocks !== []
            ? TemplateBlocks::toLegacyLayout($templateBlocks)
            : WorldTemplate::DEFAULT_LAYOUT;
        $layout['hideSidebar'] = $hideSidebar;
        // A template can set its content width explicitly (overriding the content block's own setting).
        $templateWidth = $effectiveTemplate?->layout['width'] ?? null;
        if (in_array($templateWidth, ['normal', 'narrow', 'wide'], true)) {
            $layout['width'] = $templateWidth;
        }

        return Inertia::render('Public/Article', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($documents),
            'viewer' => $this->viewerProps($world, $viewer),
            'section' => $def,
            'entry' => [
                'id' => $document->id,
                'title' => $document->title,
                'kind' => $document->kind,
                'kindLabel' => Sections::kindLabel($document->kind),
                'summary' => $document->summary,
                'content' => $content,
                'banner_url' => $document->banner?->url,
                // The entry's card image — shown as a portrait/avatar above the quick facts.
                'image' => $document->card?->url,
                'cover_mode' => $document->cover_mode ?? 'default',
                'noindex' => (bool) $document->hide_from_search,
                'word_count' => $wordCount,
                'reading_minutes' => max(1, (int) ceil($wordCount / 200)),
                'updated_at' => $document->updated_at?->toIso8601String(),
                'accent' => $document->accent,
                'commentsEnabled' => (bool) $document->comments_enabled,
                'showToc' => (bool) $document->show_toc,
                'hasConnections' => $hasConnections,
            ],
            // GM-curated "related" entries, scoped to what this viewer may see.
            'related' => filled($document->related_ids)
                ? $documents->filter(fn (Document $d) => in_array($d->id, $document->related_ids, true))
                    ->sortBy(fn (Document $d) => array_search($d->id, $document->related_ids, true))
                    ->values()->map(fn (Document $d) => $this->card($d))
                : [],
            // The reader layout for this entry, derived from its template's blocks (or the default).
            'layout' => $layout,
            // The template's blocks, so the reader can honour per-block settings (header toggles, facts
            // columns, banner height, related columns).
            'blocks' => $templateBlocks,
            // The template's custom right-hand sidebar blocks.
            'sidebar' => $sidebarBlocks,
            // Cards for the entries referenced by "linked entries" blocks, keyed by id.
            'linkedCards' => $linkedCards,
            // MapViewer payloads for the template's "map" blocks, keyed by map id.
            'maps' => $mapBlocks,
            // This entry's neighbours (for the Connections block) and the entries a Comparison block lines
            // up by their facts (keyed by id).
            'connections' => $connections,
            'comparison' => $comparison,
            // Resolved block sets for "reusable" blocks, keyed by id.
            'reusableBlocks' => $reusableBlocks,
            'gmSecrets' => $viewer->seesPrivate() && Secrets::count($document->content) > 0,
            // Structured "quick facts" (Population, Ruler, …) shown in the reader's right rail. A template
            // may restrict them to a chosen subset in a chosen order.
            'facts' => Facts::for($document, $documents, $layout['fields']),
            'siblings' => $siblings,
            'statblocks' => $statblocks,
            'embeds' => $embeds,
            'spells' => $spells,
            // Titles a [[wiki-link]] can resolve to — only entries this viewer may see, so GM-only
            // entries never become links for players.
            'wikiTargets' => $documents->map(fn (Document $d) => ['title' => $d->title, 'type' => Sections::typeSlug($d->kind), 'slug' => $d->slug])->values(),
            // How to render: freeform "brew" kinds use the Homebrewery renderer; others render clean.
            'editor' => $document->kind === 'article' ? 'article' : (DocFields::isFormKind($document->kind) ? 'field' : 'brew'),
            'brewCss' => data_get($document->data, 'css', ''),
            'brewTheme' => data_get($document->data, 'theme', 'classic'),
            'myNotes' => $viewer->authed() ? $this->myNotes($document) : [],
            'sharedNotes' => $viewer->isOwner ? $this->sharedNotes($document) : [],
            // A location's interactive map (image, pins, real-world scale) for the reader's Map view.
            'map' => $document->kind === 'location' ? $this->readerMap($document, $viewer) : null,
            // A timeline entry is one Age — its span and dated events (links scoped to visible entries).
            'timeline' => $document->kind === 'timeline'
                ? (Chronicle::build(collect([$document]), $this->timelineLinkMap($documents))[0] ?? null)
                : null,
            // A bloodline entry's family-tree members (member links scoped to visible entries).
            'bloodline' => $document->kind === 'bloodline'
                ? Bloodline::members($document, $this->timelineLinkMap($documents))
                : null,
        ]);
    }

    /**
     * The reader-facing payload for a location's map, or null if it has none this viewer may see.
     *
     * @return array<string, mixed>|null
     */
    protected function readerMap(Document $document, Viewer $viewer): ?array
    {
        $map = Map::where('document_id', $document->id)
            ->with(['image', 'pins.document.card', 'pins.compendiumItem.image'])
            ->visibleTo($viewer)
            ->first();

        if ($map === null || $map->image === null) {
            return null;
        }

        return [
            'image_url' => $map->image->url,
            'real_width' => $map->real_width,
            'distance_unit' => $map->distance_unit,
            'pins' => $map->pins->map(fn (MapPin $pin): array => [
                'x' => $pin->x,
                'y' => $pin->y,
                'label' => $pin->label,
                'note' => $pin->note,
                'behavior' => $pin->behavior,
                'style' => $pin->style ?? 'marker',
                // A token shows the linked entry's portrait (card image), or the monster's image.
                'image_url' => $pin->document?->card?->url ?? $pin->compendiumItem?->image?->url,
                'url' => $pin->document !== null
                    ? "/w/{$document->world->slug}/".Sections::typeSlug($pin->document->kind)."/{$pin->document->slug}"
                    : null,
            ])->all(),
        ];
    }

    /** The password gate shown in place of a protected entry until the reader unlocks it. */
    private function lockedArticle(World $world, Viewer $viewer, Document $document)
    {
        $documents = $this->documents->visibleTo($world, $viewer);

        return Inertia::render('Public/Article', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($documents),
            'viewer' => $this->viewerProps($world, $viewer),
            'section' => Sections::sectionForKind($document->kind),
            'entry' => [
                'id' => $document->id,
                'title' => $document->title,
                'kind' => $document->kind,
                'kindLabel' => Sections::kindLabel($document->kind),
                'type' => Sections::typeSlug($document->kind),
                'slug' => $document->slug,
            ],
            'locked' => true,
        ]);
    }

    /** A compact, iframe-embeddable card for a single public entry at /w/{world}/{type}/{slug}/embed. */
    public function embed(World $world, string $type, string $slug)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);

        $kind = Sections::kindForTypeSlug($type);
        abort_unless($kind, Response::HTTP_NOT_FOUND);
        $document = $world->documents()->where('kind', $kind)->where('slug', $slug)->firstOrFail();
        abort_if($document->is_private && ! $viewer->seesPrivate(), Response::HTTP_NOT_FOUND);

        $accents = [
            'teal' => '#6fbfc4', 'amber' => '#e0a33e', 'crimson' => '#d9555f', 'violet' => '#9b7fe0',
            'emerald' => '#4fd1a1', 'sky' => '#5cc8e6', 'rose' => '#e06c9f',
        ];

        return response()->view('embed.entry', [
            'worldName' => $world->name,
            'title' => $document->title,
            'kindLabel' => Sections::kindLabel($document->kind),
            'summary' => (string) $document->summary,
            'url' => url("/w/{$world->slug}/{$type}/{$slug}"),
            'accent' => $accents[$world->readerTheme()] ?? '#6fbfc4',
        ]);
    }

    /** The unlock screen for a password-gated unlisted world (rendered outside the reader chrome). */
    public function worldLockScreen(World $world)
    {
        if ($world->visibility !== 'hidden' || ! $world->hasReaderPassword()) {
            return redirect()->route('public.world', $world);
        }

        return Inertia::render('Public/WorldLocked', [
            'world' => ['name' => $world->name, 'slug' => $world->slug],
        ]);
    }

    /** Check the world reader password and, if correct, unlock the whole world for this session. */
    public function unlockWorld(Request $request, World $world)
    {
        abort_unless($world->visibility === 'hidden' && $world->hasReaderPassword(), Response::HTTP_NOT_FOUND);

        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], (string) $world->reader_password)) {
            throw ValidationException::withMessages(['password' => 'That password is incorrect.']);
        }

        $request->session()->put("world_unlocked.{$world->id}", true);

        return redirect()->route('public.world', $world);
    }

    /** Check a submitted password and, if correct, unlock the entry for this session. */
    public function unlock(Request $request, World $world, string $type, string $slug)
    {
        $this->guardVisibility($world);

        $kind = Sections::kindForTypeSlug($type);
        abort_unless($kind, Response::HTTP_NOT_FOUND);
        $document = $world->documents()->where('kind', $kind)->where('slug', $slug)->firstOrFail();
        abort_unless(filled($document->access_password), Response::HTTP_NOT_FOUND);

        $data = $request->validate(['password' => ['required', 'string']]);

        if (! Hash::check($data['password'], (string) $document->access_password)) {
            throw ValidationException::withMessages(['password' => 'That password is incorrect.']);
        }

        session()->put("doc_unlocked.{$document->id}", true);

        return redirect()->route('public.article', [$world, $type, $slug]);
    }

    /**
     * Reader coordinates for every entry this viewer may see, keyed by id — the pool a timeline event
     * may link to. Built from the already-scoped visible set, so a link can never resolve to private lore.
     *
     * @param  Collection<int, Document>  $documents
     * @return array<int, array{type: string, slug: string, title: string, image: string|null}>
     */
    protected function timelineLinkMap($documents): array
    {
        return $documents->mapWithKeys(fn (Document $document) => [$document->id => [
            'type' => Sections::typeSlug($document->kind),
            'slug' => $document->slug,
            'title' => $document->title,
            'image' => $document->card?->url,
        ]])->all();
    }

    /** The reader's connections web at /w/{world-slug}/web — scoped to entries this viewer may see. */
    public function web(World $world)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);

        return Inertia::render('Public/Web', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'graph' => Connections::graph($world, $viewer),
        ]);
    }

    /** The world's visible maps at /w/{world-slug}/maps. */
    public function maps(World $world)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        $maps = $world->maps()->with('image')->visibleTo($viewer)->orderBy('sort')->orderBy('name')->get();

        return Inertia::render('Public/Maps', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'maps' => $maps->map(fn (Map $map) => ['slug' => $map->slug, 'name' => $map->name, 'image_url' => $map->image?->url]),
        ]);
    }

    /** A single map at /w/{world-slug}/maps/{map-slug}. */
    public function map(World $world, Map $map)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_if($map->is_private && ! $viewer->seesPrivate(), Response::HTTP_NOT_FOUND);

        return Inertia::render('Public/Map', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'map' => $this->readerMapPayload($world, $map, $viewer),
        ]);
    }

    /**
     * Shape a map for the reader's MapViewer: its image, grid/unit settings, fog, the location it depicts,
     * and its pins resolved to click actions — scoped to what this viewer may see. Shared by the full map
     * page and the entry template's "map" block.
     *
     * @return array<string, mixed>
     */
    protected function readerMapPayload(World $world, Map $map, Viewer $viewer): array
    {
        $map->load(['image', 'document', 'pins.document:id,title,kind,slug,is_private,summary']);

        $canSee = fn (?Document $document) => $document !== null && (! $document->is_private || $viewer->seesPrivate());
        $articleHref = fn (Document $document) => route('public.article', [$world->slug, Sections::typeSlug($document->kind), $document->slug]);

        // Pins to a GM-only entry are hidden from players (they'd reveal the location).
        $visiblePins = $map->pins->filter(fn (MapPin $pin) => ! ($pin->document && $pin->document->is_private && ! $viewer->seesPrivate()));

        // Which pinned locations have a map this viewer can open (for "travel" markers).
        $targetIds = $visiblePins->pluck('document_id')->filter()->unique()->all();
        $mapsByDocument = $targetIds === []
            ? collect()
            : $world->maps()->whereIn('document_id', $targetIds)->visibleTo($viewer)->get()->keyBy('document_id');

        $pins = $visiblePins->map(function (MapPin $pin) use ($world, $canSee, $articleHref, $mapsByDocument) {
            $document = $pin->document;
            $behavior = $pin->behavior ?: 'info';
            $travelMap = $document ? $mapsByDocument->get($document->id) : null;

            // "travel" opens the location's own map; falls back to the article if it has no visible map.
            if ($behavior === 'travel' && $travelMap) {
                return $this->readerPin($pin, $document, 'travel', route('public.map', [$world->slug, $travelMap->slug]));
            }
            if ($behavior === 'info') {
                return $this->readerPin($pin, $document, 'info', null, [
                    'title' => $pin->label ?: $document?->title,
                    'summary' => $pin->note ?: $document?->summary,
                    'href' => $canSee($document) ? $articleHref($document) : null,
                ]);
            }

            return $this->readerPin($pin, $document, $canSee($document) ? 'article' : 'none', $canSee($document) ? $articleHref($document) : null);
        })->values();

        // The location this map depicts — powers the expand-to-article panel.
        $document = $map->document;
        $location = $canSee($document) ? [
            'title' => $document->title,
            'kindLabel' => Sections::kindLabel($document->kind),
            'href' => $articleHref($document),
            'content' => $viewer->seesPrivate() ? ($document->content ?? '') : Secrets::strip($document->content),
        ] : null;

        return [
            'id' => $map->id, 'name' => $map->name, 'slug' => $map->slug, 'image_url' => $map->image?->url,
            'grid_visible' => $map->grid_visible, 'grid_size' => $map->grid_size,
            'unit_size' => $map->unit_size, 'unit' => $map->unit,
            'fog_enabled' => $map->fog_enabled, 'fog' => $map->fog ?? [],
            'location' => $location,
            'pins' => $pins,
        ];
    }

    /**
     * Shape a map pin for the reader: its click action (info | travel | article | none), the target
     * URL, and any info-popup payload. GM notes never travel — only a label, a link, and a summary.
     *
     * @param  array<string, mixed>|null  $popup
     * @return array<string, mixed>
     */
    private function readerPin(MapPin $pin, ?Document $document, string $action, ?string $href, ?array $popup = null): array
    {
        return [
            'id' => $pin->id,
            'x' => $pin->x,
            'y' => $pin->y,
            'label' => $pin->label ?: $document?->title,
            'action' => $action,
            'href' => $href,
            'popup' => $popup,
        ];
    }

    /** The world's player-visible compendium at /w/{world-slug}/compendium — searchable + paginated. */
    public function compendium(World $world)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($world->readerShowsCompendium() || $viewer->seesPrivate(), Response::HTTP_NOT_FOUND);

        $base = $world->compendiumItems()->visibleTo($viewer);

        // The type tabs reflect the whole visible set, independent of the current search/filter.
        $counts = (clone $base)->selectRaw('item_type, count(*) as total')
            ->groupBy('item_type')->pluck('total', 'item_type');
        $types = collect(Compendium::TYPES)
            ->map(fn (array $meta, string $key) => ['type' => $key, 'plural' => $meta['plural'], 'count' => (int) ($counts[$key] ?? 0)])
            ->filter(fn (array $type) => $type['count'] > 0)->values();

        $type = (string) request()->query('type', '');
        $search = trim((string) request()->query('q', ''));
        // One tab per type (no "All"); default to the first type that has entries.
        $activeType = isset(Compendium::TYPES[$type]) && ($counts[$type] ?? 0) > 0
            ? $type
            : ($types->first()['type'] ?? null);

        $items = (clone $base)
            ->when($activeType !== null, fn ($query) => $query->where('item_type', $activeType))
            ->when($search !== '', fn ($query) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', "%{$search}%")->orWhere('summary', 'like', "%{$search}%")))
            ->with('image')->orderBy('name')->paginate(24)->withQueryString()
            ->through(fn (CampaignCompendiumItem $item) => [
                'slug' => $item->slug,
                'name' => $item->name,
                'summary' => $item->summary,
                'item_type' => $item->item_type,
                'typeLabel' => Compendium::label($item->item_type),
                'image_url' => $item->image?->url,
                // The core, at-a-glance details for this type (CR + creature type, spell level/school, …).
                'facts' => $this->compendiumFacts($item),
            ]);

        return Inertia::render('Public/Compendium', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'types' => $types,
            'total' => (int) $counts->sum(),
            'columns' => $this->compendiumColumns($activeType),
            'filters' => ['q' => $search, 'type' => $activeType],
            'items' => $items,
        ]);
    }

    /** D&D schools and item rarities, used to pull the core detail out of an entry's tags. */
    private const SPELL_SCHOOLS = ['abjuration', 'conjuration', 'divination', 'enchantment', 'evocation', 'illusion', 'necromancy', 'transmutation'];

    private const ITEM_RARITIES = ['common', 'uncommon', 'rare', 'very rare', 'legendary', 'artifact'];

    /**
     * The at-a-glance detail columns shown in the table for a given type.
     *
     * @return list<array{key: string, label: string}>
     */
    private function compendiumColumns(?string $type): array
    {
        return match ($type) {
            'monster' => [['key' => 'Type', 'label' => 'Type'], ['key' => 'CR', 'label' => 'CR']],
            'spell' => [['key' => 'Level', 'label' => 'Level'], ['key' => 'School', 'label' => 'School']],
            'magicitem' => [['key' => 'Item', 'label' => 'Item'], ['key' => 'Rarity', 'label' => 'Rarity']],
            'equipment' => [['key' => 'Category', 'label' => 'Category']],
            default => [],
        };
    }

    /**
     * Extract the core details for an entry from its stat block (monsters) or tags (everything else).
     *
     * @return array<string, string>
     */
    private function compendiumFacts(CampaignCompendiumItem $item): array
    {
        $tags = collect($item->tags ?? [])->map(fn ($tag) => mb_strtolower(trim((string) $tag)));
        $block = is_array($item->fields['block'] ?? null) ? $item->fields['block'] : [];
        $titled = fn (?string $value) => filled($value) ? mb_convert_case($value, MB_CASE_TITLE) : null;

        $facts = match ($item->item_type) {
            'monster' => [
                'Type' => filled($block['type'] ?? null) ? ucfirst((string) $block['type']) : null,
                'CR' => filled($block['cr'] ?? null) ? (string) $block['cr'] : null,
            ],
            'spell' => [
                'Level' => $this->spellLevel($tags),
                'School' => $titled($tags->first(fn ($tag) => in_array($tag, self::SPELL_SCHOOLS, true))),
            ],
            'magicitem' => [
                'Item' => $titled($tags->first(fn ($tag) => ! in_array($tag, self::ITEM_RARITIES, true))),
                'Rarity' => $titled($tags->first(fn ($tag) => in_array($tag, self::ITEM_RARITIES, true))),
            ],
            'equipment' => [
                'Category' => $titled($tags->reject(fn ($tag) => $tag === 'gear')->first()),
            ],
            default => [],
        };

        return array_filter($facts, fn ($value) => filled($value));
    }

    /** A spell's level from its tags ("cantrip" or "3rd-level" → "Cantrip" / "3rd"). */
    private function spellLevel(Collection $tags): ?string
    {
        $tag = $tags->first(fn ($t) => $t === 'cantrip' || preg_match('/^\d+(st|nd|rd|th)-level$/', $t) === 1);

        if ($tag === null) {
            return null;
        }

        return $tag === 'cantrip' ? 'Cantrip' : ucfirst(str_replace('-level', '', $tag));
    }

    /** A single compendium entry at /w/{world-slug}/compendium/{item-slug}. */
    public function compendiumItem(World $world, string $slug)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($world->readerShowsCompendium() || $viewer->seesPrivate(), Response::HTTP_NOT_FOUND);
        $item = $world->compendiumItems()->with('image')->where('slug', $slug)->visibleTo($viewer)->firstOrFail();

        // Spells (id/name/summary) so a monster's spellcasting entries can hover-preview their spell.
        $spells = $world->compendiumItems()->where('item_type', 'spell')->visibleTo($viewer)
            ->get(['id', 'name', 'summary', 'document', 'fields'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name, 'summary' => $s->summary, 'document' => $s->document, 'fields' => $s->fields ?? []])
            ->values();

        return Inertia::render('Public/CompendiumItem', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'item' => [
                'name' => $item->name,
                'summary' => $item->summary,
                'item_type' => $item->item_type,
                'typeLabel' => Compendium::label($item->item_type),
                'image_url' => $item->image?->url,
                'block' => $item->item_type === 'monster' ? data_get($item->fields, 'block') : null,
                'document' => $item->item_type === 'monster' ? null : $item->document,
            ],
            'spells' => $spells,
        ]);
    }

    /** The world's campaigns this viewer may browse, at /w/{world-slug}/campaigns. */
    public function campaigns(World $world)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);

        $campaigns = $world->campaigns()->orderBy('name')->get()
            ->filter(fn (Campaign $campaign) => $this->campaignIsListed($campaign, $viewer))
            ->values()
            ->map(fn (Campaign $campaign) => [
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'description' => $campaign->description,
                'visibility' => $campaign->visibility,
                'session_count' => $this->visibleSessions($campaign, $viewer)->count(),
            ]);

        return Inertia::render('Public/Campaigns', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'campaigns' => $campaigns,
        ]);
    }

    /** A single campaign's readable sessions, at /w/{world-slug}/campaigns/{campaign-slug}. */
    public function campaign(World $world, Campaign $campaign)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($this->canViewCampaign($campaign, $viewer), Response::HTTP_NOT_FOUND);

        $sessions = $this->visibleSessions($campaign, $viewer);
        $latest = $sessions->first();

        return Inertia::render('Public/CampaignOverview', [
            ...$this->campaignReaderProps($world, $campaign, $viewer),
            'stats' => [
                'sessions' => $sessions->count(),
                'characters' => $campaign->characters()->count(),
                'game_system' => $campaign->gameSystem(),
            ],
            'lastSession' => $latest === null ? null : [
                'title' => $latest->title,
                'held_on' => $latest->held_on?->toDateString(),
                'summary' => $latest->recap?->recap_short,
                'url' => url("/w/{$world->slug}/campaigns/{$campaign->slug}/sessions/{$latest->slug}"),
            ],
            // Upcoming real-world dates the GM has scheduled — players only ever see these, not the full calendar.
            'upcoming' => $campaign->scheduleEvents()
                ->where('starts_at', '>=', now())
                ->orderBy('starts_at')->limit(6)->get()
                ->map(fn (ScheduleEvent $event): array => [
                    'title' => $event->title,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'notes' => $event->notes,
                ]),
        ]);
    }

    /** A campaign's session-recap timeline at /w/{world-slug}/campaigns/{campaign-slug}/sessions. */
    public function campaignSessions(World $world, Campaign $campaign)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($this->canViewCampaign($campaign, $viewer), Response::HTTP_NOT_FOUND);

        $sessions = $this->visibleSessions($campaign, $viewer)->map(fn (Session $session) => [
            'slug' => $session->slug,
            'title' => $session->title,
            'held_on' => $session->held_on?->toDateString(),
            'summary' => $session->recap?->recap_short,
        ]);

        return Inertia::render('Public/CampaignSessions', [
            ...$this->campaignReaderProps($world, $campaign, $viewer),
            'sessions' => $sessions,
        ]);
    }

    /** A campaign's characters at /w/{world-slug}/campaigns/{campaign-slug}/characters. */
    public function campaignCharacters(World $world, Campaign $campaign)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($this->canViewCampaign($campaign, $viewer), Response::HTTP_NOT_FOUND);

        $characters = $campaign->characters()->with('image')->orderBy('name')->get()
            ->map(fn (Character $character) => [
                'slug' => $character->slug,
                'name' => $character->name,
                'image_url' => $character->image?->url,
                'level' => $character->level,
                'blurb' => collect([$character->race, $character->class])->filter()->implode(' ') ?: null,
            ]);

        return Inertia::render('Public/CampaignCharacters', [
            ...$this->campaignReaderProps($world, $campaign, $viewer),
            'characters' => $characters,
        ]);
    }

    /** A campaign's play schedule + player RSVPs at /w/{world-slug}/campaigns/{campaign-slug}/schedule. */
    public function campaignSchedule(World $world, Campaign $campaign)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($this->canViewCampaign($campaign, $viewer), Response::HTTP_NOT_FOUND);

        $user = Auth::user();
        // Members of this campaign (and the GM) may RSVP and see who else is coming; guests just see the dates.
        $canRespond = $user !== null && ($viewer->isOwner || $campaign->hasMember($user));

        $events = $campaign->scheduleEvents()
            ->with($canRespond ? ['responses.user:id,name'] : [])
            ->orderBy('starts_at')->get()
            ->map(function (ScheduleEvent $event) use ($canRespond, $user): array {
                $mine = $canRespond
                    ? $event->responses->firstWhere('user_id', $user->id)
                    : null;

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'starts_at' => $event->starts_at?->toIso8601String(),
                    'notes' => $event->notes,
                    'my_status' => $mine?->status,
                    'my_note' => $mine?->note,
                    // The table roll-call, shown only to members/GM (never leaked to guests).
                    'responses' => $canRespond
                        ? $event->responses->map(fn (ScheduleEventResponse $response): array => [
                            'name' => $response->user?->name,
                            'status' => $response->status,
                            'note' => $response->note,
                        ])->values()
                        : [],
                ];
            });

        return Inertia::render('Public/CampaignSchedule', [
            ...$this->campaignReaderProps($world, $campaign, $viewer),
            'events' => $events,
            'canRespond' => $canRespond,
        ]);
    }

    /** A single character's page at /w/{world-slug}/campaigns/{campaign-slug}/characters/{character-slug}. */
    public function campaignCharacter(World $world, Campaign $campaign, Character $character)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($this->canViewCampaign($campaign, $viewer), Response::HTTP_NOT_FOUND);

        $character->loadMissing('image');

        return Inertia::render('Public/CampaignCharacter', [
            ...$this->campaignReaderProps($world, $campaign, $viewer),
            'character' => [
                'name' => $character->name,
                'image_url' => $character->image?->url,
                'level' => $character->level,
                'class' => $character->class,
                'race' => $character->race,
                'ac' => $character->ac,
                'hp' => $character->hp,
                'max_hp' => $character->max_hp,
                'stats' => $character->stats ?? [],
                'sheet' => $character->sheet ?? [],
            ],
        ]);
    }

    /**
     * The props every campaign reader page shares: world head, sections, viewer state, and the campaign.
     *
     * @return array<string, mixed>
     */
    protected function campaignReaderProps(World $world, Campaign $campaign, Viewer $viewer): array
    {
        return [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'selectedCampaign' => [
                'name' => $campaign->name,
                'slug' => $campaign->slug,
                'description' => $campaign->description,
            ],
        ];
    }

    /** A single session's recap, at /w/{world-slug}/campaigns/{campaign-slug}/sessions/{session-slug}. */
    public function session(World $world, Campaign $campaign, Session $session)
    {
        $this->guardVisibility($world);
        $viewer = $this->makeViewer($world);
        abort_unless($this->canViewCampaign($campaign, $viewer), Response::HTTP_NOT_FOUND);

        $canSeePrivate = $viewer->isOwner || $this->viewerBelongs($campaign, $viewer);
        abort_if($session->is_private && ! $canSeePrivate, Response::HTTP_NOT_FOUND);

        $recap = $session->recap;
        abort_unless($recap !== null && $recap->status === 'done', Response::HTTP_NOT_FOUND);
        // Players only see a recap once it's published (auto or by the GM); the GM always sees it.
        abort_unless($viewer->seesPrivate() || $recap->isPublishedFor($world), Response::HTTP_NOT_FOUND);

        $recap->loadMissing(['entities.linkedDocument', 'entities.linkedCompendiumItem']);

        // A member (or the GM) may keep private notes against the session, written right here.
        $user = Auth::user();
        $canNote = $user !== null && $world->readerAllowsNotes()
            && ($viewer->isOwner || $campaign->hasMember($user));

        return Inertia::render('Public/Session', [
            'campaign' => $this->head($world, $viewer),
            'sections' => Sections::withCounts($this->documents->visibleTo($world, $viewer)),
            'viewer' => $this->viewerProps($world, $viewer),
            'selectedCampaign' => ['name' => $campaign->name, 'slug' => $campaign->slug],
            'session' => ['id' => $session->id, 'title' => $session->title, 'held_on' => $session->held_on?->toDateString()],
            'canNote' => $canNote,
            'notes' => $canNote
                ? $session->notes()->where('user_id', $user->id)->latest()->get()
                    ->map(fn (SessionNote $note): array => [
                        'id' => $note->id,
                        'body' => $note->body,
                        'when' => $note->created_at?->diffForHumans(),
                    ])->all()
                : [],
            'recap' => [
                'recap_full' => $recap->recap_full,
                'recap_short' => $recap->recap_short,
                'recap_stylized' => $recap->recap_stylized,
                'moments' => $recap->moments ?? [],
                'outline' => $recap->outline ?? [],
                'next_steps' => $recap->next_steps ?? [],
                'entities' => $recap->entities->map(fn (RecapEntity $entity): array => [
                    'name' => $entity->name,
                    'type' => $entity->type,
                    'description' => $entity->description,
                    // A reader link to the world entry this entity is tied to, when the viewer may open it.
                    'url' => $this->entityReaderUrl($entity, $world, $viewer),
                ])->all(),
                // The raw transcript and the GM's private rating stay GM-only, even on a public session.
                'transcript' => $viewer->isGm ? $recap->transcript : null,
                'rating' => $viewer->isGm ? $recap->rating : null,
            ],
        ]);
    }

    /** The public reader URL for the world entry a recap entity links to, or null if none / not visible. */
    protected function entityReaderUrl(RecapEntity $entity, World $world, Viewer $viewer): ?string
    {
        if ($entity->linked_document_id !== null) {
            $document = $entity->linkedDocument;

            if ($document === null || ($document->is_private && ! $viewer->seesPrivate())) {
                return null;
            }

            return url("/w/{$world->slug}/".Sections::typeSlug($document->kind)."/{$document->slug}");
        }

        if ($entity->linked_compendium_item_id !== null) {
            $item = $entity->linkedCompendiumItem;

            if ($item === null) {
                return null;
            }

            // Only link an item the viewer may actually open in the reader.
            $visible = $world->compendiumItems()->whereKey($item->id)->visibleTo($viewer)->exists();

            return $visible ? url("/w/{$world->slug}/compendium/{$item->slug}") : null;
        }

        return null;
    }

    /** Whether the viewer may open this campaign directly (public/hidden by link, private for members; owner always). */
    protected function canViewCampaign(Campaign $campaign, Viewer $viewer): bool
    {
        if ($viewer->isOwner) {
            return true;
        }

        return match ($campaign->visibility) {
            'public', 'hidden' => true,
            default => $this->viewerBelongs($campaign, $viewer),
        };
    }

    /** Whether this campaign appears in the public listing — hidden is link-only, so excluded. */
    protected function campaignIsListed(Campaign $campaign, Viewer $viewer): bool
    {
        if ($viewer->isOwner) {
            return true;
        }

        return match ($campaign->visibility) {
            'public' => true,
            'hidden' => false,
            default => $this->viewerBelongs($campaign, $viewer),
        };
    }

    protected function viewerBelongs(Campaign $campaign, Viewer $viewer): bool
    {
        return $viewer->userId !== null
            && $campaign->members()->where('user_id', $viewer->userId)->exists();
    }

    /**
     * Sessions in a campaign this viewer may read: those with a finished recap, excluding GM-only
     * (private) sessions unless the viewer is the owner or a member of the campaign.
     *
     * @return Collection<int, Session>
     */
    protected function visibleSessions(Campaign $campaign, Viewer $viewer): Collection
    {
        $canSeePrivate = $viewer->isOwner || $this->viewerBelongs($campaign, $viewer);

        return $campaign->sessions()->with('recap')
            ->orderByDesc('held_on')->orderByDesc('sort')->orderByDesc('id')
            ->get()
            ->filter(fn (Session $session) => $session->recap !== null && $session->recap->status === 'done')
            ->filter(fn (Session $session) => $viewer->seesPrivate() || $session->recap->isPublishedFor($campaign->world))
            ->filter(fn (Session $session) => $canSeePrivate || ! $session->is_private)
            ->values();
    }

    /**
     * The most recent readable session recaps across a set of campaigns, newest first — feeds the home
     * page's "session recaps" block.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return Collection<int, array{campaign: string, title: string, held_on: string|null, summary: string|null, url: string}>
     */
    protected function homeRecaps(World $world, Collection $campaigns, Viewer $viewer): Collection
    {
        return $campaigns
            ->flatMap(fn (Campaign $campaign): Collection => $this->visibleSessions($campaign, $viewer)
                ->map(fn (Session $session): array => [
                    'campaign' => $campaign->name,
                    'title' => $session->title,
                    'held_on' => $session->held_on?->toDateString(),
                    'summary' => $session->recap?->recap_short,
                    'url' => url("/w/{$world->slug}/campaigns/{$campaign->slug}/sessions/{$session->slug}"),
                ]))
            ->sortByDesc('held_on')
            ->take(8)
            ->values();
    }

    /**
     * The soonest upcoming scheduled game across a set of campaigns — feeds the home page's "Next session"
     * countdown. Null when nothing is scheduled ahead.
     *
     * @param  Collection<int, Campaign>  $campaigns
     * @return array{campaign: string, title: string, starts_at: string|null, url: string}|null
     */
    protected function homeNextSession(World $world, Collection $campaigns): ?array
    {
        $byId = $campaigns->keyBy('id');
        if ($byId->isEmpty()) {
            return null;
        }

        $event = ScheduleEvent::whereIn('campaign_id', $byId->keys()->all())
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->first();

        if ($event === null) {
            return null;
        }

        $campaign = $byId->get($event->campaign_id);

        return [
            'campaign' => $campaign->name,
            'title' => $event->title,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'url' => url("/w/{$world->slug}/campaigns/{$campaign->slug}/schedule"),
        ];
    }

    /**
     * Resolve the "reusable" blocks referenced by a block list into their stored block sets, keyed by id
     * (kept current, so editing a reusable set updates every page that references it).
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<int, list<array<string, mixed>>>
     */
    protected function resolveReusableBlocks(World $world, array $blocks): array
    {
        return WorldBlock::where('world_id', $world->id)
            ->whereIn('id', TemplateBlocks::reusableIds($blocks))->get()
            ->mapWithKeys(fn (WorldBlock $b): array => [$b->id => TemplateBlocks::normalise($b->layout, '', 'block')])
            ->all();
    }

    /** A private world is only visible to its owner (the slug is otherwise route-bound already). */
    /** Live reader search across the entries this viewer may see, grouped by section, as JSON. */
    public function search(Request $request, World $world): JsonResponse
    {
        $this->guardVisibility($world);

        $term = trim((string) $request->query('q', ''));
        if (mb_strlen($term) < 2) {
            return response()->json(['groups' => []]);
        }

        $viewer = $this->makeViewer($world);

        /** @var array<string, list<array{title: string, sub: string, url: string}>> $groups */
        $groups = [];

        foreach ($this->documents->search($world, $viewer, $term)->take(30) as $entry) {
            $card = $entry->toReaderCard();
            $section = Sections::sectionForKind($card['kind']);
            $label = $section['label'] ?? $card['kindLabel'];
            $groups[$label][] = [
                'title' => $card['title'],
                'sub' => $card['kindLabel'],
                'url' => route('public.article', [$world->slug, $card['type'], $card['slug']]),
            ];
        }

        return response()->json([
            'groups' => collect($groups)->map(fn (array $items, string $label): array => [
                'label' => $label,
                'items' => $items,
            ])->values()->all(),
        ]);
    }

    protected function guardVisibility(World $world): void
    {
        $isOwner = Auth::check() && $world->user_id === Auth::id();
        abort_if($world->visibility === 'private' && ! $isOwner, Response::HTTP_NOT_FOUND);
    }

    /** The viewer for this request — owner is GM unless previewing as a player (?as=player). */
    protected function makeViewer(World $world): Viewer
    {
        return Viewer::for($world, Auth::user(), request()->query('as') === 'player');
    }

    /**
     * The frontend-facing viewer state (role + toolbar bits), derived from the {@see Viewer}.
     *
     * @return array<string, mixed>
     */
    protected function viewerProps(World $world, Viewer $viewer): array
    {
        $user = Auth::user();

        // GM-only content, surfaced to the owner via the header "SECRETS" menu: wholly-private entries,
        // plus otherwise-public entries that hide {{secret}}…{{/}} passages. Both link to the entry.
        // Empty for everyone else.
        $href = fn (Document $document): string => "/w/{$world->slug}/".Sections::typeSlug($document->kind)."/{$document->slug}";

        $privateEntries = $viewer->isOwner
            ? $world->documents()->where('is_private', true)->orderBy('title')->get(['kind', 'slug', 'title'])
                ->map(fn (Document $document): array => [
                    'title' => $document->title,
                    'kindLabel' => Sections::kindLabel($document->kind),
                    'href' => $href($document),
                    'passages' => 0,
                    'private' => true,
                ])
            : collect();

        $passageEntries = $viewer->isOwner
            ? $world->documents()->where('is_private', false)->where('content', 'like', '%{{secret}}%')
                ->orderBy('title')->get(['kind', 'slug', 'title', 'content'])
                ->map(fn (Document $document): array => [
                    'title' => $document->title,
                    'kindLabel' => Sections::kindLabel($document->kind),
                    'href' => $href($document),
                    'passages' => Secrets::count($document->content),
                    'private' => false,
                ])
                // A stray "{{secret}}" without its closing tag counts zero — don't list a false positive.
                ->filter(fn (array $entry): bool => $entry['passages'] > 0)
                ->values()
            : collect();

        $secrets = $privateEntries->concat($passageEntries)->values();

        return [
            'authed' => $viewer->authed(),
            'isOwner' => $viewer->isOwner,
            'isMember' => $viewer->isMember,
            'canToggle' => $viewer->isOwner,
            'gmView' => $viewer->isGm,
            'asPlayer' => ! $viewer->isGm,
            'initial' => $user ? strtoupper(substr($user->name ?: $user->email, 0, 1)) : '',
            'avatar' => $user?->avatar_url,
            'name' => $user?->name,
            'secretCount' => $secrets->count(),
            'secrets' => $secrets,
        ];
    }

    protected function myNotes(Document $document)
    {
        return $document->notes()->where('user_id', Auth::id())->latest()->get()->map(fn (ArticleNote $n) => [
            'id' => $n->id, 'body' => $n->body, 'shared' => $n->shared, 'when' => $n->created_at?->diffForHumans(),
        ]);
    }

    protected function sharedNotes(Document $document)
    {
        return $document->notes()->where('shared', true)->where('user_id', '!=', Auth::id())
            ->with('user:id,name')->latest()->get()->map(fn (ArticleNote $n) => [
                'id' => $n->id, 'body' => $n->body, 'author' => $n->user?->name,
            ]);
    }

    protected function head(World $world, Viewer $viewer): array
    {
        // The reader nav menu (raw tree) is resolved to hrefs/labels/counts client-side. The client can
        // check feature flags and section counts itself, but not whether a linked campaign/entry is still
        // visible to this viewer — so we resolve just those referenced targets here.
        $menu = $world->readerMenuOrDefault();

        $navCampaigns = $world->campaigns
            ->filter(fn (Campaign $campaign): bool => $this->campaignIsListed($campaign, $viewer))
            ->map(fn (Campaign $campaign): array => ['slug' => $campaign->slug, 'name' => $campaign->name])
            ->values();

        $navEntries = $this->resolveNavEntries($world, $viewer, NavMenu::collectTargets($menu, 'entry'));

        return [
            'code' => $world->code,
            'slug' => $world->slug,
            'name' => $world->name,
            'description' => $world->description,
            // Whether to show the reader's "Maps" nav link — only when this viewer can see a map.
            'hasMaps' => $world->maps()->visibleTo($viewer)->exists(),
            // "Compendium" — a player-visible entry exists AND the GM allows it (GM always sees it).
            'hasCompendium' => $world->compendiumItems()->visibleTo($viewer)->exists()
                && ($world->readerShowsCompendium() || $viewer->seesPrivate()),
            // "Web" — the connections graph, worth showing once there are at least two visible entries.
            'hasWeb' => $this->documents->visibleCount($world, $viewer) >= 2,
            // "Campaigns" — only when at least one campaign is listed for this viewer.
            'hasCampaigns' => $world->campaigns->contains(fn (Campaign $campaign) => $this->campaignIsListed($campaign, $viewer)),
            // Whether signed-in players may keep private notes here.
            'allowNotes' => $world->readerAllowsNotes(),
            // Keep the reader out of search indexes unless it's public and the GM opted in.
            'noindex' => $world->visibility !== 'public' || ! $world->readerIndexable(),
            // The reader's accent theme.
            'theme' => $world->readerTheme(),
            // The GM's custom reader colours (background/headings/body text); nulls fall back to defaults.
            'colours' => $world->readerColours(),
            // The GM's custom reader CSS (raw; scoped + sanitised to the reader root in the browser).
            'css' => $world->readerCss(),
            // Reader branding: header logo + hero banner + favicon (null when the GM hasn't set one).
            'logo' => $world->logo?->url,
            'banner' => $world->banner?->url,
            'favicon' => $world->favicon?->url,
            // The GM's custom footer line, if any (the default credit shows otherwise).
            'footer' => $world->readerFooter(),
            // Reading font + how the home page leads.
            'font' => $world->readerFont(),
            'homeLayout' => $world->readerHomeLayout(),
            // Date formatting + whether to number sessions on the timeline.
            'dateFormat' => $world->readerDateFormat(),
            'numberSessions' => $world->readerNumbersSessions(),
            // Reader nav customisation (hide/reorder sections + extra external links).
            'nav' => $world->readerNav(),
            // The nav menu tree (WordPress-style), resolved to real links by the reader. The lookups
            // below let it validate campaign/entry targets without exposing the whole world.
            'navMenu' => $menu,
            'navCampaigns' => $navCampaigns,
            'navEntries' => $navEntries,
            // Social-share (Open Graph) image, falling back to the banner.
            'ogImage' => $world->socialImageUrl(),
            // Optional creator support link (Patreon/Ko-fi/etc.).
            'support' => $world->supportUrl() === null ? null : [
                'url' => $world->supportUrl(),
                'label' => $world->supportLabel(),
            ],
            // A jump back to the workspace settings, shown only to the owner viewing their own reader.
            'manageUrl' => $viewer->isOwner ? route('worlds.settings', $world->id) : null,
            // Privacy-analytics tag for the reader; never loaded for the GM's own previews.
            'analytics' => $viewer->seesPrivate() ? null : $world->readerAnalytics(),
        ];
    }

    /**
     * Resolve the entry targets a nav menu references ("typeSlug:slug") to their titles, dropping any
     * this viewer can't see. Keyed by the same "typeSlug:slug" string the menu stores.
     *
     * @param  list<string>  $targets
     * @return array<string, array{title: string}>
     */
    protected function resolveNavEntries(World $world, Viewer $viewer, array $targets): array
    {
        if ($targets === []) {
            return [];
        }

        $slugs = [];
        foreach ($targets as $target) {
            [, $slug] = array_pad(explode(':', $target, 2), 2, '');
            if ($slug !== '') {
                $slugs[] = $slug;
            }
        }
        if ($slugs === []) {
            return [];
        }

        $entries = $world->documents()
            ->whereIn('slug', array_values(array_unique($slugs)))
            ->get(['kind', 'slug', 'title', 'is_private']);

        $resolved = [];
        foreach ($entries as $entry) {
            if (! $viewer->seesPrivate() && $entry->is_private) {
                continue;
            }
            $resolved[Sections::typeSlug($entry->kind).':'.$entry->slug] = ['title' => $entry->title];
        }

        return $resolved;
    }

    protected function card(Document $document): array
    {
        return DocumentSummaryData::fromModel($document)->toReaderCard();
    }
}
