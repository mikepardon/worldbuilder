<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\RuleLevel;

/**
 * How a rule system awards talent points as characters advance. Milestone and XP both read from the
 * system's level table ({@see RuleLevel}); manual leaves every award to the GM.
 */
enum ProgressionMode: string
{
    /** The GM bumps a character's level by hand; points come from the level table. */
    case Milestone = 'milestone';

    /** A character's level (and so its points) derives from accumulated XP against the level table. */
    case Xp = 'xp';

    /** No automatic points; the GM awards them per character (e.g. randomly or as rewards). */
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Milestone => 'Milestone',
            self::Xp => 'Experience points',
            self::Manual => 'Manual / GM-awarded',
        };
    }

    /** Whether this mode derives level from a tracked XP total rather than a set level. */
    public function tracksXp(): bool
    {
        return $this === self::Xp;
    }
}
