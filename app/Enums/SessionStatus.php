<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\Arc;
use App\Models\Session;

/**
 * Where a session or narrative arc sits in play: already run, on the table now, or still ahead.
 * Shared by {@see Session} and {@see Arc} so both read the same way.
 */
enum SessionStatus: string
{
    case Played = 'played';
    case Playing = 'playing';
    case ToPlay = 'to_play';

    public function label(): string
    {
        return match ($this) {
            self::Played => 'Played',
            self::Playing => 'Playing',
            self::ToPlay => 'To play',
        };
    }

    /** The semantic colour a pill uses for this status: muted for done, live for now, ahead for later. */
    public function colour(): string
    {
        return match ($this) {
            self::Played => 'grey',
            self::Playing => 'orange',
            self::ToPlay => 'green',
        };
    }
}
