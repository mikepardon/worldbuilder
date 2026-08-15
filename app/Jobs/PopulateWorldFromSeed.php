<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Document;
use App\Models\WorldBuild;
use App\Services\AnthropicClient;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\DocFields;
use App\Support\WikiLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reads a world's seed notes and builds a batch of wiki entries from them with AI, off the web request
 * so long, multi-call generation can't hit the gateway timeout. Runs in two passes: first an outline of
 * every entry worth making, then the full text of each in small batches, streaming progress into the
 * {@see WorldBuild} record the dashboard polls.
 */
class PopulateWorldFromSeed implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** A partial build must not be silently re-run and duplicated. */
    public int $tries = 1;

    /** One run does one short unit of work, so it must comfortably finish inside the queue's retry_after. */
    public int $timeout = 200;

    /** The kinds the AI may classify entries into (structured kinds like bloodline/timeline are excluded). */
    private const KINDS = ['npc', 'location', 'faction', 'item', 'lore', 'article'];

    private const OUTLINE_CAP = 80;

    private const BATCH_SIZE = 5;

    public function __construct(public WorldBuild $build) {}

    /**
     * The build runs as a chain of short jobs rather than one long one, so no single run can exceed the
     * queue's retry_after (which would get the still-running job re-reserved and fail it). Each run does
     * exactly one unit — plan the outline, write one batch of entries, or link them — then re-dispatches
     * itself for the next unit until the outline is exhausted.
     */
    public function handle(AnthropicClient $ai): void
    {
        $build = $this->build->fresh();
        if ($build === null || $build->isFinished()) {
            return;
        }

        try {
            // Phase one: no outline yet — plan what to build, then hand off to the first batch.
            if ($build->outline === null) {
                $this->planOutline($ai, $build);

                return;
            }

            // Phase two: still entries left to write — write the next batch, then the one after.
            if ($build->cursor < count($build->outline)) {
                $this->writeNextBatch($ai, $build);

                return;
            }

            // Phase three: everything is written — resolve the [[wiki-links]] and finish.
            $this->linkAndFinish($build);
        } catch (Throwable $error) {
            $build->markFailed('Something went wrong while building your world. Please try again.');
            throw $error;
        }
    }

    private function planOutline(AnthropicClient $ai, WorldBuild $build): void
    {
        $build->markRunning();

        $world = $build->world;
        $user = $build->user;
        $seed = trim((string) $world->setting);

        if ($seed === '') {
            $build->markFailed('This world has no seed notes to build from. Add a description first.');

            return;
        }

        if (! $ai->configured()) {
            $build->markFailed("AI isn't set up on this server yet.");

            return;
        }

        if ($user === null || ! $user->canSpendAiCredit()) {
            $build->markFailed('You’re out of AI credits for today — they reset daily. Top up or upgrade for more.');

            return;
        }

        $build->pushLog('Reading your notes…');
        $outline = $this->outline($ai, $world, $seed, $user);

        if ($outline === []) {
            $build->markFailed('The AI could not find anything to build from those notes. Try adding more detail.');

            return;
        }

        $build->update(['outline' => $outline, 'planned' => count($outline), 'cursor' => 0]);
        $build->pushLog('Found '.count($outline).' entries to write. Building…');

        dispatch(new self($build));
    }

    private function writeNextBatch(AnthropicClient $ai, WorldBuild $build): void
    {
        $world = $build->world;
        $user = $build->user;
        $outline = $build->outline;

        // Stop cleanly if the account has run dry mid-build rather than failing the whole run.
        if ($user === null || ! $user->canSpendAiCredit()) {
            $build->pushLog('Ran out of AI credits — finishing with what’s built so far.');
            $this->linkAndFinish($build);

            return;
        }

        $seed = trim((string) $world->setting);
        $allTitles = array_column($outline, 'title');
        $privacy = [];
        foreach ($outline as $entity) {
            $privacy[Str::lower($entity['title'])] = $entity['private'];
        }

        $batch = array_slice($outline, $build->cursor, self::BATCH_SIZE);
        $fieldsByKind = [];
        foreach (array_unique(array_column($batch, 'kind')) as $kind) {
            $fieldsByKind[$kind] = DocFields::for($kind, $world);
        }

        $entries = $this->writeBatch($ai, $world, $seed, $batch, $fieldsByKind, $allTitles, $user);

        $counts = $build->counts ?? [];
        foreach ($entries as $entry) {
            $kind = in_array($entry['kind'], self::KINDS, true) ? $entry['kind'] : 'article';
            $title = $entry['title'];

            $world->documents()->create([
                'user_id' => $build->user_id,
                'title' => $title,
                'slug' => Document::uniqueSlug($world->id, $kind, $title),
                'kind' => $kind,
                'content' => $entry['content'],
                'summary' => Str::limit($entry['summary'], 250, ''),
                'is_private' => $privacy[Str::lower($title)] ?? false,
                'data' => $this->sanitiseFacts($entry['facts'], $fieldsByKind[$kind] ?? []),
            ]);

            $counts[$kind] = ($counts[$kind] ?? 0) + 1;
            $build->increment('created');
        }

        $build->update(['counts' => $counts, 'cursor' => $build->cursor + self::BATCH_SIZE]);
        $build->pushLog("Written {$build->created} of {$build->planned}…");

        dispatch(new self($build));
    }

    private function linkAndFinish(WorldBuild $build): void
    {
        // Every entry now exists, so [[wiki-links]] in each body resolve into real connections.
        $documents = $build->world->documents()->get();
        if ($documents->isNotEmpty()) {
            $build->pushLog('Linking entries together…');
            foreach ($documents as $document) {
                WikiLinks::sync($document);
            }
        }

        $total = (int) $build->created;
        $build->markCompleted($total === 0
            ? 'Nothing could be built from those notes.'
            : "Built {$total} ".Str::plural('entry', $total).' with quick facts and links from your notes.');
    }

    public function failed(Throwable $exception): void
    {
        $this->build->fresh()?->markFailed('Something went wrong while building your world. Please try again.');
    }

    /**
     * Pass one: a flat list of every entry worth creating, classified and privacy-flagged.
     *
     * @return list<array{kind: string, title: string, private: bool}>
     */
    private function outline(AnthropicClient $ai, mixed $world, string $seed, mixed $user): array
    {
        $kinds = implode('|', self::KINDS);
        $system = "You are helping a GM turn their campaign notes into wiki entries for the world \"{$world->name}\".\n"
            ."Read the notes and list every distinct entry worth creating. Return ONE JSON object and nothing else:\n"
            .'{"entities": [{"kind": "'.$kinds.'", "title": "…", "private": false}]}'."\n"
            ."- One entity per distinct person, place, faction/house/order, notable item or artifact, or lore/mechanic/secret topic.\n"
            ."- kind: npc = characters and people; location = places; faction = houses, orders, groups; item = notable objects; lore = world mechanics, rules, cosmology; article = events, plots and anything else.\n"
            ."- private = true for GM-only secrets (hidden reveals, spoilers, things players don't yet know).\n"
            .'- Up to '.self::OUTLINE_CAP.' entities. Only include things grounded in the notes — invent nothing.';

        $raw = $ai->chat($system, [['role' => 'user', 'content' => $seed]], 4000, new AiUsageContext('world_build', $world->id, $user->id, 'outline'), 120);
        $user->spendAiCredit();

        $parsed = AiJson::object($raw);
        $entities = is_array($parsed['entities'] ?? null) ? $parsed['entities'] : [];

        $clean = [];
        $seen = [];
        foreach ($entities as $entity) {
            $title = trim((string) data_get($entity, 'title', ''));
            $kind = (string) data_get($entity, 'kind', 'article');
            $key = Str::lower($title);
            if ($title === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $clean[] = [
                'kind' => in_array($kind, self::KINDS, true) ? $kind : 'article',
                'title' => $title,
                'private' => (bool) data_get($entity, 'private', false),
            ];
            if (count($clean) >= self::OUTLINE_CAP) {
                break;
            }
        }

        return $clean;
    }

    /**
     * Pass two: the full text of one batch of entries, grounded in the notes, with quick-facts filled
     * and [[wiki-links]] to the other entries woven into the prose.
     *
     * @param  list<array{kind: string, title: string, private: bool}>  $batch
     * @param  array<string, list<array<string, mixed>>>  $fieldsByKind
     * @param  list<string>  $allTitles
     * @return list<array{kind: string, title: string, summary: string, content: string, facts: array<string, mixed>}>
     */
    private function writeBatch(AnthropicClient $ai, mixed $world, string $seed, array $batch, array $fieldsByKind, array $allTitles, mixed $user): array
    {
        $wanted = collect($batch)->map(fn (array $entity): string => "{$entity['title']} ({$entity['kind']})")->implode('; ');

        // Tell the AI exactly which quick-facts keys each kind in this batch accepts, so it fills the
        // right ones (reference fields are handled by links, not facts, so they're excluded here).
        $fieldGuide = collect(array_unique(array_column($batch, 'kind')))
            ->map(function (string $kind) use ($fieldsByKind): ?string {
                $keys = collect($fieldsByKind[$kind] ?? [])
                    ->reject(fn (array $field): bool => ($field['type'] ?? 'text') === 'reference')
                    ->map(fn (array $field): string => $field['key'].' ('.$field['label'].')')
                    ->implode(', ');

                return $keys === '' ? null : "{$kind}: {$keys}";
            })
            ->filter()
            ->implode("\n");

        $linkable = implode(', ', $allTitles);

        $system = "You are writing wiki entries for the world \"{$world->name}\" from the GM's notes below.\n"
            ."For each requested title, write a rich Markdown entry grounded ONLY in the notes. Return ONE JSON object and nothing else:\n"
            .'{"entries": [{"title": "…", "kind": "…", "summary": "one sentence", "content": "Markdown body", "facts": {"key": "value"}}]}'."\n"
            ."- Keep the exact title and kind you were given.\n"
            ."- \"content\": a few short paragraphs of Markdown; use ## subheadings only where they help. When you mention another entry from the linkable list, wrap its name in [[double square brackets]] using its EXACT title so it becomes a link. Never link an entry to itself.\n"
            ."- \"summary\": a single sentence for the card and search result.\n"
            .($fieldGuide !== '' ? "- \"facts\": fill the quick-facts for the entry's kind using ONLY these keys (omit any you can't fill from the notes; a value may be a short string, or an array of strings for a list):\n{$fieldGuide}\n" : "- \"facts\": leave as an empty object {}.\n")
            ."- Do not invent facts beyond what the notes support.\n\n"
            .($linkable !== '' ? "ENTRIES YOU MAY LINK TO with [[Title]]: {$linkable}\n\n" : '')
            ."NOTES:\n{$seed}";

        $raw = $ai->chat($system, [['role' => 'user', 'content' => "Write entries for: {$wanted}"]], 5000, new AiUsageContext('world_build', $world->id, $user->id, 'entries'), 150);
        $user->spendAiCredit();

        $parsed = AiJson::object($raw);
        $entries = is_array($parsed['entries'] ?? null) ? $parsed['entries'] : [];

        $clean = [];
        foreach ($entries as $entry) {
            $title = trim((string) data_get($entry, 'title', ''));
            $content = trim((string) data_get($entry, 'content', ''));
            if ($title === '' || $content === '') {
                continue;
            }
            $facts = data_get($entry, 'facts');
            $clean[] = [
                'kind' => (string) data_get($entry, 'kind', 'article'),
                'title' => $title,
                'summary' => trim((string) data_get($entry, 'summary', '')),
                'content' => $content,
                'facts' => is_array($facts) ? $facts : [],
            ];
        }

        return $clean;
    }

    /**
     * Keep only quick-facts the kind actually defines, coerced to the field's shape. Reference fields
     * are skipped (cross-entry relationships come from the [[wiki-links]] in the body instead).
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
                    ->filter()
                    ->values()
                    ->all();
                if ($list !== []) {
                    $data[$key] = $list;
                }

                continue;
            }

            if (($field['type'] ?? 'text') === 'boolean') {
                $data[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                continue;
            }

            $string = trim((string) (is_array($value) ? reset($value) : $value));
            if ($string !== '') {
                $data[$key] = $string;
            }
        }

        return $data;
    }
}
