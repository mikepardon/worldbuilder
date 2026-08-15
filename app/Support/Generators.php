<?php

declare(strict_types=1);

namespace App\Support;

/**
 * AI content generators a GM can roll on and drop into the world. Each kind describes what one result
 * is (`noun`), the tick-box / select `options` that shape a batch (e.g. "include a stat block",
 * item rarity), and the `imports` a result can become — a world entry (a Document of some `kind`) or a
 * compendium stat block (a CampaignCompendiumItem of some type). Results always carry a `title` and a
 * one-line `detail`; options can add a longer `description` and a `statblock` sketch.
 */
class Generators
{
    /**
     * @var array<string, array{
     *     key: string, label: string, noun: string, placeholder: string,
     *     options: list<array{key: string, label: string, type: string, default: bool|string, choices?: list<string>}>,
     *     imports: list<array{target: string, kind: string, label: string}>,
     * }>
     */
    public const KINDS = [
        'names' => [
            'key' => 'names', 'label' => 'Names',
            'noun' => 'evocative names',
            'placeholder' => 'e.g. dwarven surnames, drowned-coast towns',
            'options' => [
                ['key' => 'subject', 'label' => 'For', 'type' => 'select', 'default' => 'people',
                    'choices' => ['people', 'places', 'things', 'creatures', 'titles']],
                ['key' => 'description', 'label' => 'Add a one-line description', 'type' => 'toggle', 'default' => false],
            ],
            'imports' => [],
        ],
        'npc' => [
            'key' => 'npc', 'label' => 'People',
            'noun' => 'notable people (an individual or a known group)',
            'placeholder' => "Describe a person or group that's known — e.g. the harbourmaster and her clerks",
            'options' => [
                ['key' => 'description', 'label' => 'Include a description', 'type' => 'toggle', 'default' => true],
                ['key' => 'role', 'label' => 'Give a role or occupation', 'type' => 'toggle', 'default' => true],
                ['key' => 'statblock', 'label' => 'Include a combat stat block', 'type' => 'toggle', 'default' => false],
            ],
            'imports' => [
                ['target' => 'entry', 'kind' => 'npc', 'label' => 'New person'],
                ['target' => 'statblock', 'kind' => 'monster', 'label' => 'Add stat block'],
            ],
        ],
        'location' => [
            'key' => 'location', 'label' => 'Places',
            'noun' => 'locations',
            'placeholder' => 'e.g. seedy taverns, ruined shrines',
            'options' => [
                ['key' => 'description', 'label' => 'Include a description', 'type' => 'toggle', 'default' => true],
                ['key' => 'place_type', 'label' => 'Type of place', 'type' => 'select', 'default' => 'any',
                    'choices' => ['any', 'settlement', 'wilderness', 'dungeon', 'building', 'landmark']],
            ],
            'imports' => [
                ['target' => 'entry', 'kind' => 'location', 'label' => 'New place'],
            ],
        ],
        'faction' => [
            'key' => 'faction', 'label' => 'Factions',
            'noun' => 'factions or organisations',
            'placeholder' => 'e.g. smuggling rings, merchant guilds',
            'options' => [
                ['key' => 'description', 'label' => 'Include a description', 'type' => 'toggle', 'default' => true],
                ['key' => 'goal', 'label' => 'Include a goal or agenda', 'type' => 'toggle', 'default' => true],
            ],
            'imports' => [
                ['target' => 'entry', 'kind' => 'faction', 'label' => 'New faction'],
            ],
        ],
        'item' => [
            'key' => 'item', 'label' => 'Items',
            'noun' => 'notable items',
            'placeholder' => 'e.g. cursed relics, exotic trade goods',
            'options' => [
                ['key' => 'description', 'label' => 'Include a description', 'type' => 'toggle', 'default' => true],
                ['key' => 'item_type', 'label' => 'Type', 'type' => 'select', 'default' => 'any',
                    'choices' => ['any', 'weapon', 'armour', 'wondrous item', 'potion', 'ring', 'scroll', 'trinket']],
                ['key' => 'rarity', 'label' => 'Rarity', 'type' => 'select', 'default' => 'any',
                    'choices' => ['any', 'common', 'uncommon', 'rare', 'very rare', 'legendary', 'artifact']],
            ],
            'imports' => [
                ['target' => 'entry', 'kind' => 'item', 'label' => 'New item'],
                ['target' => 'statblock', 'kind' => 'magicitem', 'label' => 'Add to compendium'],
            ],
        ],
        'encounter' => [
            'key' => 'encounter', 'label' => 'Encounters',
            'noun' => 'encounter ideas',
            'placeholder' => 'e.g. on the tidal flats at night',
            'options' => [
                ['key' => 'difficulty', 'label' => 'Difficulty', 'type' => 'select', 'default' => 'any',
                    'choices' => ['any', 'easy', 'medium', 'hard', 'deadly']],
                ['key' => 'statblock', 'label' => 'Stat block for the main foe', 'type' => 'toggle', 'default' => false],
            ],
            'imports' => [
                ['target' => 'entry', 'kind' => 'quest', 'label' => 'New quest'],
                ['target' => 'statblock', 'kind' => 'monster', 'label' => 'Add foe stat block'],
            ],
        ],
        'plot_hook' => [
            'key' => 'plot_hook', 'label' => 'Plot hooks',
            'noun' => 'plot hooks',
            'placeholder' => 'e.g. tied to the missing heir',
            'options' => [],
            'imports' => [
                ['target' => 'entry', 'kind' => 'quest', 'label' => 'New quest'],
            ],
        ],
    ];

    public static function isKind(string $key): bool
    {
        return isset(self::KINDS[$key]);
    }

    /**
     * @return array{
     *     key: string, label: string, noun: string, placeholder: string,
     *     options: list<array{key: string, label: string, type: string, default: bool|string, choices?: list<string>}>,
     *     imports: list<array{target: string, kind: string, label: string}>,
     * }|null
     */
    public static function find(string $key): ?array
    {
        return self::KINDS[$key] ?? null;
    }

    /** The option spec for one option key within a generator kind, if it exists. */
    public static function option(string $kind, string $optionKey): ?array
    {
        foreach (self::KINDS[$kind]['options'] ?? [] as $option) {
            if ($option['key'] === $optionKey) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *     key: string, label: string, placeholder: string,
     *     options: list<array{key: string, label: string, type: string, default: bool|string, choices?: list<string>}>,
     *     imports: list<array{target: string, kind: string, label: string}>,
     * }>
     */
    public static function all(): array
    {
        return collect(self::KINDS)->map(fn (array $kind) => [
            'key' => $kind['key'],
            'label' => $kind['label'],
            'placeholder' => $kind['placeholder'],
            'options' => $kind['options'],
            'imports' => $kind['imports'],
        ])->values()->all();
    }
}
