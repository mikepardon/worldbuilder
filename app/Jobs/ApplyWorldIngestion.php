<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CampaignCompendiumItem;
use App\Models\Document;
use App\Models\WorldIngestion;
use App\Models\WorldIngestionChange;
use App\Services\AnthropicClient;
use App\Services\CompendiumDrafter;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\CompendiumFields;
use App\Support\DocFields;
use App\Support\Statblock;
use App\Support\WikiLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * The apply pass of an ingestion: writes the reviewer-approved {@see WorldIngestionChange}s one at a time,
 * generating each entry's content on the way in. Credit-gated — each change costs one credit, and when the
 * account runs dry the run pauses (rather than failing) so it can be resumed after a top-up. Runs as a
 * chain of one-change jobs so no single run can exceed the queue's retry_after, mirroring
 * {@see PopulateWorldFromSeed}.
 */
class ApplyWorldIngestion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 200;

    public function __construct(public WorldIngestion $ingestion) {}

    public function handle(AnthropicClient $ai, CompendiumDrafter $drafter): void
    {
        $ingestion = $this->ingestion->fresh();
        if ($ingestion === null || $ingestion->status !== 'applying') {
            return;
        }

        $approved = $ingestion->proposedChanges()->where('decision', 'approved')->orderBy('id')->get();

        // Everything approved has been processed — link the touched entries and finish.
        if ($ingestion->cursor >= $approved->count()) {
            $this->finish($ingestion);

            return;
        }

        if (! $ai->configured()) {
            $ingestion->markFailed("AI isn't set up on this server yet.");

            return;
        }

        $user = $ingestion->user;
        if ($user === null || ! $user->canSpendAiCredit()) {
            $ingestion->update(['status' => 'paused']);
            $ingestion->pushLog('Paused — out of AI credits. Top up, then resume to finish applying.');

            return;
        }

        $change = $approved[$ingestion->cursor];

        try {
            $this->applyChange($ai, $drafter, $ingestion, $change, $user);
            $ingestion->increment('applied');
        } catch (Throwable $error) {
            // One entry failing must not sink the whole run — mark it and move on.
            $change->update(['status' => 'failed', 'result' => 'Could not be applied.']);
            $ingestion->pushLog("Skipped “{$change->title}” — generation failed.");
            report($error);
        }

        $ingestion->update(['cursor' => $ingestion->cursor + 1]);
        dispatch(new self($ingestion));
    }

    private function applyChange(AnthropicClient $ai, CompendiumDrafter $drafter, WorldIngestion $ingestion, WorldIngestionChange $change, mixed $user): void
    {
        if ($change->target === 'compendium') {
            $this->applyCompendium($drafter, $ingestion, $change, $user);

            return;
        }

        $this->applyDocument($ai, $ingestion, $change, $user);
    }

    private function applyDocument(AnthropicClient $ai, WorldIngestion $ingestion, WorldIngestionChange $change, mixed $user): void
    {
        $world = $ingestion->world;
        $notes = trim((string) $ingestion->source_text);
        $brief = trim((string) ($change->instruction ?: $change->title));

        $existing = $change->action === 'update' && $change->document_id !== null
            ? Document::find($change->document_id)
            : null;

        if ($existing !== null) {
            [$summary, $content] = $this->writeDocumentUpdate($ai, $world, $existing, $brief, $notes, $user);
            if ($content === '') {
                throw new RuntimeException('Empty document update.');
            }

            $existing->update([
                'content' => $content,
                'summary' => $summary !== '' ? Str::limit($summary, 250, '') : $existing->summary,
            ]);
            WikiLinks::sync($existing);

            $change->update(['status' => 'applied', 'result' => 'Updated', 'document_id' => $existing->id]);
            $this->tally($ingestion, $change->kind);
            $ingestion->pushLog("Updated “{$existing->title}”.");

            return;
        }

        $fields = DocFields::for($change->kind, $world);
        [$summary, $content, $facts] = $this->writeDocumentCreate($ai, $world, $change, $brief, $notes, $fields, $user);
        if ($content === '') {
            throw new RuntimeException('Empty document content.');
        }

        $document = $world->documents()->create([
            'user_id' => $ingestion->user_id,
            'title' => $change->title,
            'slug' => Document::uniqueSlug($world->id, $change->kind, $change->title),
            'kind' => $change->kind,
            'content' => $content,
            'summary' => Str::limit($summary, 250, ''),
            'data' => $this->sanitiseFacts($facts, $fields),
        ]);
        WikiLinks::sync($document);

        $change->update(['status' => 'applied', 'result' => 'Created', 'document_id' => $document->id]);
        $this->tally($ingestion, $change->kind);
        $ingestion->pushLog("Created “{$document->title}”.");
    }

    private function applyCompendium(CompendiumDrafter $drafter, WorldIngestion $ingestion, WorldIngestionChange $change, mixed $user): void
    {
        $world = $ingestion->world;

        $item = $change->action === 'update' && $change->campaign_compendium_item_id !== null
            ? CampaignCompendiumItem::find($change->campaign_compendium_item_id)
            : null;
        $verb = $item !== null ? 'Updated' : 'Created';
        $justCreated = false;

        if ($item === null) {
            $item = $world->compendiumItems()->create([
                'user_id' => $ingestion->user_id,
                'item_type' => $change->kind,
                'slug' => Str::slug($change->title).'-'.Str::lower(Str::random(4)),
                'name' => $change->title,
                'provider' => 'custom',
                'fields' => $change->kind === 'monster' ? ['block' => Statblock::empty()] : [],
                'document' => $change->kind === 'monster' ? Statblock::toMarkdown(Statblock::empty(), $change->title) : '',
            ]);
            $justCreated = true;
        }

        $currentBlock = (array) (data_get($item->fields, 'block') ?? []);
        $currentFields = (array) ($item->fields ?? []);
        $brief = trim((string) ($change->instruction ?: "Draft this {$change->kind}."));

        $result = $drafter->draft(
            $item->item_type, $item->name, $world->name, $currentBlock, $currentFields, (string) $item->document,
            $brief, [], new AiUsageContext('world_ingest', $world->id, $user->id, 'apply'),
        );
        $user->spendAiCredit();

        if ($result === null) {
            if ($justCreated) {
                $item->delete();
            }
            throw new RuntimeException('The AI returned an unexpected compendium response.');
        }

        // Persist exactly as the compendium editor's save does: the structured data is the source of truth
        // and the Markdown document is regenerated from it.
        if ($item->item_type === 'monster' && is_array($result['block'])) {
            $fields = $item->fields ?? [];
            $fields['block'] = $result['block'];
            $item->fields = $fields;
            $item->document = Statblock::toMarkdown($result['block'], $item->name);
        } elseif (CompendiumFields::has($item->item_type) && is_array($result['fields'])) {
            $merged = array_merge($item->fields ?? [], $result['fields']);
            $item->fields = $merged;
            $item->document = CompendiumFields::toMarkdown($item->item_type, $merged, $item->name);
        }

        if (filled($result['summary'])) {
            $item->summary = Str::limit($result['summary'], 250, '');
        }
        $item->save();

        $change->update(['status' => 'applied', 'result' => $verb, 'campaign_compendium_item_id' => $item->id]);
        $this->tally($ingestion, $change->kind);
        $ingestion->pushLog("{$verb} compendium entry “{$item->name}”.");
    }

    /**
     * Generate a brand-new wiki entry's body from the notes + brief.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function writeDocumentCreate(AnthropicClient $ai, mixed $world, WorldIngestionChange $change, string $brief, string $notes, array $fields, mixed $user): array
    {
        $factKeys = collect($fields)
            ->reject(fn (array $field): bool => ($field['type'] ?? 'text') === 'reference')
            ->map(fn (array $field): string => $field['key'].' ('.$field['label'].')')
            ->implode(', ');

        $system = "You are writing a wiki entry for the world \"{$world->name}\", grounded ONLY in the GM's notes. Return ONE JSON object and nothing else:\n"
            .'{"summary": "one sentence", "content": "Markdown body", "facts": {"key": "value"}}'."\n"
            ."- \"content\": a few short paragraphs of Markdown for the {$change->kind} entry \"{$change->title}\". Use ## subheadings only where they help, and wrap the names of other world entries in [[double brackets]] to link them.\n"
            .($factKeys !== '' ? "- \"facts\": fill only these quick-fact keys where the notes support them (omit the rest): {$factKeys}.\n" : "- \"facts\": {}.\n")
            .'- Invent nothing beyond the notes.';

        $userContent = "NOTES:\n{$notes}\n\nBRIEF for \"{$change->title}\": {$brief}";

        $raw = $ai->chat($system, [['role' => 'user', 'content' => $userContent]], 3000, new AiUsageContext('world_ingest', $world->id, $user->id, 'apply'), 120);
        $user->spendAiCredit();

        $parsed = AiJson::object($raw);
        $facts = data_get($parsed, 'facts');

        return [
            trim((string) data_get($parsed, 'summary', '')),
            trim((string) data_get($parsed, 'content', '')),
            is_array($facts) ? $facts : [],
        ];
    }

    /**
     * Rewrite an existing entry, folding the new notes in per the brief.
     *
     * @return array{0: string, 1: string}
     */
    private function writeDocumentUpdate(AnthropicClient $ai, mixed $world, Document $document, string $brief, string $notes, mixed $user): array
    {
        $system = "You are revising the existing wiki entry \"{$document->title}\" ({$document->kind}) in the world \"{$world->name}\". Fold the new information from the notes into it. Return ONE JSON object and nothing else:\n"
            .'{"summary": "one sentence", "content": "the COMPLETE updated Markdown entry"}'."\n"
            ."- Keep everything still true; add or adjust only what the notes support. Preserve the entry's existing structure and [[wiki-links]], adding new ones where they fit.\n"
            .'- "content" must be the whole entry, not a fragment. Invent nothing beyond the notes.';

        $userContent = "CURRENT ENTRY:\n{$document->content}\n\nNOTES:\n{$notes}\n\nBRIEF: {$brief}";

        $raw = $ai->chat($system, [['role' => 'user', 'content' => $userContent]], 3500, new AiUsageContext('world_ingest', $world->id, $user->id, 'apply'), 120);
        $user->spendAiCredit();

        $parsed = AiJson::object($raw);

        return [
            trim((string) data_get($parsed, 'summary', '')),
            trim((string) data_get($parsed, 'content', '')),
        ];
    }

    private function finish(WorldIngestion $ingestion): void
    {
        $applied = $ingestion->proposedChanges()->where('status', 'applied')->count();
        $ingestion->markCompleted($applied === 0
            ? 'Nothing was applied.'
            : "Applied {$applied} ".Str::plural('change', $applied).' from your notes.');
    }

    /** Bump the per-kind tally shown in the completion summary. */
    private function tally(WorldIngestion $ingestion, string $kind): void
    {
        $counts = $ingestion->counts ?? [];
        $counts[$kind] = ($counts[$kind] ?? 0) + 1;
        $ingestion->update(['counts' => $counts]);
    }

    /**
     * Keep only quick-facts the kind defines (reference fields excluded — those come from [[wiki-links]]).
     *
     * @param  array<string, mixed>  $facts
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function sanitiseFacts(array $facts, array $fields): array
    {
        $byKey = collect($fields)->keyBy('key');

        $data = [];
        foreach ($facts as $key => $value) {
            $field = $byKey->get($key);
            if (! is_array($field) || ($field['type'] ?? 'text') === 'reference') {
                continue;
            }

            if ($field['multiple'] ?? false) {
                $list = collect(is_array($value) ? $value : [$value])
                    ->map(fn (mixed $item): string => trim((string) $item))
                    ->filter()->values()->all();
                if ($list !== []) {
                    $data[$key] = $list;
                }

                continue;
            }

            $string = trim((string) (is_array($value) ? reset($value) : $value));
            if ($string !== '') {
                $data[$key] = $string;
            }
        }

        return $data;
    }

    public function failed(Throwable $exception): void
    {
        $this->ingestion->fresh()?->markFailed('Something went wrong while applying your notes. Please try again.');
    }
}
