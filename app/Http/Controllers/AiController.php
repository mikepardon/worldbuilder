<?php

namespace App\Http\Controllers;

use App\Models\Document;
use App\Services\AnthropicClient;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\CreditWeights;
use App\Support\DocFields;
use Illuminate\Http\Request;
use Throwable;

class AiController extends Controller
{
    /** Ask Claude for help with a document. Returns JSON so the editor can chat without a reload. */
    public function ask(Request $request, Document $document, AnthropicClient $ai)
    {
        $this->authorize('update', $document);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'content' => ['nullable', 'string'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        if (! $ai->configured()) {
            return response()->json([
                'message' => "AI isn't set up on this server yet.",
            ], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('assistant_ask');
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $world = $document->world;
        $content = $data['content'] ?? $document->content ?? '';
        $system = "You are a worldbuilding assistant for a tabletop RPG campaign called \"{$world->name}\"."
            .($world->setting ? " The setting: {$world->setting}." : '')
            ." You are helping the GM with a \"{$document->kind}\" entry titled \"{$document->title}\"."
            .' Be concise and evocative, stay consistent with the established world, and when asked to'
            ." write or expand an entry, respond in Markdown.\n\nThe current entry content is:\n\n{$content}";

        $messages = [];
        foreach ($data['history'] ?? [] as $m) {
            $messages[] = ['role' => $m['role'], 'content' => $m['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $data['prompt']];

        try {
            $reply = $ai->chat($system, $messages, 1500, new AiUsageContext('assistant_ask', $world->id, $user->id));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $user->spendAiCredits($creditCost);

        return response()->json(['reply' => $reply, 'creditsRemaining' => $user->aiCreditsRemaining()]);
    }

    /**
     * Draft or update a field-kind entry in one shot: Claude returns a JSON object that both fills the
     * entry's structured fields and rewrites its Markdown body, so the editor can apply it directly
     * instead of the GM copying prose out of the chat by hand.
     */
    public function draft(Request $request, Document $document, AnthropicClient $ai)
    {
        $this->authorize('update', $document);

        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:8000'],
            'content' => ['nullable', 'string'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        if (! $ai->configured()) {
            return response()->json([
                'message' => "AI isn't set up on this server yet.",
            ], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('assistant_draft', $document->kind);
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $world = $document->world;
        $fields = DocFields::for($document->kind, $world);

        // Reference fields may only point at entries that already exist, so offer Claude the candidates
        // (id => title) to choose from; anything it invents is rejected during sanitisation below.
        $referenceOptions = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? 'text') !== 'reference') {
                continue;
            }
            $kinds = $field['ref_kinds'] ?? [];
            $referenceOptions[$field['key']] = $world->documents()
                ->where('id', '!=', $document->id)
                ->when(filled($kinds), fn ($query) => $query->whereIn('kind', $kinds))
                ->orderBy('title')
                ->get(['id', 'title'])
                ->mapWithKeys(fn (Document $entry) => [$entry->id => $entry->title])
                ->all();
        }

        $data = (array) ($document->data ?? []);
        $content = $input['content'] ?? $document->content ?? '';
        $system = "You are a worldbuilding assistant editing a \"{$document->kind}\" entry titled \"{$document->title}\" for the tabletop RPG campaign \"{$world->name}\"."
            .($world->setting ? " Setting: {$world->setting}." : '')
            .' Work like a careful collaborator: do exactly what the GM asks — no more, no less.'
            ."\n\nChoose the ONE mode that fits the GM's message:\n"
            ."1. CLARIFY — if the request is ambiguous, or you need information you don't have to do it well, ask a short question instead of guessing. Put the question in \"reply\" and leave \"content\" and \"fields\" empty.\n"
            ."2. TARGETED CHANGE — if the GM asks to change, add, or remove a specific thing, change ONLY that. Reproduce everything else EXACTLY as it is now: same sections, order, wording, secrets ({{secret}}…{{/}}), and embeds ({{monster=id}} etc.). In \"fields\" include ONLY the field(s) you are changing. Give \"content\" (the full body with just that edit applied) only if you changed the body; omit it if you changed only a field.\n"
            ."3. FULL (RE)WRITE — only if the GM asks to write, draft, or rewrite the whole entry or a fresh version. Produce a complete new body and fill every field you reasonably can, discarding old placeholder text.\n\n"
            ."Respond with a SINGLE JSON object and nothing else — no prose, no code fences:\n"
            ."{\"fields\": { ... }, \"content\": \"...\", \"summary\": \"...\", \"reply\": \"...\"}\n"
            ."- \"content\": the COMPLETE entry body in Markdown, using \"## Heading\" per section. Omit it (or use \"\") when you are only asking a question or only changing fields — never return a fragment.\n"
            ."- \"fields\": map of field key to new value; include only the fields you are setting.\n"
            ."- \"summary\": one evocative sentence for the tagline; include it only when you want to change it.\n"
            ."- \"reply\": one or two short sentences to the GM — your question, or what you changed.\n\n"
            ."Fields for this entry (with their current values):\n".$this->describeFields($fields, $referenceOptions, $data)
            ."\n\nThe current entry body is:\n\n{$content}";

        $messages = [];
        foreach ($input['history'] ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $input['prompt']];

        try {
            $raw = $ai->chat($system, $messages, 2400, new AiUsageContext('assistant_draft', $world->id, $user->id, $document->kind));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $parsed = AiJson::object($raw);
        if ($parsed === null) {
            return response()->json(['message' => 'The AI returned an unexpected response. Please try again.'], 422);
        }

        $user->spendAiCredits($creditCost);

        return response()->json([
            'reply' => is_string($parsed['reply'] ?? null) ? $parsed['reply'] : '',
            'creditsRemaining' => $user->aiCreditsRemaining(),
            'content' => is_string($parsed['content'] ?? null) ? $parsed['content'] : '',
            'summary' => is_string($parsed['summary'] ?? null) ? trim($parsed['summary']) : '',
            'fields' => $this->sanitiseFields($parsed['fields'] ?? null, $fields, $referenceOptions),
        ]);
    }

    /**
     * A conversational Muse for a bloodline. The GM chats; Claude replies AND may propose people to add
     * to the tree (new members, wired to each other or to people already in the tree by id). Returns a
     * single JSON object {reply, members}; the editor maps new ids to real ones and appends them.
     */
    public function bloodline(Request $request, Document $document, AnthropicClient $ai)
    {
        $this->authorize('update', $document);

        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
            'existing' => ['nullable', 'array', 'max:120'],
            'existing.*.id' => ['required_with:existing', 'string', 'max:80'],
            'existing.*.name' => ['required_with:existing', 'string', 'max:160'],
        ]);

        if (! $ai->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('assistant_bloodline', 'bloodline');
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $existingMembers = collect($input['existing'] ?? [])
            ->mapWithKeys(fn (array $member): array => [(string) $member['id'] => (string) $member['name']])
            ->all();
        $roster = collect($existingMembers)->map(fn (string $name, string $id): string => "{$id} = {$name}")->implode('; ');

        $world = $document->world;
        $system = "You are Muse, a worldbuilding assistant helping a GM build a family tree (bloodline) for the tabletop RPG world \"{$world->name}\"."
            .($world->setting ? " Setting: {$world->setting}." : '')
            ."\n\nReply conversationally, but ALWAYS respond with a SINGLE JSON object and nothing else — no prose outside it, no code fences:\n"
            .'{"reply": "one or two sentences to the GM", "members": [{"id": "m1", "name": "…", "subtitle": "…", "parents": ["id"], "partners": ["id"]}]}'."\n"
            ."- \"members\": the NEW people to add to the tree (empty [] if you are only chatting or answering a question).\n"
            ."- Give NEW people your own short ids (m1, m2, …). To attach a new person to someone ALREADY in the tree, reference that person's existing id.\n"
            ."- \"parents\": ids of a person's parents (0–2). \"partners\": ids of spouses. Only reference ids that are new ones you define or existing ids from the roster.\n"
            ."- \"subtitle\": an optional title and/or life dates, e.g. \"The Savage · 1050–1105\".\n"
            .($roster !== '' ? "\nPeople already in the tree (id = name): {$roster}." : "\nThe tree is currently empty.");

        $messages = [];
        foreach ($input['history'] ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $input['prompt']];

        try {
            $raw = $ai->chat($system, $messages, 2000, new AiUsageContext('assistant_bloodline', $world->id, $user->id, 'bloodline'));
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $parsed = AiJson::object($raw);
        if ($parsed === null) {
            return response()->json(['message' => 'The AI returned an unexpected response. Please try again.'], 422);
        }

        $user->spendAiCredits($creditCost);

        return response()->json([
            'reply' => is_string($parsed['reply'] ?? null) ? $parsed['reply'] : '',
            'members' => $this->sanitiseBloodline(is_array($parsed['members'] ?? null) ? $parsed['members'] : [], array_keys($existingMembers)),
            'creditsRemaining' => $user->aiCreditsRemaining(),
        ]);
    }

    /**
     * Keep valid new members (id + name, not already in the tree) and drop parent/partner references
     * that aren't either a new member or an existing tree member.
     *
     * @param  array<int, mixed>  $members
     * @param  list<string>  $existingIds  ids already in the tree, which new members may reference
     * @return list<array{id: string, name: string, subtitle: string, parents: list<string>, partners: list<string>}>
     */
    private function sanitiseBloodline(array $members, array $existingIds = []): array
    {
        $existing = array_flip($existingIds);
        $clean = [];
        $newIds = [];
        foreach ($members as $member) {
            $id = trim((string) data_get($member, 'id', ''));
            $name = trim((string) data_get($member, 'name', ''));
            // Only accept genuinely new people (skip blanks, dupes, and ids that already exist).
            if ($id === '' || $name === '' || isset($newIds[$id]) || isset($existing[$id])) {
                continue;
            }
            $newIds[$id] = true;
            $refs = fn (string $key): array => collect((array) data_get($member, $key, []))
                ->map(fn (mixed $ref): string => trim((string) $ref))
                ->filter()
                ->values()
                ->all();
            $clean[] = [
                'id' => $id,
                'name' => $name,
                'subtitle' => trim((string) data_get($member, 'subtitle', '')),
                'parents' => $refs('parents'),
                'partners' => $refs('partners'),
            ];
        }

        $valid = $newIds + $existing;

        return collect($clean)
            ->map(fn (array $member): array => [
                ...$member,
                'parents' => collect($member['parents'])->filter(fn (string $ref): bool => isset($valid[$ref]) && $ref !== $member['id'])->values()->all(),
                'partners' => collect($member['partners'])->filter(fn (string $ref): bool => isset($valid[$ref]) && $ref !== $member['id'])->values()->all(),
            ])
            ->all();
    }

    /**
     * Human-readable description of each structured field (rule + current value), for the drafting prompt.
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, array<int, string>>  $referenceOptions
     * @param  array<string, mixed>  $data  the entry's current field values
     */
    private function describeFields(array $fields, array $referenceOptions, array $data): string
    {
        if ($fields === []) {
            return '(none)';
        }

        return collect($fields)->map(function (array $field) use ($referenceOptions, $data): string {
            $type = $field['type'] ?? 'text';
            $key = $field['key'];
            $rule = match ($type) {
                'select' => 'one of: '.implode(', ', $field['options'] ?? []),
                'boolean' => 'true or false',
                'number' => 'a number',
                'reference' => 'the numeric id of one of: '.$this->describeReferences($referenceOptions[$key] ?? []),
                'date' => 'a date (YYYY-MM-DD)',
                'url' => 'a URL',
                'longtext' => 'a short paragraph',
                default => 'a short string',
            };

            $current = $this->currentValueLabel($type, $data[$key] ?? null, $referenceOptions[$key] ?? []);
            $currentText = $current === '' ? 'currently empty' : "currently: {$current}";

            return "- \"{$key}\" ({$field['label']}): {$rule} — {$currentText}";
        })->implode("\n");
    }

    /** @param  array<int, string>  $referenceOptions  id => title */
    private function currentValueLabel(string $type, mixed $value, array $referenceOptions): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($type === 'reference') {
            $id = is_numeric($value) ? (int) $value : null;

            return $id !== null && array_key_exists($id, $referenceOptions) ? "{$referenceOptions[$id]} (id {$id})" : '';
        }
        if ($type === 'boolean') {
            return $value ? 'true' : 'false';
        }

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @param  array<int, string>  $options  id => title */
    private function describeReferences(array $options): string
    {
        if ($options === []) {
            return '(no eligible entries — use null)';
        }

        return collect($options)->map(fn (string $title, int $id) => "{$id} ({$title})")->implode(', ');
    }

    /**
     * Keep only known fields whose value is valid for the field type (reject-by-default).
     *
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, array<int, string>>  $referenceOptions
     * @return array<string, mixed>
     */
    private function sanitiseFields(mixed $returned, array $fields, array $referenceOptions): array
    {
        if (! is_array($returned)) {
            return [];
        }

        $clean = [];
        foreach ($fields as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $returned)) {
                continue;
            }
            $value = $this->coerceFieldValue($field['type'] ?? 'text', $returned[$key], $field, $referenceOptions[$key] ?? []);
            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $referenceOptions  id => title
     */
    private function coerceFieldValue(string $type, mixed $value, array $field, array $referenceOptions): mixed
    {
        return match ($type) {
            'boolean' => is_bool($value) ? $value : null,
            'number' => is_numeric($value) ? $value : null,
            'select' => $this->matchOption($value, $field['options'] ?? []),
            'reference' => (is_int($value) || (is_string($value) && ctype_digit($value))) && array_key_exists((int) $value, $referenceOptions)
                ? (int) $value
                : null,
            default => is_string($value) && trim($value) !== '' ? trim($value) : null,
        };
    }

    /** @param  array<int, string>  $options */
    private function matchOption(mixed $value, array $options): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        foreach ($options as $option) {
            if (mb_strtolower($option) === mb_strtolower($value)) {
                return $option;
            }
        }

        return null;
    }
}
