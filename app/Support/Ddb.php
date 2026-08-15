<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Character;
use App\Services\DdbClient;

/**
 * Maps raw D&D Beyond records into our compendium shapes: monsters into the structured stat block, and
 * items/spells into the schema-driven fields of {@see CompendiumFields}. DDB stores monster facets as
 * numeric ids (size/type/alignment/CR/movement/…) resolved via the config lookups
 * {@see DdbClient::lookups}; item/spell records carry named fields (often under a
 * `definition` object) plus HTML description blobs, which we flatten to text.
 */
class Ddb
{
    /** DDB rarity strings that need normalising to our {@see CompendiumFields} option casing. */
    private const RARITIES = [
        'common' => 'Common', 'uncommon' => 'Uncommon', 'rare' => 'Rare',
        'very rare' => 'Very rare', 'legendary' => 'Legendary', 'artifact' => 'Artifact',
    ];

    /** DDB item filter types mapped to our magic-item category options. */
    private const ITEM_CATEGORIES = [
        'weapon' => 'Weapon', 'armor' => 'Armor', 'wondrous item' => 'Wondrous item',
        'ring' => 'Ring', 'rod' => 'Rod', 'staff' => 'Staff', 'wand' => 'Wand',
        'potion' => 'Potion', 'scroll' => 'Scroll',
    ];

