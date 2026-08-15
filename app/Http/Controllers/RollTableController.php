<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RollTable;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\CreditWeights;
use App\Support\WorldNav;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Rollable, searchable random tables a world defines. Owner-managed. */
class RollTableController extends Controller
{
    public function index(Request $request, World $world, AnthropicClient $ai): Response
    {
        $this->authorize('update', $world);

        return Inertia::render('Worlds/Tables', [
            'world' => WorldNav::for($world),
            'tables' => $world->rollTables()->orderBy('name')->get(),
            'aiConfigured' => $ai->configured(),
            'creditsRemaining' => $request->user()->aiCreditsRemaining(),
        ]);
    }

    public function store(Request $request, World $world): RedirectResponse
    {
        $this->authorize('update', $world);

        $world->rollTables()->create($this->validated($request, $world));

        return back();
    }

    public function update(Request $request, RollTable $rollTable): RedirectResponse
    {
        $this->authorize('update', $rollTable->world);

        $rollTable->update($this->validated($request, $rollTable->world));

        return back();
    }

    public function destroy(RollTable $rollTable): RedirectResponse
    {
        $this->authorize('update', $rollTable->world);

        $rollTable->delete();

        return back();
    }

    /**
     * Muse: a conversational assistant that builds and reworks a roll table. Each reply returns the
     * COMPLETE set of rows for the current die (so the ranges always stay valid), plus a short reply.
     */
    public function generate(Request $request, World $world, AnthropicClient $ai): JsonResponse
    {
        $this->authorize('update', $world);

        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:2000'],
            'die' => ['required', 'integer', 'min:2', 'max:1000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
            'existing' => ['nullable', 'array', 'max:300'],
            'existing.*.min' => ['required_with:existing', 'integer'],
            'existing.*.max' => ['required_with:existing', 'integer'],
            'existing.*.result' => ['required_with:existing', 'string', 'max:500'],
            // A big table is built in range-scoped batches so no single request runs long enough to
            // trip the gateway timeout. When "range" is set we generate only that slice of the die.
            'range' => ['nullable', 'array'],
            'range.min' => ['required_with:range', 'integer'],
            'range.max' => ['required_with:range', 'integer'],
            'avoid' => ['nullable', 'array', 'max:400'],
            'avoid.*' => ['string', 'max:500'],
        ]);

