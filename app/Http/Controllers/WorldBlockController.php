<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\World;
use App\Models\WorldBlock;
use App\Support\TemplateBlocks;
use App\Support\TemplateBuilderData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Reusable block sets a world defines once and references from many templates. Owner-managed. */
class WorldBlockController extends Controller
{
    /** The builder for a new reusable block set. */
    public function create(World $world): Response
    {
        $this->authorize('update', $world);

        return Inertia::render('Worlds/TemplateBuilder', [
            ...TemplateBuilderData::for($world, 'block'),
            'target' => 'block',
            'template' => null,
        ]);
    }

    /** The builder for an existing reusable block set. */
    public function edit(WorldBlock $block): Response
    {
        $this->authorize('update', $block->world);

        return Inertia::render('Worlds/TemplateBuilder', [
            ...TemplateBuilderData::for($block->world, 'block'),
            'target' => 'block',
            'template' => [
                'id' => $block->id,
                'name' => $block->name,
                'kind' => '',
                'target' => 'block',
                'blocks' => TemplateBlocks::normalise($block->layout, '', 'block'),
            ],
        ]);
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        WorldBlock::create(['world_id' => $world->id, ...$this->validated($request)]);

        // "Save as reusable" from inside a template builder asks to stay put (so the in-progress template
        // isn't lost); the dedicated builder returns to the template list.
        return $request->boolean('stay')
            ? back()
            : redirect()->action([WorldTemplateController::class, 'index'], [$world->id]);
    }

    public function update(Request $request, WorldBlock $block): RedirectResponse
    {
        $this->authorize('update', $block->world);

        $block->update($this->validated($request));

        return redirect()->action([WorldTemplateController::class, 'index'], [$block->world_id]);
    }

    public function destroy(WorldBlock $block): RedirectResponse
    {
        $this->authorize('update', $block->world);

        $block->delete();

        return back();
    }

    /** Download a reusable block set as a portable JSON file. */
    public function export(WorldBlock $block): JsonResponse
    {
        $this->authorize('update', $block->world);

        $slug = Str::slug($block->name) ?: 'block';

        return response()->json([
            'worldbuilder_block' => 1,
            // The source world, so re-importing here keeps id references while importing elsewhere strips them.
            'world' => $block->world_id,
            'name' => $block->name,
            'layout' => ['blocks' => TemplateBlocks::normalise($block->layout, '', 'block')],
        ], 200, [
            'Content-Disposition' => "attachment; filename=\"{$slug}.json\"",
        ], JSON_PRETTY_PRINT);
    }

    /** Import a reusable block set from an exported JSON blob. */
    public function import(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $decoded = json_decode((string) $request->input('payload'), true);
        if (! is_array($decoded) || ! is_array($decoded['layout']['blocks'] ?? null)) {
            return back()->withErrors(['payload' => 'That doesn’t look like an exported reusable block.']);
        }

        $blocks = TemplateBlocks::sanitise($decoded['layout']['blocks'], true, 'block');
        if (($decoded['world'] ?? null) !== $world->id) {
            $blocks = TemplateBlocks::stripReferences($blocks);
        }

        WorldBlock::create([
            'world_id' => $world->id,
            'name' => mb_substr(trim((string) ($decoded['name'] ?? 'Imported block')), 0, 80) ?: 'Imported block',
            'layout' => ['blocks' => $blocks],
        ]);

        return redirect()->action([WorldTemplateController::class, 'index'], [$world->id]);
    }

    /**
     * @return array{name: string, layout: array{blocks: list<array<string, mixed>>}}
     */
    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'layout.blocks' => ['present', 'array', 'max:40'],
            'layout.blocks.*.type' => ['required', 'string', Rule::in(TemplateBlocks::typeKeys('block'))],
            'layout.blocks.*.id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'layout.blocks.*.settings' => ['sometimes', 'array'],
            'layout.blocks.*.css' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'layout.blocks.*.visibleIf' => ['sometimes', 'nullable', 'array'],
            'layout.blocks.*.device' => ['sometimes', 'nullable', 'string'],
        ]);

        return [
            'name' => $data['name'],
            'layout' => ['blocks' => TemplateBlocks::sanitise($data['layout']['blocks'], true, 'block')],
        ];
    }
}
