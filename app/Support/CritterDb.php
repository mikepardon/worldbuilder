<?php

declare(strict_types=1);

namespace App\Support;

use Brick\Math\BigInteger;

/**
 * Maps CritterDB homebrew creatures into our structured stat block (see {@see Statblock}). A CritterDB
 * creature carries its numbers under `stats` (ability scores, hit dice, CR…) and its prose under
 * `flavor`. Descriptions are HTML, so they are stripped to plain text before rendering to markdown.
 *
 * Two shapes reach us: a pasted JSON export (a bare creature, a list, or a `{ creatures: [...] }`
 * envelope) and the published-bestiary API (a list of creatures). {@see creaturesFromExport}
 * normalises all of them to a flat list.
 */
class CritterDb
{
    /** CritterDB ability-score key => our stat-block key. */
    private const ABILITIES = [
        'strength' => 'str', 'dexterity' => 'dex', 'constitution' => 'con',
        'intelligence' => 'int', 'wisdom' => 'wis', 'charisma' => 'cha',
    ];

    /**
     * Pull a published-bestiary id out of a CritterDB reference. Accepts a full share link
     * (e.g. https://critterdb.com/#/publishedbestiary/view/6512686e94b584b853ef1586) or a bare
     * 24-character hex ObjectId. Returns null when no id can be found.
     */
    public static function bestiaryIdFromReference(string $reference): ?string
    {
        if (preg_match('/[a-f0-9]{24}/i', $reference, $matches) === 1) {
            return mb_strtolower($matches[0]);
        }

        return null;
    }

    /**
     * Normalise a decoded CritterDB export into a flat list of creature records.
     *
     * @return list<array<string, mixed>>
     */
    public static function creaturesFromExport(mixed $decoded): array
    {
        $candidates = match (true) {
            is_array($decoded) && isset($decoded['creatures']) && is_array($decoded['creatures']) => $decoded['creatures'],
            is_array($decoded) && isset($decoded['stats']) => [$decoded], // a single creature
            is_array($decoded) => array_values($decoded), // already a list
            default => [],
        };

        return collect($candidates)
            ->filter(fn ($creature) => is_array($creature) && filled($creature['name'] ?? null))
            ->values()
            ->all();
    }

    /**
     * Map one CritterDB creature into the fields we persist for a compendium monster.
     *
     * @param  array<string, mixed>  $creature
     * @return array{name: string, summary: string, block: array<string, mixed>, document: string}
     */
    public static function toItem(array $creature): array
    {
        $name = trim((string) ($creature['name'] ?? 'Unnamed Creature')) ?: 'Unnamed Creature';
        $block = self::toBlock($creature);

        return [
            'name' => $name,
            'summary' => self::summary($block),
            'block' => $block,
            'document' => Statblock::toMarkdown($block, $name),
        ];
    }

    /**
     * @param  array<string, mixed>  $creature
     * @return array<string, mixed>
     */
    private static function toBlock(array $creature): array
    {
        $stats = is_array($creature['stats'] ?? null) ? $creature['stats'] : [];
        $scores = is_array($stats['abilityScores'] ?? null) ? $stats['abilityScores'] : [];

        $abilities = [];
        foreach (self::ABILITIES as $source => $target) {
            $abilities[$target] = (int) ($scores[$source] ?? 10);
        }

        $armorClass = self::text($stats['armorClass'] ?? '');
        $armorType = self::text($stats['armorType'] ?? '');

        return array_merge(Statblock::empty(), [
            'size' => self::text($stats['size'] ?? '') ?: 'Medium',
            'type' => self::text($stats['race'] ?? '') ?: 'humanoid',
            'alignment' => self::text($stats['alignment'] ?? '') ?: 'unaligned',
            'ac' => $armorClass === '' ? '10' : ($armorType === '' ? $armorClass : "{$armorClass} ({$armorType})"),
            'hp' => self::hitPoints($stats, $abilities['con']),
            'speed' => self::speed($stats['speed'] ?? ''),
            'abilities' => $abilities,
            'saves' => self::proficiencyList($stats['savingThrows'] ?? null),
            'skills' => self::proficiencyList($stats['skills'] ?? null),
            'vulnerabilities' => self::text($stats['damageVulnerabilities'] ?? ''),
            'resistances' => self::text($stats['damageResistances'] ?? ''),
            'immunities' => self::text($stats['damageImmunities'] ?? ''),
            'conditionImmunities' => self::text($stats['conditionImmunities'] ?? ''),
            'senses' => self::text($stats['senses'] ?? ''),
            'languages' => self::text($stats['languages'] ?? ''),
            'cr' => self::challengeRating($stats['challengeRating'] ?? ''),
            'traits' => self::entries($stats['additionalAbilities'] ?? null),
            'actions' => self::entries($stats['actions'] ?? null),
            'reactions' => self::entries($stats['reactions'] ?? null),
            'legendary' => self::entries($stats['legendaryActions'] ?? null),
        ]);
    }

