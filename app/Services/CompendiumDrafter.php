<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\AiJson;
use App\Support\AiUsageContext;
use App\Support\Compendium;
use App\Support\CompendiumFields;

/**
 * The structured "draft this entry" flow shared by the GM and admin compendium editors: builds the
 * prompt for the entry's type (monster stat block, schema fields, or freeform document), calls Claude,
 * and validates the reply. Budgeting/authorisation stays in the controllers that call this.
 */
class CompendiumDrafter
{
    public function __construct(protected AnthropicClient $ai) {}

    public function configured(): bool
    {
        return $this->ai->configured();
    }

    /**
     * @param  array<string, mixed>  $currentBlock
     * @param  array<string, mixed>  $currentFields
     * @param  list<array{role: string, content: string}>  $history
     * @return array{reply: string, summary: string, document: string, block: ?array, fields: ?array}|null null when the reply wasn't JSON
     */
    public function draft(string $itemType, string $name, ?string $worldName, array $currentBlock, array $currentFields, string $document, string $prompt, array $history, ?AiUsageContext $usage = null): ?array
    {
        $isMonster = $itemType === 'monster';
        $schema = $isMonster ? [] : CompendiumFields::for($itemType);
        $hasSchema = $schema !== [];

        $shape = match (true) {
            $isMonster => "{\"block\": { ... }, \"summary\": \"...\", \"reply\": \"...\"}\n"
                .'- "block": the COMPLETE 5e stat block reflecting your change, with these keys: '
                .'size, type, alignment, ac, hp, speed, cr (strings); abilities {str,dex,con,int,wis,cha} (integers 1-30); '
                .'saves, skills, senses, languages, resistances, immunities, conditionImmunities, vulnerabilities (strings, "" if none); '
                .'traits, actions, bonusActions, reactions, legendary (arrays of {"name","desc"}). Omit "block" when only asking a question.',
            $hasSchema => "{\"fields\": { ... }, \"summary\": \"...\", \"reply\": \"...\"}\n"
                ."- \"fields\": an object with these keys (fill the ones you can; omit \"fields\" when only asking a question):\n".$this->describeSchema($schema),
            default => "{\"document\": \"...\", \"summary\": \"...\", \"reply\": \"...\"}\n"
                .'- "document": the COMPLETE entry in Markdown. Omit it (or use "") when you are only asking a question — never a fragment.',
        };
        $current = match (true) {
            $isMonster => "\n\nThe current stat block (JSON) is:\n\n".json_encode($currentBlock, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $hasSchema => "\n\nThe current fields (JSON) are:\n\n".json_encode($currentFields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            default => "\n\nThe current entry is:\n\n{$document}",
        };

        $typeLabel = Compendium::label($itemType);
        $where = $worldName !== null ? "for the tabletop RPG campaign \"{$worldName}\"" : 'for the shared compendium library';
        $system = "You are a worldbuilding assistant editing a \"{$typeLabel}\" compendium entry titled \"{$name}\" {$where}."
            .' Work like a careful collaborator: do exactly what the GM asks — no more, no less.'
            ."\n\nChoose the ONE mode that fits the GM's message:\n"
            ."1. CLARIFY — if the request is ambiguous or you're missing something you need, ask a short question in \"reply\" and change nothing else.\n"
            ."2. TARGETED CHANGE — if the GM asks to change a specific thing, change ONLY that and leave everything else exactly as it is.\n"
            ."3. FULL (RE)WRITE — only if the GM asks to write, draft, or rewrite the whole entry.\n\n"
            .'Respond with a SINGLE JSON object and nothing else — no prose, no code fences:'."\n"
            .$shape
            ."\n- \"summary\": one short sentence for lists; include only when you want to change it."
            ."\n- \"reply\": one or two short sentences to the GM — your question, or what you changed."
            .$current;

        $messages = [];
        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $prompt];

        $raw = $this->ai->chat($system, $messages, 2400, $usage);
        $parsed = AiJson::object($raw);
        if ($parsed === null) {
            return null;
        }

        return [
            'reply' => is_string($parsed['reply'] ?? null) ? $parsed['reply'] : '',
            'summary' => is_string($parsed['summary'] ?? null) ? trim($parsed['summary']) : '',
            'document' => (! $isMonster && ! $hasSchema && is_string($parsed['document'] ?? null)) ? $parsed['document'] : '',
            'block' => $isMonster ? $this->sanitiseBlock($parsed['block'] ?? null, $currentBlock) : null,
            'fields' => $hasSchema ? $this->sanitiseSchemaFields($parsed['fields'] ?? null, $schema) : null,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, type: string, options?: list<string>}>  $schema
     */
    private function describeSchema(array $schema): string
    {
        return collect($schema)->map(function (array $field): string {
            $rule = match ($field['type'] ?? 'text') {
                'select' => 'one of: '.implode(', ', $field['options'] ?? []),
                'longtext' => 'a short paragraph of Markdown',
                default => 'a short string',
            };

            return "  - \"{$field['key']}\" ({$field['label']}): {$rule}";
        })->implode("\n");
    }

    /**
     * @param  list<array{key: string, label: string, type: string, options?: list<string>}>  $schema
     * @return array<string, string>
     */
    private function sanitiseSchemaFields(mixed $returned, array $schema): array
    {
        if (! is_array($returned)) {
            return [];
        }

        $clean = [];
        foreach ($schema as $field) {
            $key = $field['key'];
            if (! array_key_exists($key, $returned) || ! is_scalar($returned[$key])) {
                continue;
            }
            $value = trim((string) $returned[$key]);
            if ($value === '') {
                continue;
            }
            if (($field['type'] ?? '') === 'select') {
                $match = null;
                foreach ($field['options'] ?? [] as $option) {
                    if (mb_strtolower($option) === mb_strtolower($value)) {
                        $match = $option;
                        break;
                    }
                }
                if ($match === null) {
                    continue;
                }
                $value = $match;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>|null
     */
    private function sanitiseBlock(mixed $returned, array $current): ?array
    {
        if (! is_array($returned) || $returned === []) {
            return null;
        }

        $block = $current;

        foreach (['size', 'type', 'alignment', 'ac', 'hp', 'speed', 'cr', 'saves', 'skills', 'vulnerabilities', 'resistances', 'immunities', 'conditionImmunities', 'senses', 'languages'] as $key) {
            if (array_key_exists($key, $returned) && is_scalar($returned[$key])) {
                $block[$key] = trim((string) $returned[$key]);
            }
        }

        if (isset($returned['abilities']) && is_array($returned['abilities'])) {
            foreach (['str', 'dex', 'con', 'int', 'wis', 'cha'] as $ability) {
                $value = $returned['abilities'][$ability] ?? null;
                if (is_numeric($value)) {
                    $block['abilities'][$ability] = (int) $value;
                }
            }
        }

        foreach (['traits', 'actions', 'bonusActions', 'reactions', 'legendary'] as $group) {
            if (! array_key_exists($group, $returned) || ! is_array($returned[$group])) {
                continue;
            }
            $block[$group] = collect($returned[$group])
                ->filter(fn ($entry) => is_array($entry))
                ->map(fn ($entry) => [
                    'name' => is_scalar($entry['name'] ?? null) ? trim((string) $entry['name']) : '',
                    'desc' => is_scalar($entry['desc'] ?? null) ? trim((string) $entry['desc']) : '',
                ])
                ->filter(fn ($entry) => $entry['name'] !== '' || $entry['desc'] !== '')
                ->values()
                ->all();
        }

        return $block;
    }
}