        if (! $ai->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('roll_table', 'roll_table');
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $die = (int) $input['die'];

        $lowerBound = 1;
        $upperBound = $die;
        if (isset($input['range'])) {
            $lowerBound = max(1, min($die, (int) $input['range']['min']));
            $upperBound = max($lowerBound, min($die, (int) $input['range']['max']));
        }
        $isBatch = isset($input['range']);
        $span = $upperBound - $lowerBound + 1;

        $system = "You are Muse, a worldbuilding assistant helping a GM build a random roll table (rolled on a d{$die}) for the tabletop RPG world \"{$world->name}\"."
            .($world->setting ? " Setting: {$world->setting}." : '')
            ."\n\nReply conversationally, but ALWAYS respond with a SINGLE JSON object and nothing else — no prose outside it, no code fences:\n"
            .'{"reply": "one or two sentences to the GM", "rows": [{"min": 1, "max": 3, "result": "…", "detail": "…"}]}'."\n"
            ."- \"result\": a short label (a few words). \"detail\": one sentence of flavour.\n"
            ."- Weight rarer or more dramatic outcomes to the higher numbers.\n";

        if ($isBatch) {
            $avoid = collect($input['avoid'] ?? [])->filter()->implode('; ');
            $system .= "- \"rows\": cover ONLY numbers {$lowerBound} to {$upperBound} of the d{$die}, every number in that span, no gaps or overlaps. This is one slice of a larger table.\n"
                ."- \"reply\": leave empty.\n"
                .($avoid !== '' ? "- Do NOT reuse any of these results already used elsewhere in the table; invent different ones: {$avoid}." : '');
        } else {
            $current = collect($input['existing'] ?? [])
                ->map(fn (array $row): string => "{$row['min']}-{$row['max']}: {$row['result']}")
                ->implode('; ');
            $system .= "- \"rows\": the COMPLETE table after your change — every row, not just new ones. Return [] only when you are just chatting or answering a question without changing the table.\n"
                ."- The rows MUST cover 1 to {$die} with no gaps or overlaps.\n"
                ."- \"reply\": one or two short sentences to the GM — what you built or changed, or your answer.\n"
                .($current !== '' ? "\nThe table currently has these rows: {$current}." : "\nThe table is currently empty.");
        }

        $messages = [];
        foreach ($input['history'] ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $input['prompt']];

        // Size the token budget to the span we're actually generating. The timeout stays under the
        // typical 60s gateway limit so a slow call fails as a clean error, not a 504 — large tables
        // avoid that entirely by being batched into small spans on the client.
        $maxTokens = (int) min(8000, 600 + $span * 70);
        $timeout = 55;

        try {
            $raw = $ai->chat($system, $messages, $maxTokens, new AiUsageContext('roll_table', $world->id, $user->id, 'roll_table'), $timeout);
        } catch (Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $parsed = AiJson::object($raw);
        $rawRows = is_array($parsed['rows'] ?? null) ? $parsed['rows'] : [];

        // A large table can be cut off mid-JSON by the token cap, so the whole object won't decode.
        // Recover whatever complete row objects made it through rather than losing the lot.
        if ($rawRows === [] && str_contains($raw, '"result"')) {
            $rawRows = $this->salvageRows($raw);
        }

        if ($parsed === null && $rawRows === []) {
            return response()->json(['message' => 'The AI returned an unexpected response. Please try again.'], 422);
        }

        $user->spendAiCredits($creditCost);

        return response()->json([
            'reply' => is_string($parsed['reply'] ?? null) ? $parsed['reply'] : '',
            'rows' => $this->sanitiseRows($rawRows, $die, $lowerBound, $upperBound),
            'creditsRemaining' => $user->aiCreditsRemaining(),
        ]);
    }

    /**
     * Best-effort recovery of complete row objects from a reply whose outer JSON was truncated.
     * Walks the text pairing braces and decodes each balanced {...} block, keeping those that look
     * like a row (they carry a "result"). Rows are re-validated by sanitiseRows afterwards.
     *
     * @return list<array<string, mixed>>
     */
    private function salvageRows(string $raw): array
    {
        $characters = mb_str_split($raw);
        $openIndexes = [];
        $rows = [];

        foreach ($characters as $index => $character) {
            if ($character === '{') {
                $openIndexes[] = $index;

                continue;
            }

            if ($character !== '}' || $openIndexes === []) {
                continue;
            }

            $start = array_pop($openIndexes);
            $decoded = json_decode(implode('', array_slice($characters, $start, $index - $start + 1)), true);
            if (is_array($decoded) && array_key_exists('result', $decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * Keep well-formed rows whose ranges fall within the given bounds (the whole die by default, or a
     * single batch's slice), clamped and ordered.
     *
     * @param  array<int, mixed>  $rows
     * @return list<array{min: int, max: int, result: string, detail: string}>
     */
    private function sanitiseRows(array $rows, int $die, ?int $lower = null, ?int $upper = null): array
    {
        $lower = max(1, $lower ?? 1);
        $upper = min($die, $upper ?? $die);

        return collect($rows)
            ->map(function (mixed $row) use ($lower, $upper): ?array {
                $result = trim((string) data_get($row, 'result', ''));
                if ($result === '') {
                    return null;
                }
                $min = max($lower, min($upper, (int) data_get($row, 'min', $lower)));
                $max = max($min, min($upper, (int) data_get($row, 'max', $min)));

                return ['min' => $min, 'max' => $max, 'result' => $result, 'detail' => trim((string) data_get($row, 'detail', ''))];
            })
            ->filter()
            ->sortBy('min')
            ->values()
            ->all();
    }

    /**
     * @return array{name: string, description: string|null, die: int, rows: list<array<string, mixed>>, is_private: bool}
     */
    protected function validated(Request $request, World $world): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'die' => ['required', 'integer', 'min:2', 'max:1000'],
            'rows' => ['present', 'array', 'max:300'],
            'rows.*.min' => ['required', 'integer', 'min:1'],
            'rows.*.max' => ['required', 'integer', 'min:1'],
            'rows.*.result' => ['required', 'string', 'max:500'],
            'rows.*.detail' => ['nullable', 'string', 'max:2000'],
            'rows.*.link' => ['nullable', 'integer', Rule::exists('roll_tables', 'id')->where('world_id', $world->id)],
            'is_private' => ['sometimes', 'boolean'],
        ]);

        return [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'die' => (int) $data['die'],
            'rows' => collect($data['rows'])->map(fn (array $row): array => [
                'min' => (int) $row['min'],
                'max' => (int) $row['max'],
                'result' => $row['result'],
                'detail' => $row['detail'] ?? null,
                'link' => isset($row['link']) ? (int) $row['link'] : null,
            ])->all(),
            'is_private' => $request->boolean('is_private'),
        ];
    }
}
