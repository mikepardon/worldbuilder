<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Document;
use App\Models\GeneratorBatch;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\Compendium;
use App\Support\CompendiumFields;
use App\Support\CreditWeights;
use App\Support\Generators;
use App\Support\ItemBlock;
use App\Support\Sections;
use App\Support\Statblock;
use App\Support\WorldContext;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** AI content generators for a world — names, people, places, items, encounters, hooks a GM can drop in. */
class GeneratorController extends Controller
{
    public function index(Request $request, World $world, AnthropicClient $ai): Response
    {
        $this->authorize('editContent', $world);

        return Inertia::render('Worlds/Generators', [
            'world' => WorldNav::for($world),
            'campaign' => ['id' => $world->id, 'name' => $world->name],
            'generators' => Generators::all(),
            'aiConfigured' => $ai->configured(),
            'creditsRemaining' => $request->user()->aiCreditsRemaining(),
            'batches' => $this->recentBatches($world),
        ]);
    }

    /** Generate a batch of results. XHR (JSON) so results can be reviewed before anything is saved. */
    public function run(Request $request, World $world, AnthropicClient $ai): JsonResponse
    {
        $this->authorize('editContent', $world);

        // Manual JSON validation: this XHR endpoint isn't under api/*, so a failed $request->validate()
        // would redirect rather than return JSON (see shouldRenderJsonWhen in bootstrap/app.php).
        $validator = Validator::make($request->all(), [
            'kind' => ['required', 'string', 'in:'.implode(',', array_keys(Generators::KINDS))],
            'count' => ['nullable', 'integer', 'min:1', 'max:8'],
            'context' => ['nullable', 'string', 'max:300'],
            'options' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }
        $data = $validator->validated();

        if (! $ai->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('generator', $data['kind']);
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $generator = Generators::find($data['kind']);
        $count = (int) ($data['count'] ?? 5);
        $context = trim((string) ($data['context'] ?? ''));
        $options = is_array($data['options'] ?? null) ? $data['options'] : [];
        $wantsBlock = $this->wantsStatblock($generator, $options);
        $wantsItem = $data['kind'] === 'item';

        $digest = WorldContext::forPrompt($world);

        $system = "You are a worldbuilding generator for the tabletop RPG world \"{$world->name}\".\n"
            .($digest !== ''
                ? 'Here is what already exists in this world. Match its style and tone, reuse or reference '
                    ."these people, places and factions where it fits, and do not duplicate existing entries:\n"
                    .$digest."\n\n"
                : '')
            ."Generate exactly {$count} {$generator['noun']} that fit this world. "
            .$this->fieldInstructions($generator, $options, $wantsBlock, $wantsItem)
            .' Be original and consistent with the world; avoid real-world or trademarked names.'
            ." Respond with ONLY a JSON object and nothing else:\n"
            .$this->responseShape($wantsBlock, $wantsItem);

        $prompt = $context !== '' ? "Focus: {$context}" : 'Surprise me — anything that suits the world.';

        try {
            $raw = $ai->chat(
                $system,
                [['role' => 'user', 'content' => $prompt]],
                $wantsBlock || $wantsItem ? 4000 : 2000,
                new AiUsageContext('generator', $world->id, $user->id, $data['kind']),
            );
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $items = $this->parseItems($raw);
        if ($items === []) {
            return response()->json(['message' => 'The AI returned an unexpected response. Please try again.'], 422);
        }

        $batch = DB::transaction(function () use ($user, $world, $data, $context, $options, $items, $creditCost) {
            $user->spendAiCredits($creditCost);

            return $world->generatorBatches()->create([
                'user_id' => $user->id,
                'kind' => $data['kind'],
                'context' => $context !== '' ? $context : null,
                'options' => $options,
                'items' => $items,
            ]);
        });

        return response()->json([
            'items' => $items,
            'creditsRemaining' => $user->aiCreditsRemaining(),
            'batch' => $this->batchSummary($batch),
            'batches' => $this->recentBatches($world),
        ]);
    }

    /** Reopen a saved batch — its results and the options it was generated with. */
    public function batchShow(Request $request, World $world, GeneratorBatch $batch): JsonResponse
    {
        $this->authorize('editContent', $world);
        abort_unless($batch->world_id === $world->id, 404);

        return response()->json([
            'kind' => $batch->kind,
            'context' => $batch->context,
            'options' => $batch->options,
            'items' => $batch->items,
        ]);
    }

    /** Persist tweaks a GM made to a saved batch's results. */
    public function batchUpdate(Request $request, World $world, GeneratorBatch $batch): JsonResponse
    {
        $this->authorize('editContent', $world);
        abort_unless($batch->world_id === $world->id, 404);

        $validator = Validator::make($request->all(), [
            'items' => ['required', 'array', 'max:8'],
            'items.*.title' => ['required', 'string', 'max:160'],
            'items.*.detail' => ['nullable', 'string', 'max:2000'],
            'items.*.description' => ['nullable', 'string', 'max:8000'],
            'items.*.block' => ['nullable', 'array'],
            'items.*.item' => ['nullable', 'array'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first()], 422);
        }

        $batch->update(['items' => $this->cleanItems($validator->validated()['items'])]);

        return response()->json(['items' => $batch->items, 'batches' => $this->recentBatches($world)]);
    }

    public function batchDestroy(Request $request, World $world, GeneratorBatch $batch): JsonResponse
    {
        $this->authorize('editContent', $world);
        abort_unless($batch->world_id === $world->id, 404);

        $batch->delete();

        return response()->json(['batches' => $this->recentBatches($world)]);
    }

    /** Turn a generated result into a world entry (Document), seeded with everything the batch produced. */
    public function create(Request $request, World $world): RedirectResponse
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', Sections::KINDS)],
            'title' => ['required', 'string', 'max:160'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:8000'],
            'meta' => ['nullable', 'array', 'max:12'],
            'meta.*' => ['nullable', 'string', 'max:200'],
            'block' => ['nullable', 'array'],
            'item' => ['nullable', 'array'],
        ]);

        $title = trim($data['title']);

        $document = $world->documents()->create([
            'user_id' => $request->user()->id,
            'title' => $title,
            'slug' => Document::uniqueSlug($world->id, $data['kind'], $title),
            'kind' => $data['kind'],
            'content' => $this->composeMarkdown($title, $data),
            'summary' => Str::limit(trim((string) ($data['detail'] ?? '')), 250, ''),
            'is_private' => false,
        ]);

        return redirect()->route('documents.edit', $document);
    }

    /** Turn a generated result into a compendium stat block (CampaignCompendiumItem), ready to refine. */
    public function importStatblock(Request $request, World $world): RedirectResponse
    {
        $this->authorize('editContent', $world);

        $data = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', Compendium::keys())],
            'title' => ['required', 'string', 'max:160'],
            'detail' => ['nullable', 'string', 'max:2000'],
            'description' => ['nullable', 'string', 'max:8000'],
            'meta' => ['nullable', 'array', 'max:12'],
            'meta.*' => ['nullable', 'string', 'max:200'],
            'block' => ['nullable', 'array'],
            'item' => ['nullable', 'array'],
        ]);

