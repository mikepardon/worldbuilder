<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\World;
use App\Models\WorldBlock;
use App\Models\WorldTemplate;
use App\Support\DocFields;
use App\Support\Facts;
use App\Support\Sections;
use App\Support\TemplateBlocks;
use App\Support\TemplateBuilderData;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reusable reader layouts a world defines per document kind. Owner-managed. */
class WorldTemplateController extends Controller
{
    /** The template list — entry templates plus the singleton home template. The builder is its own page. */
    public function index(World $world): Response
    {
        $this->authorize('update', $world);

        $home = $world->templates()->where('target', 'home')->first();

        return Inertia::render('Worlds/Templates', [
            'world' => WorldNav::for($world),
            'templates' => $world->templates()->where('target', 'entry')->orderBy('name')->get()->map(fn (WorldTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'kind' => $template->kind,
                'is_default' => $template->is_default,
                'blocks' => TemplateBlocks::normalise($template->layout, $template->kind),
            ]),
            'homeTemplate' => $home === null ? null : ['id' => $home->id, 'name' => $home->name],
            'archiveTemplates' => $world->templates()->where('target', 'archive')->orderBy('name')->get()
                ->map(fn (WorldTemplate $t): array => ['id' => $t->id, 'name' => $t->name, 'section' => $t->section]),
            'reusableBlocks' => WorldBlock::where('world_id', $world->id)->orderBy('name')->get()
                ->map(fn (WorldBlock $b): array => ['id' => $b->id, 'name' => $b->name]),
            'sectionOptions' => collect(Sections::SECTIONS)->map(fn (array $s): array => ['slug' => $s['slug'], 'label' => $s['label']])->all(),
        ]);
    }

    /** The dedicated builder page for a new template (entry by default, or ?target=home). */
    public function create(Request $request, World $world): Response
    {
        $this->authorize('update', $world);

        $target = in_array($request->query('target'), ['home', 'archive'], true) ? $request->query('target') : 'entry';
        $sections = collect(Sections::SECTIONS)->pluck('slug')->all();
        $initialSection = $target === 'archive' && in_array($request->query('section'), $sections, true)
            ? $request->query('section')
            : null;

        return Inertia::render('Worlds/TemplateBuilder', [
            ...$this->builderData($world, $target),
            'target' => $target,
            'template' => null,
            'initialSection' => $initialSection,
        ]);
    }

    /** The dedicated builder page for the world's home page template (creating it if needed). */
    public function home(World $world): Response
    {
        $this->authorize('update', $world);

        $template = $world->templates()->where('target', 'home')->first();

        return Inertia::render('Worlds/TemplateBuilder', [
            ...$this->builderData($world, 'home'),
            'target' => 'home',
            'template' => $template === null ? null : [
                'id' => $template->id,
                'name' => $template->name,
                'kind' => '',
                'target' => 'home',
                'blocks' => TemplateBlocks::normalise($template->layout, '', 'home'),
            ],
        ]);
    }

    /** The dedicated builder page for an existing template. */
    public function edit(WorldTemplate $template): Response
    {
        $this->authorize('update', $template->world);

        $target = $template->target ?: 'entry';

        return Inertia::render('Worlds/TemplateBuilder', [
            ...$this->builderData($template->world, $target),
            'target' => $target,
            'template' => [
                'id' => $template->id,
                'name' => $template->name,
                'kind' => $template->kind,
                'target' => $target,
                'section' => $template->section,
                'is_default' => $template->is_default,
                'blocks' => TemplateBlocks::normalise($template->layout, $template->kind, $target),
                'sidebar' => TemplateBlocks::sanitise((array) ($template->layout['sidebar'] ?? []), true, 'sidebar'),
                'hideSidebar' => (bool) ($template->layout['hideSidebar'] ?? false),
                'width' => $template->layout['width'] ?? null,
            ],
        ]);
    }

    /**
     * The catalogue the builder needs: per-kind fields, the block types + starter for the target, and
     * entries to preview against.
     *
     * @return array<string, mixed>
     */
    private function builderData(World $world, string $target = 'entry'): array
    {
        return TemplateBuilderData::for($world, $target);
    }

