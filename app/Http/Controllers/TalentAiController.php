<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\RuleSystem;
use App\Models\TalentNode;
use App\Models\TalentWeb;
use App\Services\AnthropicClient;
use App\Services\TalentLayout;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\CreditWeights;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Muse for the talent-web builder: a conversational assistant that proposes nodes for a web. The chat
 * turn ({@see self::chat()}) returns a reply plus sanitised node proposals and spends one AI credit;
 * applying them ({@see self::apply()}) persists the chosen nodes and their connections for free. Both
 * are gated by the system's policy (admins for templates, co-authors for world systems).
 */
class TalentAiController extends Controller
{
    /** A conversational turn: chat about the web and get node proposals back (spends one credit). */
    public function chat(Request $request, TalentWeb $web, AnthropicClient $ai)
    {
        $this->authorize('update', $web->ruleSystem);

        $data = $request->validate([
            'prompt' => ['required', 'string', 'max:4000'],
            'history' => ['nullable', 'array', 'max:40'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string'],
        ]);

        if (! $ai->configured()) {
            return response()->json(['message' => "AI isn't set up on this server yet."], 422);
        }

        $user = $request->user();
        $creditCost = CreditWeights::forFeature('assistant_ask');
        if (! $user->canSpendAiCredits($creditCost)) {
            return response()->json([
                'message' => 'You’re out of AI credits for today — they reset daily. Top up or upgrade for more.',
                'outOfCredits' => true,
            ], 402);
        }

        $system = $web->ruleSystem;
        $messages = [];
        foreach ($data['history'] ?? [] as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $data['prompt']];

        try {
            $raw = $ai->chat(
                $this->systemPrompt($system, $web),
                $messages,
                2400,
                new AiUsageContext('assistant_ask', $system->world_id, $user->id),
            );
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $parsed = AiJson::object($raw);
        if ($parsed === null) {
            return response()->json(['message' => 'The AI returned an unexpected response. Please try again.'], 422);
        }

        $user->spendAiCredits($creditCost);

        return response()->json([
            'reply' => is_string($parsed['reply'] ?? null) ? $parsed['reply'] : '',
            'nodes' => $this->sanitiseNodes(is_array($parsed['nodes'] ?? null) ? $parsed['nodes'] : [], $web),
            'creditsRemaining' => $user->aiCreditsRemaining(),
        ]);
    }

    /** Persist a batch of proposed nodes and their connections onto the web. No credit cost. */
    public function apply(Request $request, TalentWeb $web, TalentLayout $layout)
    {
        $this->authorize('update', $web->ruleSystem);

        $request->validate([
            'nodes' => ['present', 'array', 'max:40'],
        ]);

        $nodes = $this->sanitiseNodes((array) $request->input('nodes'), $web);
        if ($nodes === []) {
            return back();
        }

        $centre = $web->layout['centre'] ?? ['x' => 1300, 'y' => 1300];
        // Cast to int: pluck can return string ids on some drivers, which would break the strict in_array below.
        $existingIds = $web->nodes()->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // Each node hangs off the first connection it resolves to (its parent), so it lays out as a branch
        // child rather than being scattered in a spiral. We collect the parents touched and fan their
        // children out afterwards — the same tidy layout a GM gets when growing a branch by hand.
        $parentIds = DB::transaction(function () use ($web, $nodes, $centre, $existingIds): array {
            $tempToReal = [];
            $sort = (int) $web->nodes()->max('sort');

            foreach ($nodes as $node) {
                $created = $web->nodes()->create([
                    'key' => $this->uniqueKey($web, $node['name']),
                    'name' => $node['name'],
                    'kind' => $node['kind'],
                    // A provisional spot near the centre; the fan pass below places every parented node.
                    'x' => (int) $centre['x'],
                    'y' => (int) $centre['y'],
                    'gate_level' => $node['gate_level'],
                    'cost' => $node['cost'],
                    'description' => $node['description'],
                    'effects' => $node['effects'],
                    'sort' => ++$sort,
                ]);
                $tempToReal[$node['temp_id']] = $created->id;
            }

            $parents = [];
            foreach ($nodes as $node) {
                $fromId = $tempToReal[$node['temp_id']];
                $parentId = null;
                foreach ($node['connect'] as $target) {
                    $toId = $tempToReal[$target] ?? (in_array((int) $target, $existingIds, true) ? (int) $target : null);
                    if ($toId === null || $toId === $fromId) {
                        continue;
                    }
                    $parentId ??= $toId;
                    if (! $this->edgeExists($web, $fromId, $toId)) {
                        $web->edges()->create(['from_node_id' => $fromId, 'to_node_id' => $toId]);
                    }
                }

                if ($parentId !== null) {
                    TalentNode::where('id', $fromId)->update(['parent_node_id' => $parentId, 'layout_role' => 'child']);
                    $parents[$parentId] = true;
                }
            }

            return array_keys($parents);
        });

        // Fan parents in sort order so a new parent is placed (as its own parent's child) before its
        // children are arranged around it.
        foreach ($web->nodes()->whereIn('id', $parentIds)->orderBy('sort')->get() as $parent) {
            $layout->arrange($web, $parent);
        }

        return back();
    }

    private function systemPrompt(RuleSystem $system, TalentWeb $web): string
    {
        $stats = $system->stats->map(fn ($stat) => "{$stat->key} ({$stat->label})")->implode(', ') ?: 'none';
        $resources = $system->resources->map(fn ($resource) => "{$resource->key} ({$resource->label})")->implode(', ') ?: 'none';
        $skills = $system->skills->map(fn ($skill) => $skill->key)->implode(', ') ?: 'none';
        $kinds = $system->nodeKinds->map(fn ($kind) => "{$kind->key} ({$kind->label}, cost {$kind->default_cost})")->implode(', ') ?: 'none';
        $roster = $web->nodes->take(120)->map(fn (TalentNode $node) => "{$node->id} = {$node->name}")->implode('; ') ?: 'empty';

        return "You are Muse, helping a game master design a talent web for their custom tabletop RPG system \"{$system->name}\". A talent web is a tree of nodes (talents, stat boosts, spells, abilities) that characters spend points on."
            ."\n\nReply conversationally, but ALWAYS respond with a SINGLE JSON object and nothing else — no prose outside it, no code fences:\n"
            .'{"reply": "one or two sentences to the GM", "nodes": [{"id": "n1", "name": "…", "kind": "…", "cost": 2, "gate_level": 1, "description": "…", "effects": [{"type": "stat", "key": "str", "delta": 1}], "connect": ["n2", "12"]}]}'."\n"
            ."- \"nodes\": the NEW nodes to propose (empty [] when you are only chatting or answering a question). Propose a focused set (usually 3–8).\n"
            ."- \"kind\" MUST be one of these keys: {$kinds}.\n"
            ."- \"effects\" is optional; each is {type, key, delta} where type is stat|resource|skill|derived. Stat keys: {$stats}. Resource keys: {$resources}. Skill keys: {$skills}. For a skill proficiency use {\"type\":\"skill\",\"key\":\"…\",\"proficiency\":true}. Only use keys that exist.\n"
            ."- \"connect\": ids this node links to. List the node it grows from FIRST — that becomes its parent, so the web lays it out as a branch off that node. Use your own new ids (n1, n2, …) to chain new nodes together, and the numeric ids of existing nodes to attach onto the current web.\n"
            ."- \"cost\" and \"gate_level\" are small integers; leave gate_level 1 unless it should unlock later.\n\n"
            ."Existing nodes on this web (id = name): {$roster}.";
    }

    /**
     * Keep only well-formed proposals: a name, a real node kind, sane numbers, effects that reference
     * keys that exist, and connections as a clean id list. Reject-by-default on both the AI's output and
     * a client's apply request.
     *
     * @param  array<int, mixed>  $raw
     * @return list<array{temp_id: string, name: string, kind: string, cost: int, gate_level: int, description: string|null, effects: list<array<string, mixed>>, connect: list<string>}>
     */
    private function sanitiseNodes(array $raw, TalentWeb $web): array
    {
        $system = $web->ruleSystem;
        $kindKeys = $system->nodeKinds->pluck('key')->all();
        $defaultKind = $kindKeys[0] ?? null;
        if ($defaultKind === null) {
            return [];
        }

        $statKeys = $system->stats->pluck('key')->flip();
        $resourceKeys = $system->resources->pluck('key')->flip();
        $skillKeys = $system->skills->pluck('key')->flip();

        $clean = [];
        $seen = [];
        foreach (array_values($raw) as $index => $node) {
            $name = trim((string) data_get($node, 'name', ''));
            if ($name === '') {
                continue;
            }

            // The AI labels new nodes with "id"; when the client sends them back to apply they carry
            // "temp_id". Accept either so connections stay wired to the right node.
            $tempId = trim((string) (data_get($node, 'temp_id') ?? data_get($node, 'id', ''))) ?: 'n'.($index + 1);
            if (isset($seen[$tempId])) {
                $tempId .= '-'.$index;
            }
            $seen[$tempId] = true;

            $kind = (string) data_get($node, 'kind', '');
            if (! in_array($kind, $kindKeys, true)) {
                $kind = $defaultKind;
            }

            $connect = collect((array) data_get($node, 'connect', []))
                ->map(fn ($ref): string => trim((string) $ref))
                ->filter()
                ->values()
                ->all();

            $clean[] = [
                'temp_id' => $tempId,
                'name' => Str::limit($name, 118, ''),
                'kind' => $kind,
                'cost' => max(0, min(99, (int) data_get($node, 'cost', 1))),
                'gate_level' => max(1, min(100, (int) data_get($node, 'gate_level', 1))),
                'description' => filled(data_get($node, 'description')) ? Str::limit((string) data_get($node, 'description'), 1980, '') : null,
                'effects' => $this->sanitiseEffects(data_get($node, 'effects', []), $statKeys, $resourceKeys, $skillKeys),
                'connect' => $connect,
            ];
        }

        return $clean;
    }

    /**
     * @param  Collection<string, int>  $statKeys
     * @param  Collection<string, int>  $resourceKeys
     * @param  Collection<string, int>  $skillKeys
     * @return list<array<string, mixed>>
     */
    private function sanitiseEffects(mixed $effects, $statKeys, $resourceKeys, $skillKeys): array
    {
        if (! is_array($effects)) {
            return [];
        }

        $clean = [];
        foreach ($effects as $effect) {
            $type = (string) data_get($effect, 'type', '');
            $key = (string) data_get($effect, 'key', '');
            $valid = match ($type) {
                'stat' => $statKeys->has($key),
                'resource' => $resourceKeys->has($key),
                'skill' => $skillKeys->has($key),
                'derived' => $key !== '',
                default => false,
            };
            if (! $valid) {
                continue;
            }

            $entry = ['type' => $type, 'key' => $key, 'delta' => (int) data_get($effect, 'delta', 0)];
            if ($type === 'resource' && is_numeric(data_get($effect, 'pct'))) {
                $entry['pct'] = (float) data_get($effect, 'pct');
            }
            if ($type === 'skill' && data_get($effect, 'proficiency')) {
                $entry['proficiency'] = true;
            }
            $clean[] = $entry;
        }

        return $clean;
    }

    /** Whether the web already joins these two nodes, in either direction. */
    private function edgeExists(TalentWeb $web, int $a, int $b): bool
    {
        return $web->edges()
            ->where(fn ($query) => $query
                ->where(fn ($pair) => $pair->where('from_node_id', $a)->where('to_node_id', $b))
                ->orWhere(fn ($pair) => $pair->where('from_node_id', $b)->where('to_node_id', $a)))
            ->exists();
    }

    private function uniqueKey(TalentWeb $web, string $name): string
    {
        $base = Str::slug($name) ?: 'node';
        $key = $base;
        $n = 2;
        while ($web->nodes()->where('key', $key)->exists()) {
            $key = $base.'-'.$n++;
        }

        return $key;
    }
}
