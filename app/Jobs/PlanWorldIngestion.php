<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CampaignCompendiumItem;
use App\Models\Document;
use App\Models\WorldIngestion;
use App\Services\AnthropicClient;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * The cheap first pass of an ingestion: read the user's freeform notes against the world's existing
 * entries and propose a reviewable plan of {@see \App\Models\WorldIngestionChange}s (create or update,
 * documents or compendium). No content is generated or written here — that happens in the credit-gated
 * {@see ApplyWorldIngestion} once the user has approved a subset. One AI call, one credit.
 */
class PlanWorldIngestion implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 200;

    /** Document kinds the plan may target (structured kinds like bloodline/timeline are excluded). */
    private const DOC_KINDS = ['npc', 'location', 'faction', 'item', 'lore', 'article'];

    /** Compendium item types the plan may target. */
    private const COMPENDIUM_KINDS = ['monster', 'spell'];

    private const PLAN_CAP = 40;

    /** Existing entries listed to the model, capped so a large world can't blow the prompt. */
    private const INDEX_CAP = 300;

    public function __construct(public WorldIngestion $ingestion) {}

    public function handle(AnthropicClient $ai): void
    {
        $ingestion = $this->ingestion->fresh();
        if ($ingestion === null || $ingestion->status !== 'planning') {
            return;
        }

        try {
            $this->plan($ai, $ingestion);
        } catch (Throwable $error) {
            $ingestion->markFailed('Something went wrong while reviewing your notes. Please try again.');
            throw $error;
        }
    }

    private function plan(AnthropicClient $ai, WorldIngestion $ingestion): void
    {
        $world = $ingestion->world;
        $user = $ingestion->user;
        $notes = trim((string) $ingestion->source_text);

        if ($notes === '') {
            $ingestion->markFailed('There were no notes to review.');

            return;
        }

        if (! $ai->configured()) {
            $ingestion->markFailed("AI isn't set up on this server yet.");

            return;
        }

        if ($user === null || ! $user->canSpendAiCredit()) {
            $ingestion->markFailed('You’re out of AI credits — they reset daily. Top up or upgrade for more.');

            return;
        }

        $ingestion->pushLog('Reviewing your notes against the world…');

        $documents = $world->documents()->get(['id', 'title', 'kind']);
        $items = $world->compendiumItems()->get(['id', 'name', 'item_type']);
        $docsByTitle = $documents->keyBy(fn (Document $doc): string => Str::lower($doc->title));
        $itemsByName = $items->keyBy(fn (CampaignCompendiumItem $item): string => Str::lower($item->name));

        $proposed = $this->proposeChanges($ai, $world, $notes, $documents, $items, $user);

        $created = 0;
        foreach ($proposed as $change) {
            [$target, $kind] = $this->normaliseTarget($change);
            $title = trim((string) data_get($change, 'title', ''));
            if ($title === '') {
                continue;
            }

            $action = data_get($change, 'action') === 'update' ? 'update' : 'create';
            $documentId = null;
            $itemId = null;

            // An "update" only stands if it actually matches an existing entry of that target; otherwise it
            // becomes a "create" so we never try to edit something that isn't there.
            if ($action === 'update') {
                if ($target === 'document') {
                    $documentId = $docsByTitle->get(Str::lower($title))?->id;
                } else {
                    $itemId = $itemsByName->get(Str::lower($title))?->id;
                }

                if ($documentId === null && $itemId === null) {
                    $action = 'create';
                }
            }

            $ingestion->proposedChanges()->create([
                'action' => $action,
                'target' => $target,
                'kind' => $kind,
                'title' => $title,
                'rationale' => trim((string) data_get($change, 'rationale', '')) ?: null,
                'instruction' => trim((string) data_get($change, 'instruction', '')) ?: null,
                'document_id' => $documentId,
                'campaign_compendium_item_id' => $itemId,
                'decision' => 'pending',
                'status' => 'pending',
            ]);

            $created++;
            if ($created >= self::PLAN_CAP) {
                break;
            }
        }

        if ($created === 0) {
            $ingestion->markFailed('The AI found nothing to add or change from those notes. Try adding more detail.');

            return;
        }

        $ingestion->update(['status' => 'ready']);
        $ingestion->pushLog("Proposed {$created} ".Str::plural('change', $created).'. Review and choose what to apply.');
    }

    /**
     * The AI proposal call: a flat list of create/update changes grounded in the notes, aware of what
     * already exists so it can update rather than duplicate.
     *
     * @param  \Illuminate\Support\Collection<int, Document>  $documents
     * @param  \Illuminate\Support\Collection<int, CampaignCompendiumItem>  $items
     * @return list<array<string, mixed>>
     */
    private function proposeChanges(AnthropicClient $ai, mixed $world, string $notes, mixed $documents, mixed $items, mixed $user): array
    {
        $index = $this->existingIndex($documents, $items);
        $docKinds = implode('|', self::DOC_KINDS);
        $compKinds = implode('|', self::COMPENDIUM_KINDS);

        $system = "You are helping a GM extend the existing worldbuilding wiki for the world \"{$world->name}\".\n"
            ."You are given the world's EXISTING entries and some NEW notes. Propose a plan of changes to fold the notes into the wiki. Return ONE JSON object and nothing else:\n"
            .'{"changes": [{"action": "create|update", "target": "document|compendium", "kind": "…", "title": "…", "rationale": "one sentence", "instruction": "…"}]}'."\n"
            ."- action = update ONLY when the notes add to or change an entry that ALREADY EXISTS — use its EXACT existing title. Otherwise action = create.\n"
            ."- target = document for people, places, groups, items, lore and events (kind: {$docKinds}). target = compendium for game stat blocks the notes describe (kind: {$compKinds}).\n"
            ."- title: the entry's name. For an update, match the existing title exactly.\n"
            ."- rationale: one short sentence telling the reviewer what this change does.\n"
            ."- instruction: a concise brief the writer will later follow to produce the entry, grounded ONLY in the notes. Do NOT write the full entry now.\n"
            .'- Only propose things grounded in the notes — invent nothing. Up to '.self::PLAN_CAP.' changes.';

        $userContent = "EXISTING ENTRIES:\n".($index === '' ? '(none yet)' : $index)."\n\nNEW NOTES:\n{$notes}";

        $raw = $ai->chat($system, [['role' => 'user', 'content' => $userContent]], 4000, new AiUsageContext('world_ingest', $world->id, $user->id, 'plan'), 120);
        $user->spendAiCredit();

        $parsed = AiJson::object($raw);

        return is_array($parsed['changes'] ?? null) ? $parsed['changes'] : [];
    }

    /**
     * A compact list of what already exists, so the model can update rather than duplicate.
     *
     * @param  \Illuminate\Support\Collection<int, Document>  $documents
     * @param  \Illuminate\Support\Collection<int, CampaignCompendiumItem>  $items
     */
    private function existingIndex(mixed $documents, mixed $items): string
    {
        $lines = collect();
        foreach ($documents as $doc) {
            $lines->push("- [document/{$doc->kind}] {$doc->title}");
        }
        foreach ($items as $item) {
            $lines->push("- [compendium/{$item->item_type}] {$item->name}");
        }

        return $lines->take(self::INDEX_CAP)->implode("\n");
    }

    /**
     * Coerce a proposed change's target + kind into an allowed (target, kind) pair, defaulting sensibly
     * when the model returns something off-list.
     *
     * @param  array<string, mixed>  $change
     * @return array{0: string, 1: string}
     */
    private function normaliseTarget(array $change): array
    {
        $target = data_get($change, 'target') === 'compendium' ? 'compendium' : 'document';
        $kind = Str::lower(trim((string) data_get($change, 'kind', '')));

        if ($target === 'compendium') {
            return in_array($kind, self::COMPENDIUM_KINDS, true)
                ? ['compendium', $kind]
                : ['compendium', 'monster'];
        }

        return in_array($kind, self::DOC_KINDS, true)
            ? ['document', $kind]
            : ['document', 'article'];
    }

    public function failed(Throwable $exception): void
    {
        $this->ingestion->fresh()?->markFailed('Something went wrong while reviewing your notes. Please try again.');
    }
}