    /** Preview data for one real entry, so the builder can render a template against it. */
    public function preview(World $world, Document $document): JsonResponse
    {
        $this->authorize('update', $world);
        abort_unless($document->world_id === $world->id, 404);

        $documents = $world->documents()->get();
        $words = str_word_count(strip_tags((string) $document->content));
        $related = filled($document->related_ids)
            ? $documents->whereIn('id', $document->related_ids)
                ->map(fn (Document $d): array => ['title' => $d->title, 'kind' => Sections::kindLabel($d->kind)])
                ->values()
            : collect();

        return response()->json([
            'eyebrow' => Sections::kindLabel($document->kind),
            'title' => $document->title,
            'summary' => (string) $document->summary,
            'readingTime' => max(1, (int) ceil($words / 200)),
            'words' => $words,
            'facts' => collect(Facts::for($document, $documents))
                ->map(fn (array $fact): array => ['key' => $fact['key'], 'label' => $fact['label'], 'value' => $fact['value']])
                ->all(),
            'content' => str(strip_tags((string) $document->content))->replaceMatches('/[#*`>_\-]{1,}/', ' ')->squish()->limit(600)->value(),
            'related' => $related->all(),
            // The entry's own banner only — not the world/site banner, so the preview reflects this entry.
            'bannerUrl' => $document->banner?->url,
            'imageUrl' => $document->card?->url,
        ]);
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $this->persist($world, $this->validated($request, $world));

        return redirect()->route('worlds.templates.index', $world);
    }

    /**
     * Persist a validated template payload: home is a singleton, archive is one-per-section (both upsert),
     * entry templates are free-form. Kept transactional so the default-sync mutation can't half-apply.
     *
     * @param  array{name: string, kind: string, target: string, section: string|null, is_default: bool, layout: array<string, mixed>}  $data
     */
    protected function persist(World $world, array $data): WorldTemplate
    {
        return DB::transaction(function () use ($world, $data): WorldTemplate {
            $template = match ($data['target']) {
                'home' => $world->templates()->updateOrCreate(['target' => 'home'], $data),
                'archive' => $world->templates()->updateOrCreate(['target' => 'archive', 'section' => $data['section']], $data),
                default => $world->templates()->create($data),
            };
            $this->syncDefault($template);

            return $template;
        });
    }

    public function update(Request $request, WorldTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template->world);

        $data = $this->validated($request, $template->world);
        DB::transaction(function () use ($template, $data): void {
            $template->update($data);
            $this->syncDefault($template);
        });

