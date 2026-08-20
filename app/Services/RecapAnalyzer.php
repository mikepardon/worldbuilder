<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\AnalyseRecap;
use App\Models\Recap;
use App\Models\Session;
use App\Models\World;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\WorldContext;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns a raw session transcript into a full recap analysis with the Muse assistant: three recap variants
 * (full / short / stylized), the memorable moments, a scene outline, and the entities that appeared —
 * grounded in the world's existing entries so names stay consistent. {@see AnalyseRecap}
 * persists the result onto the session's {@see Recap}.
 */
class RecapAnalyzer
{
    public function __construct(private AnthropicClient $ai) {}

    /**
     * @return array{
     *     recap_full: string, recap_short: string, recap_stylized: string,
     *     moments: list<array{type: string, description: string, context: string}>,
     *     outline: list<array{title: string, detail: string}>,
     *     next_steps: list<string>,
     *     entities: list<array{name: string, type: string, description: string}>,
     * }
     */
    /**
     * @param  list<string>  $facts  GM-provided facts and corrections to apply as authoritative.
     * @param  list<string>  $instructions  Extra GM output directives layered on top of the core narrative
     *                                       rules (e.g. "write in the present tense", "keep it spoiler-free").
     */
    public function analyze(World $world, Session $session, string $transcript, string $detail, ?int $userId, array $facts = [], array $instructions = []): array
    {
        $transcript = trim($transcript);
        if ($transcript === '') {
            throw new RuntimeException('The transcript was empty, so there was nothing to analyse.');
        }

        $depth = $detail === 'brief'
            ? 'Cover the major beats only; keep the full recap to a few tight paragraphs.'
            : 'Be thorough: a full, evocative recap and every distinct scene in the outline.';

        $context = WorldContext::forPrompt($world);

        // Core narrative rules: a recap is an in-world chronicle of the campaign's story, not a play-by-play
        // of the table. It follows the characters and what happens to them in the fiction, and omits the
        // game's machinery — everything the characters themselves would never know.
        $perspective = ' Write the recap as an in-world, third-person narrative of the campaign\'s story,'
            .' following the characters and what happens to them in the fiction. Do not include any'
            .' out-of-character or "meta" table content: no dice rolls, target numbers, DCs, skill or ability'
            .' checks, initiative, hit points, armour class, spell slots, rules or mechanics discussion, and no'
            .' real player names or table chatter. Translate mechanical outcomes into story terms — a hit'
            .' becomes a blow that lands, a failed save becomes the spell taking hold — so it reads as events in'
            .' the world, not moves in a game. This applies to every text field you produce (the recaps,'
            .' moments, outline and next steps).';

        $factsBlock = $facts === [] ? '' : ' The GM has provided authoritative facts and corrections for'
            .' this session — apply them throughout (for example, a real name said out of character should be'
            .' replaced by the intended character, and a stated fact overrides anything ambiguous in the'
            .' transcript): '.collect($facts)->map(fn (string $fact): string => "({$fact})")->implode('; ').'.';

        $instructionsBlock = $instructions === [] ? '' : ' Follow these GM instructions for the output: '
            .collect($instructions)->map(fn (string $rule): string => "({$rule})")->implode('; ').'.';

        $system = "You are Muse, a worldbuilding assistant for the tabletop RPG world \"{$world->name}\"."
            .($world->setting ? " The setting: {$world->setting}." : '')
            .' Read a raw play-session transcript and turn it into a GM session recap analysis.'
            .$perspective
            .' Prefer names, places and factions that already exist in the world (listed below) over inventing new spellings.'
            .$factsBlock
            .$instructionsBlock
            .' '.$depth
            ."\n\nReturn ONLY a single JSON object, no prose, with exactly these keys:\n"
            .'- "recap_full": a Markdown narrative recap of the whole session.'."\n"
            .'- "recap_short": a 2–3 sentence summary.'."\n"
            .'- "recap_stylized": a punchy, in-world "previously on…" retelling.'."\n"
            .'- "moments": an array of memorable moments, each {"type": one of '.implode('|', Recap::MOMENT_TYPES).', "description": string, "context": a short note on what led to it}.'."\n"
            .'- "outline": an array of scenes, each {"title": string, "detail": string} in play order.'."\n"
            .'- "next_steps": an array of short bullet-point strings for what the party decided or intends to do next — plans, leads to chase, unresolved goals. Use an empty array if none were stated.'."\n"
            .'- "entities": an array of the notable people, places, factions, items, monsters and spells that appeared, each {"name": string, "type": one of '.implode('|', Recap::ENTITY_TYPES).', "description": string}.'
            ."\n\nExisting world so far:\n".($context !== '' ? $context : '(no entries yet)');

        $reply = $this->ai->chat(
            $system,
            [['role' => 'user', 'content' => "Session title: {$session->title}\n\nTranscript:\n\n{$transcript}"]],
            // A long session (full recap + many entities + moments + steps) is a large reply; too small a
            // cap truncates the JSON mid-object and the parse fails. Give it generous headroom — a
            // multi-hour session can run to ~20k tokens of structured output.
            24000,
            new AiUsageContext('session_recap', $world->id, $userId, 'analyze'),
            // A whole-session transcript is a large prompt with a long reply; give it far more than the
            // interactive default. Stays under AnalyseRecap's 600s job timeout so the client fails cleanly.
            timeout: 540,
        );

        $data = AiJson::object($reply);
        if ($data === null) {
            throw new RuntimeException('The assistant did not return a usable recap analysis.');
        }

        return [
            'recap_full' => trim((string) ($data['recap_full'] ?? '')),
            'recap_short' => trim((string) ($data['recap_short'] ?? '')),
            'recap_stylized' => trim((string) ($data['recap_stylized'] ?? '')),
            'moments' => $this->cleanMoments($data['moments'] ?? []),
            'outline' => $this->cleanOutline($data['outline'] ?? []),
            'next_steps' => $this->cleanSteps($data['next_steps'] ?? []),
            'entities' => $this->cleanEntities($data['entities'] ?? []),
        ];
    }