    /**
     * Hit points from CritterDB's dice (`numHitDie` d `hitDieSize`) plus the Constitution bonus per die,
     * rendered like "310 (23d20 + 69)". Falls back to a precomputed string/number when no dice are given.
     *
     * @param  array<string, mixed>  $stats
     */
    private static function hitPoints(array $stats, int $constitution): string
    {
        $numberOfDice = (int) ($stats['numHitDie'] ?? 0);
        $dieSize = (int) ($stats['hitDieSize'] ?? 0);

        if ($numberOfDice < 1 || $dieSize < 1) {
            return self::text($stats['hitPointsStr'] ?? $stats['hitPoints'] ?? '') ?: '1';
        }

        $dice = BigInteger::of($numberOfDice);
        $constitutionBonus = $dice->multipliedBy(Statblock::abilityMod($constitution));
        $average = $dice
            ->multipliedBy(BigInteger::of($dieSize)->plus(1))
            ->quotient(2) // integer floor division; average HP is always positive
            ->plus($constitutionBonus);

        $total = $constitutionBonus->isZero() ? '' : ' '.($constitutionBonus->isNegative() ? '-' : '+').' '.$constitutionBonus->abs();

        return "{$average} ({$numberOfDice}d{$dieSize}{$total})";
    }

    private static function speed(mixed $speed): string
    {
        if (is_numeric($speed)) {
            return "{$speed} ft.";
        }

        return self::text($speed) ?: '30 ft.';
    }

    /** CritterDB stores CR as a number; render the fractional ones as fractions. */
    private static function challengeRating(mixed $challengeRating): string
    {
        if (is_string($challengeRating) && $challengeRating !== '') {
            return $challengeRating;
        }

        if (! is_numeric($challengeRating)) {
            return '1';
        }

        $value = (float) $challengeRating;

        return match (true) {
            abs($value - 0.125) < 0.001 => '1/8',
            abs($value - 0.25) < 0.001 => '1/4',
            abs($value - 0.5) < 0.001 => '1/2',
            $value === floor($value) => (string) (int) $value,
            default => (string) $value,
        };
    }

    /**
     * CritterDB saving-throw/skill lists arrive as either bare strings or `{name|proficiency, value}`
     * objects. Render each as a readable, optionally signed label.
     */
    private static function proficiencyList(mixed $list): string
    {
        if (! is_array($list)) {
            return '';
        }

        return collect($list)
            ->map(function ($entry): string {
                if (is_string($entry)) {
                    return self::clean($entry);
                }
                if (! is_array($entry)) {
                    return '';
                }

                $name = self::clean((string) ($entry['name'] ?? $entry['proficiency'] ?? ''));
                $value = $entry['value'] ?? $entry['modifier'] ?? null;

                return match (true) {
                    $name === '' => '',
                    is_numeric($value) => $name.' '.((int) $value >= 0 ? '+' : '').(int) $value,
                    default => $name,
                };
            })
            ->filter()
            ->implode(', ');
    }

    /**
     * @return list<array{name: string, desc: string}>
     */
    private static function entries(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        return collect($rows)
            ->filter(fn ($row) => is_array($row))
            ->map(fn (array $row) => [
                'name' => self::clean((string) ($row['name'] ?? '')),
                'desc' => self::clean((string) ($row['description'] ?? $row['desc'] ?? '')),
            ])
            ->filter(fn (array $row) => $row['name'] !== '' || $row['desc'] !== '')
            ->values()
            ->all();
    }

    /** @param array<string, mixed> $block */
    private static function summary(array $block): string
    {
        $line = trim("{$block['size']} {$block['type']}");

        return trim($line.' · CR '.$block['cr'], " ·\t\n");
    }

    /** Join CritterDB's string/array values into a single comma-separated, tag-free string. */
    private static function text(mixed $value): string
    {
        if (is_array($value)) {
            return collect($value)->map(fn ($item) => self::text($item))->filter()->implode(', ');
        }

        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        return self::clean((string) $value);
    }

    /** Strip HTML from CritterDB prose and collapse runs of whitespace. */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[ \t]+/', ' ', strip_tags($value)));
    }
}