        return redirect()->route('worlds.templates.index', $template->world);
    }

    /** Keep at most one default entry template per kind: clear the flag on the others. */
    protected function syncDefault(WorldTemplate $template): void
    {
        if ($template->target !== 'entry' || ! $template->is_default) {
            return;
        }

        $template->world->templates()
            ->where('target', 'entry')->where('kind', $template->kind)
            ->whereKeyNot($template->id)
            ->update(['is_default' => false]);
    }

    public function destroy(WorldTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template->world);

        $template->delete();

        return back();
    }

    /** Download a template as a portable JSON file that can be imported into any world. */
    public function export(WorldTemplate $template): JsonResponse
    {
        $this->authorize('update', $template->world);

        $target = $template->target ?: 'entry';
        $slug = Str::slug($template->name) ?: 'template';

        return response()->json([
            'worldbuilder_template' => 1,
            // The source world, so re-importing here keeps id references while importing elsewhere strips them.
            'world' => $template->world_id,
            'name' => $template->name,
            'kind' => $template->kind,
            'target' => $target,
            'section' => $template->section,
            'layout' => ['blocks' => TemplateBlocks::normalise($template->layout, $template->kind, $target)],
        ], 200, [
            'Content-Disposition' => "attachment; filename=\"{$slug}.json\"",
        ], JSON_PRETTY_PRINT);
    }

    /** Import a template from an exported JSON blob: sanitised against the declared target, then saved. */
    public function import(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $decoded = json_decode((string) $request->input('payload'), true);
        if (! is_array($decoded) || ! is_array($decoded['layout']['blocks'] ?? null)) {
            return back()->withErrors(['payload' => 'That doesn’t look like an exported template.']);
        }

        $target = in_array($decoded['target'] ?? '', ['home', 'archive'], true) ? $decoded['target'] : 'entry';
        $kind = $target === 'entry' && in_array($decoded['kind'] ?? '', Sections::KINDS, true) ? $decoded['kind'] : 'location';
        $sections = collect(Sections::SECTIONS)->pluck('slug')->all();
        $section = $target === 'archive'
            ? (in_array($decoded['section'] ?? '', $sections, true) ? $decoded['section'] : $sections[0])
            : null;

        // Id references (compendium, map, linked entries, reusable blocks) only make sense within the world
        // they were authored in — strip them unless this is the very world the template came from.
        $blocks = TemplateBlocks::sanitise($decoded['layout']['blocks'], true, $target);
        if (($decoded['world'] ?? null) !== $world->id) {
            $blocks = TemplateBlocks::stripReferences($blocks);
        }

        $this->persist($world, [
            'name' => mb_substr(trim((string) ($decoded['name'] ?? 'Imported template')), 0, 80) ?: 'Imported template',
            'kind' => $target === 'entry' ? $kind : '',
            'target' => $target,
            'section' => $section,
            'is_default' => false,
            'layout' => ['blocks' => $blocks],
        ]);

        return redirect()->route('worlds.templates.index', $world);
    }

    /**
     * @return array{name: string, kind: string, target: string, section: string|null, is_default: bool, layout: array<string, mixed>}
     */
    protected function validated(Request $request, World $world): array
    {
        $target = in_array($request->input('target'), ['home', 'archive'], true) ? $request->input('target') : 'entry';

        // The visual builder sends a block list; sanitise it against the target's catalogue and store it.
        // (An older dropdown payload without blocks still validates below and is normalised on read.)
        if ($request->has('layout.blocks')) {
            $rules = [
                'name' => ['required', 'string', 'max:80'],
                'layout.blocks' => ['present', 'array', 'max:40'],
                'layout.blocks.*.type' => ['required', 'string', Rule::in(TemplateBlocks::typeKeys($target))],
                'layout.blocks.*.id' => ['sometimes', 'nullable', 'string', 'max:64'],
                'layout.blocks.*.settings' => ['sometimes', 'array'],
                'layout.blocks.*.css' => ['sometimes', 'nullable', 'string', 'max:5000'],
                // The conditional-visibility rule; its internals are validated in TemplateBlocks::sanitise.
                'layout.blocks.*.visibleIf' => ['sometimes', 'nullable', 'array'],
                // The responsive "show on" setting (all | desktop | mobile).
                'layout.blocks.*.device' => ['sometimes', 'nullable', 'string'],
            ];
            if ($target === 'entry') {
                $rules['kind'] = ['required', Rule::in(Sections::KINDS)];
            }
            if ($target === 'archive') {
                $rules['section'] = ['required', Rule::in(collect(Sections::SECTIONS)->pluck('slug')->all())];
            }
            $data = $request->validate($rules);

            $kind = $target === 'entry' ? $data['kind'] : '';
            $section = $target === 'archive' ? $data['section'] : null;
            $blocks = TemplateBlocks::sanitise($data['layout']['blocks'], true, $target);
            // Constrain any facts block's chosen fields to real keys for this kind (entry templates only).
            if ($target === 'entry') {
                $validKeys = collect(DocFields::for($kind, $world))->pluck('key')->all();
                foreach ($blocks as $index => $block) {
                    if ($block['type'] === 'facts') {
                        $blocks[$index]['settings']['fields'] = collect($block['settings']['fields'] ?? [])
                            ->filter(fn (mixed $key): bool => is_string($key) && in_array($key, $validKeys, true))
                            ->unique()->values()->all();
                    }
                }
            }

            $layout = ['blocks' => $blocks];
            // Entry templates also carry a right-hand sidebar: its own (flat, common-block) list, plus a
            // flag to hide the sidebar entirely. Sanitised against the reusable-block catalogue.
            if ($target === 'entry') {
                $layout['sidebar'] = TemplateBlocks::sanitise((array) $request->input('layout.sidebar', []), true, 'sidebar');
                $layout['hideSidebar'] = $request->boolean('layout.hideSidebar');
                $layout['width'] = in_array($request->input('layout.width'), ['normal', 'narrow', 'wide'], true)
                    ? $request->input('layout.width')
                    : 'normal';
            }

            return [
                'name' => $data['name'],
                'kind' => $kind,
                'target' => $target,
                'section' => $section,
                // Only entry templates can be a kind's default.
                'is_default' => $target === 'entry' && $request->boolean('is_default'),
                'layout' => $layout,
            ];
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'kind' => ['required', Rule::in(Sections::KINDS)],
            'layout.facts' => ['required', 'in:sidebar,top,off'],
            'layout.width' => ['required', 'in:normal,wide'],
            'layout.banner' => ['sometimes', 'in:auto,show,hide'],
            // The ordered subset of the kind's quick-facts fields to show; [] means "all, default order".
            'layout.fields' => ['sometimes', 'array'],
            'layout.fields.*' => ['string', 'max:64'],
        ]);

        // Keep only field keys that actually exist for this kind, in the order the GM chose.
        $validKeys = collect(DocFields::for($data['kind'], $world))->pluck('key')->all();
        $fields = collect($data['layout']['fields'] ?? [])
            ->filter(fn (string $key): bool => in_array($key, $validKeys, true))
            ->unique()
            ->values()
            ->all();

        return [
            'name' => $data['name'],
            'kind' => $data['kind'],
            'target' => 'entry',
            'section' => null,
            'is_default' => $request->boolean('is_default'),
            'layout' => [
                'facts' => $data['layout']['facts'],
                'width' => $data['layout']['width'],
                'banner' => $data['layout']['banner'] ?? 'auto',
                'fields' => $fields,
            ],
        ];
    }
}
