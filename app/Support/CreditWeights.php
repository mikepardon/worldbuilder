<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The credit cost of generating each content type — heavier work (a session recap) costs many more
 * credits than a quick one (an NPC). Keyed by the labels {@see AiUsageType} produces. These weights are
 * live: they drive both the admin credit-weighting review panel and what a metered AI action actually
 * spends against a user's balance.
 *
 * Pricing basis: a credit sells at {@see self::CREDIT_CENTS} US cents ($0.10), so a 1-credit action costs
 * the user 10c and a 30-credit recap costs $3.00. Anthropic's token cost is a fraction of that, leaving a
 * healthy blended margin reviewed in the admin panel.
 */
final class CreditWeights
{
    /** Sale price of a single credit, in US cents (what a credit is charged at). */
    public const CREDIT_CENTS = 10;

    /** Credit values a credit can be reviewed at, in US cents. */
    public const CREDIT_CENT_PRESETS = [5, 10, 15, 20];

    /** Recaps are billed by audio length (GM-Assistant style); this is the credits charged per hour. */
    public const RECAP_CREDITS_PER_HOUR = 12;

    /** Per-hour recap rates the pricing can be reviewed at. */
    public const RECAP_RATE_PRESETS = [8, 12, 16];

    /** Credits granted once to a newly registered account, to get them started before they pay. */
    public const SIGNUP_BONUS_CREDITS = 200;

    private const DEFAULT_WEIGHT = 1;

    /** Credits charged per content type. Anything unlisted falls back to DEFAULT_WEIGHT. */
    private const WEIGHTS = [
        'Recap' => 30,
        'Monster' => 2,
        'Magic item' => 2,
        'Item' => 2,
        'Statblock' => 2,
        'Equipment' => 2,
        'Spell' => 1,
        'NPC' => 1,
        'Location' => 1,
        'Faction' => 1,
        'Lore' => 1,
        'Session' => 1,
        'Quest' => 1,
        'Assistant chat' => 1,
    ];

    public static function for(string $type): int
    {
        return self::WEIGHTS[$type] ?? self::DEFAULT_WEIGHT;
    }

    /** The credit cost of a metered action, resolved from its (feature, detail) as {@see AiUsageType} labels it. */
    public static function forFeature(string $feature, ?string $detail = null): int
    {
        return self::for(AiUsageType::label($feature, $detail));
    }

    /** Credits a recap of $seconds of audio costs at $perHour credits per hour (rounded up). */
    public static function recapCredits(int $seconds, int $perHour): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        return (int) ceil($seconds / 3600 * $perHour);
    }

    /** The live credit cost of a recap of $seconds, at the configured per-hour rate. */
    public static function recapCreditCost(int $seconds): int
    {
        return self::recapCredits($seconds, self::RECAP_CREDITS_PER_HOUR);
    }

    /** @return array<string, int> */
    public static function all(): array
    {
        return self::WEIGHTS;
    }

    /**
     * The credit costs the front-end needs to show "this costs N credits" hints before an AI action runs.
     * `kinds` is keyed by the raw entry/generator kind (npc, monster, …); `actions` by the fixed-cost
     * feature actions. Recaps are quoted per hour of audio since the exact cost depends on the recording.
     *
     * @return array{kinds: array<string, int>, actions: array<string, int>}
     */
    public static function uiCostMap(): array
    {
        $kinds = [];
        foreach (AiUsageType::kindKeys() as $kind) {
            $kinds[$kind] = self::forFeature('assistant_draft', $kind);
        }

        return [
            'kinds' => $kinds,
            'actions' => [
                'assistant_chat' => self::forFeature('assistant_ask'),
                'session_writeup' => self::forFeature('session_writeup'),
                'roll_table' => self::forFeature('roll_table'),
                'bloodline' => self::forFeature('assistant_bloodline'),
                'recap_per_hour' => self::RECAP_CREDITS_PER_HOUR,
            ],
        ];
    }
}