        $title = trim($data['title']);
        $type = $data['type'];

        // Monsters carry a structured stat block; typed items (magic items, equipment, …) carry the
        // compendium's schema fields; anything else just gets the composed markdown body.
        [$document, $fields] = match (true) {
            $type === 'monster' => [
                Statblock::toMarkdown($block = Statblock::sanitise($data['block'] ?? null), $title),
                ['block' => $block],
            ],
            $type === 'magicitem' && is_array($data['item'] ?? null) => (function () use ($data, $title) {
                $fields = ItemBlock::toFields(ItemBlock::sanitise($data['item']), (string) ($data['description'] ?? ''));

                return [CompendiumFields::toMarkdown('magicitem', $fields, $title), $fields];
            })(),
            default => [$this->composeMarkdown($title, $data), []],
        };

        $item = $world->compendiumItems()->create([
            'user_id' => $request->user()->id,
            'item_type' => $type,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'name' => $title,
            'summary' => Str::limit(trim((string) ($data['detail'] ?? '')), 250, ''),
            'document' => $document,
            'fields' => $fields,
            'provider' => 'custom',
            'is_private' => true,
            'is_active' => true,
        ]);

        return redirect()->route('compendium.edit', $item);
    }

    /**
     * @return list<array{id: int, kind: string, label: string, context: ?string, count: int, ago: string}>
     */
    private function recentBatches(World $world): array
    {
        return $world->generatorBatches()
            ->latest()
            ->limit(15)
            ->get()
            ->map(fn (GeneratorBatch $batch) => $this->batchSummary($batch))
            ->all();
    }

    /** @return array{id: int, kind: string, label: string, context: ?string, count: int, ago: string} */
    private function batchSummary(GeneratorBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'kind' => $batch->kind,
            'label' => Generators::find($batch->kind)['label'] ?? Str::title($batch->kind),
            'context' => $batch->context,
            'count' => count($batch->items ?? []),
            'ago' => $batch->created_at->diffForHumans(),
        ];
    }

    /** @param array{options: list<array{key: string, type: string, default: bool|string}>} $generator */
    private function wantsStatblock(array $generator, array $options): bool
    {
        foreach ($generator['options'] as $option) {
            if ($option['key'] === 'statblock') {
                return (bool) ($options['statblock'] ?? $option['default']);
            }
        }

        return false;
    }

    private function responseShape(bool $wantsBlock, bool $wantsItem): string
    {
        $block = $wantsBlock
            ? ',"block":{"size":"…","type":"…","alignment":"…","ac":"…","hp":"…","speed":"…","cr":"…",'
                .'"abilities":{"str":10,"dex":10,"con":10,"int":10,"wis":10,"cha":10},'
                .'"traits":[{"name":"…","desc":"…"}],"actions":[{"name":"…","desc":"…"}]}'
            : '';

        $item = $wantsItem
            ? ',"item":{"category":"…","rarity":"…","attunement":"…","mechanics":"…"}'
            : '';

        return '{"items":[{"title":"…","detail":"…","description":"…"'.$block.$item.'}]}';
    }

    /**
     * Build the "which fields to fill" instruction for the system prompt from the chosen options.
     *
     * @param  array{options: list<array{key: string, type: string, default: bool|string, choices?: list<string>}>}  $generator
     * @param  array<string, mixed>  $options
     */
    private function fieldInstructions(array $generator, array $options, bool $wantsBlock, bool $wantsItem = false): string
    {
        $wantDescription = false;
        $directives = [];

        foreach ($generator['options'] as $option) {
            $key = $option['key'];
            $value = $options[$key] ?? $option['default'];

            if ($option['type'] === 'select') {
                $choice = is_string($value) ? trim($value) : '';
                if ($choice !== '' && $choice !== 'any') {
                    $directives[] = $this->selectDirective($key, $choice);
                }

                continue;
            }

            if (! (bool) $value) {
                continue;
            }

            match ($key) {
                'description' => $wantDescription = true,
                'role' => $directives[] = 'Give each a clear role or occupation, woven into the detail.',
                'goal' => $directives[] = 'Give each a goal or agenda, woven into the detail.',
                default => null,
            };
        }

        $instruction = 'Each result has a short "title" and one evocative sentence of "detail". '
            .($wantDescription
                ? 'Also fill "description" with a vivid two-to-three sentence description. '
                : 'Leave "description" as an empty string. ')
            .($wantsBlock
                ? 'Also fill "block" with a complete D&D 5e stat block: size, type, alignment, ac, hp, speed, '
                    .'cr (all strings), an "abilities" object of six integer scores (str, dex, con, int, wis, cha), '
                    .'and "traits" and "actions" arrays of {name, desc} objects. '
                : '')
            .($wantsItem
                ? 'Also fill "item" with D&D 5e magic-item mechanics — make it genuinely useful at the table, '
                    .'not just flavour: "category" (one of Wondrous item, Weapon, Armor, Ring, Rod, Staff, Wand, '
                    .'Potion, Scroll), "rarity" (Common, Uncommon, Rare, Very rare, Legendary, or Artifact), '
                    .'"attunement" ("No", or "Yes" with any requirement), and "mechanics": concrete rules in the '
                    .'clipped style of the Dungeon Master\'s Guide — bonuses to AC, attack and damage rolls, saving '
                    .'throws, charges and the effects they power, activation and any drawbacks. '
                : '');

        return $instruction.implode(' ', $directives);
    }

    private function selectDirective(string $key, string $value): string
    {
        return match ($key) {
            'subject' => "Produce names suited to {$value}.",
            'place_type' => "They should be of type: {$value}.",
            'item_type' => "They should be {$value}s.",
            'rarity' => "Rarity: {$value}.",
            'difficulty' => "Encounter difficulty: {$value}.",
            default => "{$key}: {$value}.",
        };
    }

    /**
     * Compose a Markdown body from a generated result (title + detail + optional meta/description/block).
     *
     * @param  array{detail?: ?string, description?: ?string, meta?: array<string, ?string>, block?: ?array<string, mixed>}  $data
     */
    private function composeMarkdown(string $title, array $data): string
    {
        $detail = trim((string) ($data['detail'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));

        $metaLine = collect($data['meta'] ?? [])
            ->filter(fn ($value) => is_string($value) && trim($value) !== '' && trim($value) !== 'any')
            ->map(fn ($value, $key) => Str::ucfirst($key).': '.trim((string) $value))
            ->values()
            ->implode(' · ');

        // A generated item's own type/rarity/attunement supersede the coarser select-driven meta line.
        $item = is_array($data['item'] ?? null) ? ItemBlock::sanitise($data['item']) : null;

        $sections = ["# {$title}"];
        if ($detail !== '') {
            $sections[] = $detail;
        }
        if ($item !== null) {
            $attunement = $item['attunement'] !== '' && $item['attunement'] !== 'No'
                ? " (requires attunement{$this->attunementSuffix($item['attunement'])})"
                : '';
            $sections[] = "> {$item['category']}, {$item['rarity']}{$attunement}";
            if ($item['mechanics'] !== '') {
                $sections[] = $item['mechanics'];
            }
        } elseif ($metaLine !== '') {
            $sections[] = "> {$metaLine}";
        }
        if ($description !== '') {
            $sections[] = $description;
        }
        if (is_array($data['block'] ?? null)) {
            $sections[] = '## Stat block';
            $sections[] = Statblock::toMarkdown(Statblock::sanitise($data['block']), $title);
        }

        return implode("\n\n", $sections)."\n";
    }

    /** Turn an item's attunement value ("Yes", or "Yes by a spellcaster") into a trailing requirement phrase. */
    private function attunementSuffix(string $attunement): string
    {
        $requirement = Str::startsWith(Str::lower($attunement), 'yes')
            ? Str::substr($attunement, 3)
            : $attunement;
        $requirement = trim($requirement, ' ,:-()');

        return $requirement !== '' && Str::lower($requirement) !== 'yes' ? " {$requirement}" : '';
    }

    /**
     * Normalise a batch's items (from AI JSON or from an edit) to the stored shape.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array{title: string, detail: string, description: string, block: ?array<string, mixed>, item: ?array{category: string, rarity: string, attunement: string, mechanics: string}}>
     */
    private function cleanItems(array $items): array
    {
        return collect($items)
            ->map(fn ($item) => [
                'title' => is_array($item) && is_string($item['title'] ?? null) ? trim($item['title']) : '',
                'detail' => is_array($item) && is_string($item['detail'] ?? null) ? trim($item['detail']) : '',
                'description' => is_array($item) && is_string($item['description'] ?? null) ? trim($item['description']) : '',
                'block' => is_array($item) && is_array($item['block'] ?? null) ? Statblock::sanitise($item['block']) : null,
                'item' => is_array($item) && is_array($item['item'] ?? null) ? ItemBlock::sanitise($item['item']) : null,
            ])
            ->filter(fn (array $item) => $item['title'] !== '')
            ->values()
            ->all();
    }

    /**
     * Keep only well-formed results; other fields default to empty. A structured `block` is kept only
     * when the model returned one.
     *
     * @return list<array{title: string, detail: string, description: string, block: ?array<string, mixed>, item: ?array{category: string, rarity: string, attunement: string, mechanics: string}}>
     */
    private function parseItems(string $raw): array
    {
        $parsed = AiJson::object($raw);
        $items = is_array($parsed['items'] ?? null) ? $parsed['items'] : [];

        return $this->cleanItems($items);
    }
}