    /**
     * @return list<string>
     */
    private function cleanSteps(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $steps = [];
        foreach ($raw as $step) {
            // Tolerate the model wrapping a bullet in an object rather than returning a plain string.
            if (is_array($step)) {
                $step = $step['text'] ?? $step['step'] ?? $step['action'] ?? '';
            }
            $text = trim((string) $step);
            if ($text !== '') {
                $steps[] = Str::limit($text, 300, '');
            }
        }

        return $steps;
    }

    /**
     * @return list<array{type: string, description: string, context: string}>
     */
    private function cleanMoments(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $moments = [];
        foreach ($raw as $moment) {
            if (! is_array($moment)) {
                continue;
            }
            $description = trim((string) ($moment['description'] ?? ''));
            if ($description === '') {
                continue;
            }
            $type = Str::lower(trim((string) ($moment['type'] ?? '')));
            if (! in_array($type, Recap::MOMENT_TYPES, true)) {
                $type = 'story';
            }
            $moments[] = [
                'type' => $type,
                'description' => $description,
                'context' => trim((string) ($moment['context'] ?? '')),
            ];
        }

        return $moments;
    }

    /**
     * @return list<array{title: string, detail: string}>
     */
    private function cleanOutline(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $scenes = [];
        foreach ($raw as $scene) {
            if (! is_array($scene)) {
                continue;
            }
            $title = trim((string) ($scene['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $scenes[] = [
                'title' => Str::limit($title, 180, ''),
                'detail' => trim((string) ($scene['detail'] ?? '')),
            ];
        }

        return $scenes;
    }

    /**
     * @return list<array{name: string, type: string, description: string}>
     */
    private function cleanEntities(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $entities = [];
        foreach ($raw as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            $name = trim((string) ($entity['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $type = Str::lower(trim((string) ($entity['type'] ?? '')));
            if (! in_array($type, Recap::ENTITY_TYPES, true)) {
                $type = 'npc';
            }
            $entities[] = [
                'name' => Str::limit($name, 180, ''),
                'type' => $type,
                'description' => trim((string) ($entity['description'] ?? '')),
            ];
        }

        return $entities;
    }
}