    /** DDB spell activation type ids mapped to casting-time units. */
    private const ACTIVATION_UNITS = [
        1 => 'action', 3 => 'bonus action', 4 => 'reaction',
        6 => 'minute', 7 => 'hour', 8 => 'special',
    ];

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, array<int, string>>  $lookups
     * @return array{name: string, summary: string, block: array<string, mixed>, document: string, image: array{url: string, key: string}}
     */
    public static function toItem(array $raw, array $lookups): array
    {
        $name = trim((string) ($raw['name'] ?? 'Unnamed'));
        $block = self::toBlock($raw, $lookups);
        $alignment = $block['alignment'] !== '' ? ", {$block['alignment']}" : '';

        return [
            'name' => $name,
            'summary' => trim("{$block['size']} {$block['type']}{$alignment}"),
            'block' => $block,
            'document' => Statblock::toMarkdown($block, $name),
            'image' => self::image($raw, 'monster', $raw['id'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, array<int, string>>  $lookups
     * @return array<string, mixed>
     */
    public static function toBlock(array $raw, array $lookups): array
    {
        $look = fn (string $collection, $id): string => $lookups[$collection][(int) $id] ?? '';

        $stats = [];
        foreach ($raw['stats'] ?? [] as $stat) {
            $stats[(int) ($stat['statId'] ?? 0)] = (int) ($stat['value'] ?? 10);
        }

        $speed = [];
        foreach ($raw['movements'] ?? [] as $move) {
            $label = mb_strtolower($look('movements', $move['movementId'] ?? 0));
            $value = $move['speed'] ?? null;
            if ($value !== null) {
                $speed[] = trim(($label === 'walk' || $label === '' ? '' : "{$label} ")."{$value} ft.");
            }
        }

        $dice = $raw['hitPointDice'] ?? [];
        $diceText = '';
        if ((int) ($dice['diceCount'] ?? 0) > 0) {
            $diceText = "{$dice['diceCount']}d{$dice['diceValue']}";
            if ((int) ($dice['fixedValue'] ?? 0) !== 0) {
                $diceText .= ' + '.$dice['fixedValue'];
            }
        }

        $ac = (string) ($raw['armorClass'] ?? '');
        $acDesc = trim((string) ($raw['armorClassDescription'] ?? ''), ' ()');

        $conditionImmunities = [];
        foreach ($raw['conditionImmunities'] ?? [] as $condition) {
            $id = is_array($condition) ? ($condition['conditionId'] ?? 0) : $condition;
            $name = $look('conditions', $id);
            if ($name !== '') {
                $conditionImmunities[] = $name;
            }
        }

        $senses = [];
        foreach ($raw['senses'] ?? [] as $sense) {
            $name = $look('senses', $sense['senseId'] ?? 0);
            $notes = trim((string) ($sense['notes'] ?? ''));
            if ($name !== '') {
                $senses[] = trim("{$name} {$notes}");
            }
        }
        if (($raw['passivePerception'] ?? null) !== null) {
            $senses[] = 'passive Perception '.$raw['passivePerception'];
        }

        return array_merge(Statblock::empty(), [
            'size' => $look('sizes', $raw['sizeId'] ?? 0) ?: 'Medium',
            'type' => $look('monsterTypes', $raw['typeId'] ?? 0) ?: 'humanoid',
            'alignment' => $look('alignments', $raw['alignmentId'] ?? 0),
            'ac' => $acDesc !== '' ? "{$ac} ({$acDesc})" : $ac,
            'hp' => trim(($raw['averageHitPoints'] ?? '').($diceText !== '' ? " ({$diceText})" : '')),
            'speed' => implode(', ', array_filter($speed)) ?: '30 ft.',
            'abilities' => [
                'str' => $stats[1] ?? 10, 'dex' => $stats[2] ?? 10, 'con' => $stats[3] ?? 10,
                'int' => $stats[4] ?? 10, 'wis' => $stats[5] ?? 10, 'cha' => $stats[6] ?? 10,
            ],
            'senses' => implode(', ', $senses),
            'languages' => trim((string) ($raw['languageNote'] ?? '')),
            'conditionImmunities' => implode(', ', $conditionImmunities),
            'cr' => (string) $look('challengeRatings', $raw['challengeRatingId'] ?? 0),
            'traits' => self::entries($raw['specialTraitsDescription'] ?? ''),
            'actions' => self::entries($raw['actionsDescription'] ?? ''),
            'bonusActions' => self::entries($raw['bonusActionsDescription'] ?? ''),
            'reactions' => self::entries($raw['reactionsDescription'] ?? ''),
            'legendary' => self::entries($raw['legendaryActionsDescription'] ?? ''),
            'mythic' => self::entries($raw['mythicActionsDescription'] ?? ''),
        ]);
    }

    /**
     * Map a DDB item record into a magic item (when magical) or a piece of equipment (otherwise),
     * returning the structured {@see CompendiumFields} fields plus the rendered document.
     *
     * @param  array<string, mixed>  $raw
     * @return array{item_type: string, name: string, summary: string, fields: array<string, string>, document: string, image: array{url: string, key: string}}
     */
    public static function itemToItem(array $raw): array
    {
        $def = is_array($raw['definition'] ?? null) ? $raw['definition'] : $raw;

        $name = trim((string) ($def['name'] ?? ''));
        $description = self::html($def['description'] ?? '');
        $image = self::image($def, 'item', $def['id'] ?? $raw['id'] ?? null);

        if ((bool) ($def['magic'] ?? false) === true) {
            $rarity = self::RARITIES[mb_strtolower(trim((string) ($def['rarity'] ?? '')))] ?? '';
            $category = self::ITEM_CATEGORIES[mb_strtolower(trim((string) ($def['filterType'] ?? '')))] ?? 'Wondrous item';

            $fields = [
                'category' => $category,
                'rarity' => $rarity,
                'attunement' => (bool) ($def['canAttune'] ?? false) ? 'Yes' : 'No',
                'description' => $description,
            ];
            $summary = trim("{$rarity} {$category}");

            return self::structured('magicitem', $name, $summary, $fields, $image);
        }

        $damage = trim((string) (($def['damage']['diceString'] ?? '') ?: ''));
        $properties = [];
        foreach ($def['properties'] ?? [] as $property) {
            $label = trim((string) (is_array($property) ? ($property['name'] ?? '') : $property));
            if ($label !== '') {
                $properties[] = $label;
            }
        }
        $strength = (int) ($def['strengthRequirement'] ?? 0);
        $armorClass = $def['armorClass'] ?? null;

        $fields = [
            'category' => trim((string) ($def['filterType'] ?? $def['type'] ?? $def['subType'] ?? '')),
            'cost' => self::cost($def['cost'] ?? null),
            'weight' => ($weight = (float) ($def['weight'] ?? 0)) > 0 ? self::number($weight).' lb.' : '',
            'damage' => $damage,
            'damage_type' => trim((string) ($def['damageType'] ?? '')),
            'properties' => implode(', ', $properties),
            'ac' => $armorClass !== null && $armorClass !== '' ? (string) $armorClass : '',
            'strength' => $strength > 0 ? "Str {$strength}" : '',
            'stealth' => (int) ($def['stealthCheck'] ?? 0) === 2 ? 'Disadvantage' : '—',
            'description' => $description,
        ];

        return self::structured('equipment', $name, trim((string) $fields['category']), $fields, $image);
    }

    /**
     * Map a DDB spell record into our spell fields plus the rendered document.
     *
     * @param  array<string, mixed>  $raw
     * @return array{item_type: string, name: string, summary: string, fields: array<string, string>, document: string, image: array{url: string, key: string}}
     */
    public static function spellToItem(array $raw): array
    {
        $def = is_array($raw['definition'] ?? null) ? $raw['definition'] : $raw;

        $name = trim((string) ($def['name'] ?? ''));
        $level = (int) ($def['level'] ?? 0);
        $levelLabel = $level === 0 ? 'Cantrip' : self::ordinal($level);
        $school = trim((string) ($def['school'] ?? ''));

        $fields = [
            'level' => $levelLabel,
            'school' => $school,
            'casting_time' => self::castingTime($def['activation'] ?? []),
            'range' => self::range($def['range'] ?? []),
            'components' => self::components($def),
            'duration' => self::duration($def),
            'description' => self::html($def['description'] ?? ''),
            'higher_levels' => self::html($def['higherLevelDescription'] ?? ''),
        ];

        $summary = $level === 0
            ? trim("{$school} cantrip")
            : trim("{$levelLabel}-level {$school}");

        return self::structured('spell', $name, $summary, $fields);
    }

    /**
     * Map a raw D&D Beyond character record ({@see DdbClient::character}) into a battle-room token spec:
     * name, portrait, current/max HP, AC, and a one-line profile summary for the player-notes panel.
     *
     * @param  array<string, mixed>  $raw
     * @return array{name: string, image: array{url: string, key: string}, hp: int|null, max_hp: int|null, ac: int|null, notes: string}
     */
    public static function characterToToken(array $raw, string $id): array
    {
        $maxHp = self::maxHp($raw);
        $hp = $maxHp !== null ? max(0, $maxHp - (int) ($raw['removedHitPoints'] ?? 0)) : null;

        $ac = self::characterAc($raw, self::abilityScores($raw));

        $avatar = trim((string) ($raw['decorations']['avatarUrl'] ?? ''));
        $image = $avatar === ''
            ? ['url' => '', 'key' => '']
            : ['url' => $avatar, 'key' => "ddb-char-{$id}-".sha1($avatar)];

        return [
            'name' => trim((string) ($raw['name'] ?? '')) ?: 'Character',
            'image' => $image,
            'hp' => $hp,
            'max_hp' => $maxHp,
            'ac' => $ac,
            'notes' => self::characterSummary($raw),
        ];
    }

    /**
     * Map a raw D&D Beyond character into our {@see Character} profile shape: name, portrait,
     * level, class, race, AC, HP and ability scores.
     *
     * @param  array<string, mixed>  $raw
     * @return array{name: string, image: array{url: string, key: string}, level: int|null, class: string|null, race: string|null, ac: int|null, hp: int|null, max_hp: int|null, stats: array<string, int>}
     */
    public static function characterProfile(array $raw, string $id): array
    {
        $token = self::characterToToken($raw, $id);

        $totalLevel = 0;
        $classParts = [];
        foreach ($raw['classes'] ?? [] as $class) {
            $totalLevel += (int) ($class['level'] ?? 0);
            $name = trim((string) ($class['definition']['name'] ?? ''));
            if ($name !== '') {
                $classParts[] = $name;
            }
        }
        $level = $totalLevel > 0 ? $totalLevel : null;

        $scores = self::abilityScores($raw);
        $wisMod = self::modifier($scores['wis']);
        $proficiency = $level !== null ? (int) floor(($level - 1) / 4) + 2 : 2;
        $passivePerception = 10 + $wisMod + (self::proficientInPerception($raw) ? $proficiency : 0);

        $walk = (int) ($raw['race']['weightSpeeds']['normal']['walk'] ?? 0);

        return [
            'name' => $token['name'],
            'image' => $token['image'],
            'level' => $level,
            'class' => $classParts === [] ? null : implode(' / ', $classParts),
            'race' => trim((string) ($raw['race']['fullName'] ?? $raw['race']['baseName'] ?? '')) ?: null,
            'speed' => $walk > 0 ? $walk : null,
            'passive_perception' => $passivePerception,
            'ac' => $token['ac'],
            'hp' => $token['hp'],
            'max_hp' => $token['max_hp'],
            'stats' => $scores,
        ];
    }

    /**
     * A D&D Beyond character's effective ability scores: base + racial/feat/item bonuses, respecting any
     * set/override values. Keys str,dex,con,int,wis,cha.
     *
     * @param  array<string, mixed>  $raw
     * @return array{str: int, dex: int, con: int, int: int, wis: int, cha: int}
     */
    private static function abilityScores(array $raw): array
    {
        $keys = [1 => 'str', 2 => 'dex', 3 => 'con', 4 => 'int', 5 => 'wis', 6 => 'cha'];
        $scores = ['str' => 10, 'dex' => 10, 'con' => 10, 'int' => 10, 'wis' => 10, 'cha' => 10];

        $apply = function (array $rows, string $mode) use (&$scores, $keys): void {
            foreach ($rows as $row) {
                $key = $keys[(int) ($row['id'] ?? 0)] ?? null;
                if ($key === null || ($row['value'] ?? null) === null) {
                    continue;
                }
                $scores[$key] = $mode === 'add' ? $scores[$key] + (int) $row['value'] : (int) $row['value'];
            }
        };

        $apply($raw['stats'] ?? [], 'set');       // base
        $apply($raw['bonusStats'] ?? [], 'add');  // racial/ASI stored separately
        $apply($raw['overrideStats'] ?? [], 'set'); // explicit overrides win

        // Modifier-based ability bonuses (race, feats, items, …).
        $abilityKey = [
            'strength' => 'str', 'dexterity' => 'dex', 'constitution' => 'con',
            'intelligence' => 'int', 'wisdom' => 'wis', 'charisma' => 'cha',
        ];
        foreach (($raw['modifiers'] ?? []) as $group) {
            foreach ((is_array($group) ? $group : []) as $modifier) {
                if (($modifier['type'] ?? '') !== 'bonus') {
                    continue;
                }
                $subType = (string) ($modifier['subType'] ?? '');
                if (! str_ends_with($subType, '-score')) {
                    continue;
                }
                $key = $abilityKey[substr($subType, 0, -6)] ?? null;
                if ($key !== null) {
                    $scores[$key] += (int) ($modifier['value'] ?? $modifier['fixedValue'] ?? 0);
                }
            }
        }

        return $scores;
    }

    /**
     * Best-effort Armor Class: an explicit override if present, else base armour (from equipped items)
     * plus the appropriate Dex bonus and any shield, else unarmoured 10 + Dex.
     *
     * @param  array<string, mixed>  $raw
     * @param  array{str: int, dex: int, con: int, int: int, wis: int, cha: int}  $scores
     */
    private static function characterAc(array $raw, array $scores): ?int
    {
        if (is_numeric($raw['armorClass'] ?? null)) {
            return (int) $raw['armorClass'];
        }

        $dexMod = self::modifier($scores['dex']);
        $bodyAc = null;
        $bodyType = 0;
        $shield = 0;

        foreach ($raw['inventory'] ?? [] as $item) {
            if (empty($item['equipped'])) {
                continue;
            }
            $definition = $item['definition'] ?? [];
            $armour = $definition['armorClass'] ?? null;
            $typeId = $definition['armorTypeId'] ?? null;
            if ($armour === null || $typeId === null) {
                continue;
            }
            if ((int) $typeId === 4) { // shield
                $shield += (int) $armour;
            } elseif ($bodyAc === null || (int) $armour > $bodyAc) {
                $bodyAc = (int) $armour;
                $bodyType = (int) $typeId;
            }
        }

        // Flat AC bonuses from items/feats (rings, cloaks, …).
        $acBonus = 0;
        foreach (self::flatModifiers($raw) as $modifier) {
            if (($modifier['type'] ?? '') === 'bonus' && ($modifier['subType'] ?? '') === 'armor-class') {
                $acBonus += (int) ($modifier['value'] ?? $modifier['fixedValue'] ?? 0);
            }
        }

        // Unarmoured base is 10 + Dex, or 13 + Dex when the character has Mage Armor available.
        $unarmoured = (self::knowsSpell($raw, 'Mage Armor') ? 13 : 10) + $dexMod;

        $base = match (true) {
            $bodyAc === null => $unarmoured,
            $bodyType === 1 => $bodyAc + $dexMod,        // light
            $bodyType === 2 => $bodyAc + min($dexMod, 2), // medium
            $bodyType === 3 => $bodyAc,                  // heavy
            default => $bodyAc + $dexMod,
        };

        return $base + $shield + $acBonus;
    }

    /** Whether the character knows/has a spell by name, across every granting source. */
    private static function knowsSpell(array $raw, string $name): bool
    {
        foreach ($raw['classSpells'] ?? [] as $classSpells) {
            foreach ($classSpells['spells'] ?? [] as $spell) {
                if (($spell['definition']['name'] ?? '') === $name) {
                    return true;
                }
            }
        }
        foreach (['race', 'class', 'feat', 'item', 'background'] as $source) {
            foreach ($raw['spells'][$source] ?? [] as $spell) {
                if (($spell['definition']['name'] ?? $spell['name'] ?? '') === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param  array<string, mixed>  $raw */
    private static function proficientInPerception(array $raw): bool
    {
        foreach (($raw['modifiers'] ?? []) as $group) {
            foreach ((is_array($group) ? $group : []) as $modifier) {
                if (($modifier['type'] ?? '') === 'proficiency' && ($modifier['subType'] ?? '') === 'perception') {
                    return true;
                }
            }
        }

        return false;
    }

    private static function modifier(int $score): int
    {
        return (int) floor(($score - 10) / 2);
    }

    /** The 18 skills, keyed by DDB subType, with label and governing ability. */
    private const SKILLS = [
        ['acrobatics', 'Acrobatics', 'dex'], ['animal-handling', 'Animal Handling', 'wis'],
        ['arcana', 'Arcana', 'int'], ['athletics', 'Athletics', 'str'],
        ['deception', 'Deception', 'cha'], ['history', 'History', 'int'],
        ['insight', 'Insight', 'wis'], ['intimidation', 'Intimidation', 'cha'],
        ['investigation', 'Investigation', 'int'], ['medicine', 'Medicine', 'wis'],
        ['nature', 'Nature', 'int'], ['perception', 'Perception', 'wis'],
        ['performance', 'Performance', 'cha'], ['persuasion', 'Persuasion', 'cha'],
        ['religion', 'Religion', 'int'], ['sleight-of-hand', 'Sleight of Hand', 'dex'],
        ['stealth', 'Stealth', 'dex'], ['survival', 'Survival', 'wis'],
    ];

    private const ABILITY_NAMES = [
        'str' => 'strength', 'dex' => 'dexterity', 'con' => 'constitution',
        'int' => 'intelligence', 'wis' => 'wisdom', 'cha' => 'charisma',
    ];

    /** DDB ability ids (1-6) to our short ability keys. */
    private const ABILITY_BY_ID = [
        1 => 'str', 2 => 'dex', 3 => 'con', 4 => 'int', 5 => 'wis', 6 => 'cha',
    ];

    /**
     * A full D&D Beyond character sheet: combat header, spellcasting, skills, saving throws, senses,
     * defences, proficiencies, attacks, features (full text), inventory, spells (full cards), spell
     * slots, currency, description, and the mutable combat state (HP, temp HP, death saves) that the
     * in-play sheet edits. Best-effort and null-safe — DDB's shape varies by character.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public static function characterSheet(array $raw): array
    {
        $scores = self::abilityScores($raw);
        $level = 0;
        foreach ($raw['classes'] ?? [] as $class) {
            $level += (int) ($class['level'] ?? 0);
        }
        $proficiency = $level > 0 ? (int) floor(($level - 1) / 4) + 2 : 2;

        $mods = self::flatModifiers($raw);
        $has = fn (string $type, string $subType): bool => (bool) collect($mods)
            ->first(fn ($m) => ($m['type'] ?? '') === $type && ($m['subType'] ?? '') === $subType);

        $skills = [];
        foreach (self::SKILLS as [$key, $label, $ability]) {
            $proficient = $has('proficiency', $key);
            $expert = $has('expertise', $key);
            $skills[] = [
                'label' => $label,
                'ability' => mb_strtoupper($ability),
                'mod' => self::modifier($scores[$ability]) + ($proficient ? $proficiency : 0) + ($expert ? $proficiency : 0),
                'proficient' => $proficient || $expert,
            ];
        }

        $saves = [];
        foreach (self::ABILITY_NAMES as $short => $full) {
            $proficient = $has('proficiency', "{$full}-saving-throws");
            $saves[] = [
                'ability' => mb_strtoupper($short),
                'mod' => self::modifier($scores[$short]) + ($proficient ? $proficiency : 0),
                'proficient' => $proficient,
            ];
        }

        $named = fn (string $type): array => collect($mods)
            ->filter(fn ($m) => ($m['type'] ?? '') === $type)
            ->map(fn ($m) => self::titleCase((string) ($m['subType'] ?? '')))
            ->filter()->unique()->values()->all();

        $passivePerception = 10 + self::modifier($scores['wis'])
            + ($has('proficiency', 'perception') ? $proficiency : 0)
            + ($has('expertise', 'perception') ? $proficiency : 0);

        $features = [];
        foreach ($raw['classes'] ?? [] as $class) {
            $className = trim((string) ($class['definition']['name'] ?? 'Class'));
            foreach ($class['classFeatures'] ?? [] as $feature) {
                self::pushFeature($features, $feature['definition'] ?? [], $className);
            }
        }
        foreach ($raw['race']['racialTraits'] ?? [] as $trait) {
            self::pushFeature($features, $trait['definition'] ?? [], trim((string) ($raw['race']['baseName'] ?? 'Race')));
        }
        foreach ($raw['feats'] ?? [] as $feat) {
            self::pushFeature($features, $feat['definition'] ?? [], 'Feat');
        }

        $maxHp = self::maxHp($raw);

        return [
            'proficiency' => $proficiency,
            'initiative' => self::modifier($scores['dex']),
            'speed' => (int) ($raw['race']['weightSpeeds']['normal']['walk'] ?? 0) ?: null,
            'senses' => "Passive Perception {$passivePerception}",
            'passive_perception' => $passivePerception,
            'spellcasting' => self::spellcasting($raw, $scores, $proficiency),
            'skills' => $skills,
            'saves' => $saves,
            'defenses' => [
                'resistances' => $named('resistance'),
                'immunities' => $named('immunity'),
                'vulnerabilities' => $named('vulnerability'),
            ],
            'languages' => collect($mods)->filter(fn ($m) => ($m['type'] ?? '') === 'language')
                ->map(fn ($m) => self::titleCase((string) ($m['subType'] ?? '')))
                ->filter()->unique()->values()->all(),
            'actions' => self::attacks($raw, $scores, $proficiency),
            'features' => collect($features)->unique('name')->take(80)->values()->all(),
            'inventory' => self::inventoryDetail($raw),
            'spells' => self::spellList($raw),
            'spell_slots' => self::spellSlots($raw),
            'pact_slots' => self::pactSlots($raw),
            'hit_dice' => self::hitDice($raw),
            'currency' => self::currency($raw),
            'description' => self::description($raw),
            'hp' => [
                'max' => $maxHp,
                'removed' => (int) ($raw['removedHitPoints'] ?? 0),
                'temp' => (int) ($raw['temporaryHitPoints'] ?? 0),
            ],
            'death_saves' => [
                'successes' => (int) ($raw['deathSaves']['successCount'] ?? 0),
                'failures' => (int) ($raw['deathSaves']['failCount'] ?? 0),
            ],
        ];
    }

    /**
     * Maximum HP. DDB's baseHitPoints excludes the Constitution contribution, so add CON modifier per
     * character level (an explicit override still wins).
     *
     * @param  array<string, mixed>  $raw
     */
    private static function maxHp(array $raw): ?int
    {
        $override = $raw['overrideHitPoints'] ?? null;
        if ($override !== null && (int) $override > 0) {
            return (int) $override;
        }

        $level = 0;
        foreach ($raw['classes'] ?? [] as $class) {
            $level += (int) ($class['level'] ?? 0);
        }
        $conMod = self::modifier(self::abilityScores($raw)['con']);
        $max = (int) ($raw['baseHitPoints'] ?? 0) + (int) ($raw['bonusHitPoints'] ?? 0) + $conMod * $level;

        return $max > 0 ? $max : null;
    }

    /**
     * The character's spellcasting summary (ability, attack bonus, save DC) from its first caster class.
     *
     * @param  array<string, mixed>  $raw
     * @param  array{str: int, dex: int, con: int, int: int, wis: int, cha: int}  $scores
     * @return array{ability: string, attack: int, dc: int}|null
     */
    private static function spellcasting(array $raw, array $scores, int $proficiency): ?array
    {
        foreach ($raw['classes'] ?? [] as $class) {
            $abilityId = (int) ($class['definition']['spellCastingAbilityId'] ?? 0);
            $short = self::ABILITY_BY_ID[$abilityId] ?? null;
            if ($short !== null) {
                $mod = self::modifier($scores[$short]);

                return [
                    'ability' => mb_strtoupper($short),
                    'attack' => $mod + $proficiency,
                    'dc' => 8 + $mod + $proficiency,
                ];
            }
        }

        return null;
    }

    /**
     * Weapon (and other) attacks: to-hit and damage computed best-effort from equipped weapons plus any
     * DDB action entries flagged as attacks.
     *
     * @param  array<string, mixed>  $raw
     * @param  array{str: int, dex: int, con: int, int: int, wis: int, cha: int}  $scores
     * @return list<array{name: string, to_hit: int|null, damage: string, damage_type: string, range: string, save: string|null}>
     */
    private static function attacks(array $raw, array $scores, int $proficiency): array
    {
        $strMod = self::modifier($scores['str']);
        $dexMod = self::modifier($scores['dex']);
        $out = [];

        foreach ($raw['inventory'] ?? [] as $item) {
            $def = $item['definition'] ?? [];
            if (($def['filterType'] ?? '') !== 'Weapon') {
                continue;
            }
            $properties = array_map(
                fn ($p) => mb_strtolower((string) (is_array($p) ? ($p['name'] ?? '') : $p)),
                $def['properties'] ?? [],
            );
            $ranged = (int) ($def['attackType'] ?? 1) === 2;
            $finesse = in_array('finesse', $properties, true);
            $abilityMod = ($ranged || ($finesse && $dexMod > $strMod)) ? $dexMod : $strMod;

            $magic = 0;
            foreach ($def['grantedModifiers'] ?? [] as $granted) {
                if (($granted['type'] ?? '') === 'bonus' && ($granted['subType'] ?? '') === 'magic') {
                    $magic += (int) ($granted['value'] ?? $granted['fixedValue'] ?? 0);
                }
            }

            $proficientBonus = self::weaponProficient($raw, $def) ? $proficiency : 0;
            $dice = trim((string) ($def['damage']['diceString'] ?? ''));
            $damageBonus = $abilityMod + $magic;

            $out[] = [
                'name' => trim((string) ($def['name'] ?? 'Weapon')),
                'to_hit' => $abilityMod + $proficientBonus + $magic,
                'damage' => trim($dice.self::signedSuffix($damageBonus)),
                'damage_type' => trim((string) ($def['damageType'] ?? '')),
                'range' => $ranged ? 'Ranged' : 'Melee',
                'save' => null,
            ];
        }

        foreach (['race', 'class', 'background', 'item', 'feat'] as $source) {
            foreach ($raw['actions'][$source] ?? [] as $action) {
                if (empty($action['displayAsAttack']) && blank($action['dice'] ?? null)) {
                    continue;
                }
                $name = trim((string) ($action['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $abilityShort = self::ABILITY_BY_ID[(int) ($action['abilityModifierStatId'] ?? 0)] ?? null;
                $abilityMod = $abilityShort !== null ? self::modifier($scores[$abilityShort]) : 0;
                $fixedToHit = $action['fixedToHit'] ?? null;
                $toHit = $fixedToHit !== null
                    ? (int) $fixedToHit
                    : ($abilityShort !== null ? $abilityMod + ((bool) ($action['isProficient'] ?? false) ? $proficiency : 0) : null);
                $dice = trim((string) ($action['dice']['diceString'] ?? ''));
                $saveShort = self::ABILITY_BY_ID[(int) ($action['saveStatId'] ?? 0)] ?? null;

                $out[] = [
                    'name' => $name,
                    'to_hit' => $saveShort !== null ? null : $toHit,
                    'damage' => $dice !== '' ? trim($dice.self::signedSuffix($abilityShort !== null && $fixedToHit === null ? $abilityMod : 0)) : '',
                    'damage_type' => '',
                    'range' => trim((string) ($action['range']['range'] ?? '')) !== '' ? "{$action['range']['range']} ft" : '',
                    'save' => $saveShort !== null ? mb_strtoupper($saveShort).' '.((int) ($action['fixedSaveDc'] ?? 0) ?: '?') : null,
                ];
            }
        }

        return $out;
    }

    /**
     * Best-effort weapon proficiency: a specific weapon proficiency (e.g. "dagger") or the weapon's
     * category (DDB categoryId 1 = simple, 2 = martial).
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $def
     */
    private static function weaponProficient(array $raw, array $def): bool
    {
        $slug = self::slug((string) ($def['name'] ?? $def['type'] ?? ''));
        $category = (int) ($def['categoryId'] ?? 0);

        foreach (self::flatModifiers($raw) as $modifier) {
            if (($modifier['type'] ?? '') !== 'proficiency') {
                continue;
            }
            $subType = (string) ($modifier['subType'] ?? '');
            if ($subType === $slug
                || ($subType === 'simple-weapons' && $category === 1)
                || ($subType === 'martial-weapons' && $category === 2)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detailed inventory: name, type, quantity, equipped/attuned state, rarity, weight and a short blurb.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private static function inventoryDetail(array $raw): array
    {
        $inventory = [];
        foreach ($raw['inventory'] ?? [] as $item) {
            $def = $item['definition'] ?? [];
            $name = trim((string) ($def['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $inventory[] = [
                'name' => $name,
                'type' => (string) ($def['filterType'] ?? $def['type'] ?? ''),
                'quantity' => (int) ($item['quantity'] ?? 1),
                'equipped' => (bool) ($item['equipped'] ?? false),
                'attuned' => (bool) ($item['isAttuned'] ?? false),
                'magic' => (bool) ($def['magic'] ?? false),
                'rarity' => trim((string) ($def['rarity'] ?? '')),
                'weight' => (float) ($def['weight'] ?? 0),
                'description' => self::plainSnippet($def['snippet'] ?? $def['description'] ?? ''),
            ];
        }

        return $inventory;
    }

    /**
     * Full spell cards, deduplicated by name and sorted by level: metadata, save/attack, damage and the
     * rules text. Prepared if any granting source has it prepared or always-prepared.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private static function spellList(array $raw): array
    {
        $spells = [];
        $add = function (array $entry) use (&$spells): void {
            $def = $entry['definition'] ?? $entry;
            $name = trim((string) ($def['name'] ?? ''));
            if ($name === '') {
                return;
            }
            $prepared = (bool) ($entry['prepared'] ?? false) || (bool) ($entry['alwaysPrepared'] ?? false);
            if (isset($spells[$name])) {
                $spells[$name]['prepared'] = $spells[$name]['prepared'] || $prepared;

                return;
            }

            $saveShort = self::ABILITY_BY_ID[(int) ($def['saveDcAbilityId'] ?? 0)] ?? null;
            $spells[$name] = [
                'name' => $name,
                'level' => (int) ($def['level'] ?? 0),
                'school' => trim((string) ($def['school'] ?? '')),
                'casting_time' => self::castingTime($def['activation'] ?? []),
                'range' => self::range($def['range'] ?? []),
                'components' => self::components($def),
                'duration' => self::duration($def),
                'concentration' => (bool) ($def['concentration'] ?? false),
                'ritual' => (bool) ($def['ritual'] ?? false),
                'save' => ($def['requiresSavingThrow'] ?? false) && $saveShort !== null ? mb_strtoupper($saveShort) : null,
                'attack' => (bool) ($def['requiresAttackRoll'] ?? false),
                'damage' => self::spellDamage($def),
                'description' => self::html($def['description'] ?? ''),
                'prepared' => $prepared,
            ];
        };

        foreach ($raw['classSpells'] ?? [] as $classSpells) {
            foreach ($classSpells['spells'] ?? [] as $spell) {
                $add($spell);
            }
        }
        foreach (['race', 'class', 'feat', 'item', 'background'] as $source) {
            foreach ($raw['spells'][$source] ?? [] as $spell) {
                $add($spell);
            }
        }

        return collect($spells)->sortBy(['level', 'name'])->values()->all();
    }

    /** The primary damage of a spell as "1d6 psychic", from its damage modifiers. @param array<string, mixed> $def */
    private static function spellDamage(array $def): string
    {
        foreach ($def['modifiers'] ?? [] as $modifier) {
            if (($modifier['type'] ?? '') === 'damage') {
                $dice = trim((string) ($modifier['die']['diceString'] ?? ''));
                $type = mb_strtolower((string) ($modifier['friendlySubtypeName'] ?? $modifier['subType'] ?? ''));
                if ($dice !== '') {
                    return trim("{$dice} {$type}");
                }
            }
        }

        return '';
    }

    /**
     * Spell slots per level with used/max, from the character's recorded usage and its caster class's
     * slot progression. Only levels the character actually has are returned.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array{level: int, used: int, max: int}>
     */
    private static function spellSlots(array $raw): array
    {
        $used = [];
        foreach ($raw['spellSlots'] ?? [] as $slot) {
            $used[(int) ($slot['level'] ?? 0)] = (int) ($slot['used'] ?? 0);
        }

        $slots = [];
        foreach ($raw['classes'] ?? [] as $class) {
            $table = $class['definition']['spellRules']['levelSpellSlots'] ?? [];
            $classLevel = (int) ($class['level'] ?? 0);
            $row = $table[$classLevel] ?? null;
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $index => $max) {
                $level = $index + 1;
                if ((int) $max > 0) {
                    $slots[$level] = ['level' => $level, 'used' => $used[$level] ?? 0, 'max' => (int) $max];
                }
            }
        }

        ksort($slots);

        return array_values($slots);
    }

    /**
     * Warlock pact-magic slots (all one level), or null for non-warlocks.
     *
     * @param  array<string, mixed>  $raw
     * @return array{level: int, max: int, used: int}|null
     */
    private static function pactSlots(array $raw): ?array
    {
        foreach ($raw['pactMagic'] ?? [] as $slot) {
            if ((int) ($slot['available'] ?? 0) > 0) {
                return [
                    'level' => (int) ($slot['level'] ?? 1),
                    'max' => (int) $slot['available'],
                    'used' => (int) ($slot['used'] ?? 0),
                ];
            }
        }

        return null;
    }

    /**
     * Hit dice pooled by die size across classes: total available and how many are spent.
     *
     * @param  array<string, mixed>  $raw
     * @return list<array{die: int, total: int, used: int}>
     */
    private static function hitDice(array $raw): array
    {
        $pools = [];
        foreach ($raw['classes'] ?? [] as $class) {
            $die = (int) ($class['definition']['hitDice'] ?? 0);
            $level = (int) ($class['level'] ?? 0);
            if ($die < 1 || $level < 1) {
                continue;
            }
            $pools[$die]['die'] = $die;
            $pools[$die]['total'] = ($pools[$die]['total'] ?? 0) + $level;
            $pools[$die]['used'] = ($pools[$die]['used'] ?? 0) + (int) ($class['hitDiceUsed'] ?? 0);
        }
        ksort($pools);

        return array_values($pools);
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{cp: int, sp: int, ep: int, gp: int, pp: int}
     */
    private static function currency(array $raw): array
    {
        $currencies = is_array($raw['currencies'] ?? null) ? $raw['currencies'] : [];

        return [
            'cp' => (int) ($currencies['cp'] ?? 0),
            'sp' => (int) ($currencies['sp'] ?? 0),
            'ep' => (int) ($currencies['ep'] ?? 0),
            'gp' => (int) ($currencies['gp'] ?? 0),
            'pp' => (int) ($currencies['pp'] ?? 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{background: string, appearance: string, personality: string, ideals: string, bonds: string, flaws: string, backstory: string}
     */
    private static function description(array $raw): array
    {
        $traits = is_array($raw['traits'] ?? null) ? $raw['traits'] : [];
        $notes = is_array($raw['notes'] ?? null) ? $raw['notes'] : [];
        $background = trim((string) ($raw['background']['definition']['name'] ?? $raw['background']['customBackground']['name'] ?? ''));

        return [
            'background' => $background,
            'appearance' => self::html($traits['appearance'] ?? ''),
            'personality' => self::html($traits['personalityTraits'] ?? ''),
            'ideals' => self::html($traits['ideals'] ?? ''),
            'bonds' => self::html($traits['bonds'] ?? ''),
            'flaws' => self::html($traits['flaws'] ?? ''),
            'backstory' => self::html($notes['backstory'] ?? ''),
        ];
    }

    /** " + 3" / " - 1" / "" for a damage/attack bonus. */
    private static function signedSuffix(int $value): string
    {
        return match (true) {
            $value > 0 => " + {$value}",
            $value < 0 => ' - '.abs($value),
            default => '',
        };
    }

    private static function slug(string $value): string
    {
        return trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($value)) ?? '', '-');
    }

    /**
     * @param  list<array{name: string, desc: string, source: string}>  $features
     * @param  array<string, mixed>  $definition
     */
    private static function pushFeature(array &$features, array $definition, string $source = ''): void
    {
        $name = trim((string) ($definition['name'] ?? ''));
        if ($name === '') {
            return;
        }
        $features[] = [
            'name' => $name,
            'desc' => self::html($definition['description'] ?? $definition['snippet'] ?? ''),
            'source' => $source,
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private static function flatModifiers(array $raw): array
    {
        $out = [];
        foreach ($raw['modifiers'] ?? [] as $group) {
            foreach (is_array($group) ? $group : [] as $modifier) {
                $out[] = $modifier;
            }
        }

        return $out;
    }

    /** Strip HTML to a short plain-text snippet. */
    private static function plainSnippet(mixed $html): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) $html)));

        return mb_strlen($text) > 240 ? mb_substr($text, 0, 240).'…' : $text;
    }

    /** "sleight-of-hand" → "Sleight Of Hand". */
    private static function titleCase(string $value): string
    {
        return $value === '' ? '' : ucwords(str_replace('-', ' ', $value));
    }

    /**
     * A one-line "Level 5 Half-Elf · Ranger 5 (Gloom Stalker) · Outlander" summary of a DDB character.
     *
     * @param  array<string, mixed>  $raw
     */
    private static function characterSummary(array $raw): string
    {
        $race = trim((string) ($raw['race']['fullName'] ?? $raw['race']['baseName'] ?? ''));

        $classParts = [];
        $totalLevel = 0;
        foreach ($raw['classes'] ?? [] as $class) {
            $level = (int) ($class['level'] ?? 0);
            $totalLevel += $level;
            $name = trim((string) ($class['definition']['name'] ?? ''));
            $subclass = trim((string) ($class['subclassDefinition']['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $classParts[] = trim("{$name} {$level}").($subclass !== '' ? " ({$subclass})" : '');
        }

        $background = trim((string) ($raw['background']['definition']['name'] ?? $raw['background']['customBackground']['name'] ?? ''));

        $lead = trim(($totalLevel > 0 ? "Level {$totalLevel}" : '')." {$race}");

        return collect([$lead, implode(' / ', $classParts), $background])
            ->filter()
            ->implode(' · ');
    }

    /**
     * @param  array<string, string>  $fields
     * @param  array{url: string, key: string}  $image
     * @return array{item_type: string, name: string, summary: string, fields: array<string, string>, document: string, image: array{url: string, key: string}}
     */
    private static function structured(string $type, string $name, string $summary, array $fields, array $image = ['url' => '', 'key' => '']): array
    {
        $name = $name !== '' ? $name : 'Unnamed';

        return [
            'item_type' => $type,
            'name' => $name,
            'summary' => $summary,
            'fields' => $fields,
            'document' => CompendiumFields::toMarkdown($type, $fields, $name),
            'image' => $image,
        ];
    }

    /**
     * Pick the best avatar URL from a DDB record and pair it with a stable dedup key. The key lets a
     * shared image be stored once and reused by every world that imports the same entity.
     *
     * @param  array<string, mixed>  $source
     * @return array{url: string, key: string}
     */
    private static function image(array $source, string $prefix, mixed $id): array
    {
        $url = '';
        foreach (['largeAvatarUrl', 'avatarUrl', 'basicAvatarUrl'] as $candidate) {
            $value = trim((string) ($source[$candidate] ?? ''));
            if ($value !== '') {
                $url = $value;
                break;
            }
        }

        if ($url === '') {
            return ['url' => '', 'key' => ''];
        }

        $key = $id !== null && $id !== ''
            ? "ddb-{$prefix}-{$id}"
            : "ddb-{$prefix}-".sha1($url);

        return ['url' => $url, 'key' => $key];
    }

    /** DDB costs are gold-piece amounts; render whole numbers cleanly. */
    private static function cost(mixed $cost): string
    {
        if ($cost === null || $cost === '' || (float) $cost <= 0) {
            return '';
        }

        return self::number((float) $cost).' gp';
    }

    private static function number(float $value): string
    {
        return $value === floor($value) ? (string) (int) $value : (string) $value;
    }

    private static function ordinal(int $level): string
    {
        return match ($level) {
            1 => '1st',
            2 => '2nd',
            3 => '3rd',
            default => "{$level}th",
        };
    }

    /** @param array<string, mixed> $activation */
    private static function castingTime(array $activation): string
    {
        $unit = self::ACTIVATION_UNITS[(int) ($activation['activationType'] ?? 0)] ?? '';
        if ($unit === '') {
            return '';
        }

        $time = (int) ($activation['activationTime'] ?? 1) ?: 1;
        if ($unit === 'action' || $unit === 'bonus action' || $unit === 'reaction' || $unit === 'special') {
            return "1 {$unit}";
        }

        return $time === 1 ? "1 {$unit}" : "{$time} {$unit}s";
    }

    /** @param array<string, mixed> $range */
    private static function range(array $range): string
    {
        $origin = trim((string) ($range['origin'] ?? ''));
        $value = (int) ($range['rangeValue'] ?? 0);

        return match (true) {
            $origin === 'Self' => 'Self',
            $origin === 'Touch' => 'Touch',
            $origin === 'Sight' => 'Sight',
            $origin === 'Unlimited' => 'Unlimited',
            $value > 0 => "{$value} feet",
            $origin !== '' => $origin,
            default => '',
        };
    }

    /** @param array<string, mixed> $def */
    private static function components(array $def): string
    {
        $map = [1 => 'V', 2 => 'S', 3 => 'M'];
        $parts = [];
        foreach ($def['components'] ?? [] as $component) {
            if (isset($map[(int) $component])) {
                $parts[] = $map[(int) $component];
            }
        }
        $components = implode(', ', $parts);

        $material = trim((string) ($def['componentsDescription'] ?? ''));
        if ($material !== '' && str_contains($components, 'M')) {
            $components .= " ({$material})";
        }

        return $components;
    }

    /** @param array<string, mixed> $def */
    private static function duration(array $def): string
    {
        $duration = is_array($def['duration'] ?? null) ? $def['duration'] : [];
        $type = trim((string) ($duration['durationType'] ?? ''));
        $interval = (int) ($duration['durationInterval'] ?? 0);
        $unit = mb_strtolower(trim((string) ($duration['durationUnit'] ?? '')));

        $span = $interval > 0 && $unit !== ''
            ? $interval.' '.($interval === 1 ? $unit : "{$unit}s")
            : '';

        if ((bool) ($def['concentration'] ?? false) === true) {
            return trim('Concentration, up to '.($span !== '' ? $span : 'the duration'));
        }

        return match (true) {
            $type === 'Instantaneous' => 'Instantaneous',
            $type === 'Special' => 'Special',
            $span !== '' => $span,
            $type !== '' => $type,
            default => '',
        };
    }

    /** Flatten a DDB HTML description into plain text, preserving paragraph breaks. */
    private static function html(?string $html): string
    {
        $text = trim((string) $html);
        if ($text === '') {
            return '';
        }

        $text = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n\n", $text) ?? $text;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }

    /**
     * Parse a DDB HTML description block ("<p><strong>Name.</strong> desc</p>" per entry) into
     * name/desc pairs. Parsing (not sanitisation) — the values are escaped when rendered.
     *
     * @return list<array{name: string, desc: string}>
     */
    protected static function entries(?string $html): array
    {
        $html = trim((string) $html);
        if ($html === '') {
            return [];
        }

        preg_match_all('/<p[^>]*>(.*?)<\/p>/is', $html, $matches);
        $blocks = $matches[1] !== [] ? $matches[1] : [$html];

        $out = [];
        foreach ($blocks as $block) {
            if (preg_match('/<strong>(.*?)<\/strong>(.*)/is', $block, $parts)) {
                $name = trim(html_entity_decode(strip_tags($parts[1]), ENT_QUOTES | ENT_HTML5), " .\u{00A0}");
                $desc = trim(html_entity_decode(strip_tags($parts[2]), ENT_QUOTES | ENT_HTML5));
            } else {
                $name = '';
                $desc = trim(html_entity_decode(strip_tags($block), ENT_QUOTES | ENT_HTML5));
            }
            if ($name !== '' || $desc !== '') {
                $out[] = ['name' => $name, 'desc' => $desc];
            }
        }

        return $out;
    }
}
