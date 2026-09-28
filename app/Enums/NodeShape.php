<?php

declare(strict_types=1);

namespace App\Enums;

use App\Models\RuleNodeKind;

/**
 * The rendered shape of a talent node. Drives the builder palette and the web canvas; a node's kind
 * ({@see RuleNodeKind}) carries the shape so a GM's custom kinds look distinct.
 */
enum NodeShape: string
{
    case Circle = 'circle';
    case Square = 'square';
    case Diamond = 'diamond';
    case Hexagon = 'hexagon';
    case Star = 'star';

    public function label(): string
    {
        return match ($this) {
            self::Circle => 'Circle',
            self::Square => 'Square',
            self::Diamond => 'Diamond',
            self::Hexagon => 'Hexagon',
            self::Star => 'Star',
        };
    }

    /** The CSS border-radius the canvas applies for this shape (squares/diamonds get a slight round). */
    public function borderRadius(): string
    {
        return match ($this) {
            self::Circle, self::Star => '50%',
            self::Square, self::Diamond, self::Hexagon => '4px',
        };
    }

    /** Degrees the node is rotated so diamonds/hexes read correctly (glyphs are counter-rotated). */
    public function spin(): int
    {
        return $this === self::Diamond ? 45 : 0;
    }
}
