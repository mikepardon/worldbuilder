<?php

namespace App\Support;

/** Helpers for the inline reference tokens used inside document content. */
class EntryRefs
{
    /** Compendium-monster embed: {{monster=<item id>}} (optionally with ,frame,wide classes). */
    public const STATBLOCK_RE = '/\{\{monster=(\d+)(?:,[^}]*)?\}\}/';

    /**
     * Any compendium embed token: {{monster=id}}, {{spell=id}}, {{armor=id,frame,wide}}, etc.
     * The keyword is cosmetic — the numeric id resolves the entry regardless of the prefix used.
     */
    public const EMBED_RE = '/\{\{[a-z]+=(\d+)(?:,[^}]*)?\}\}/';

    /** The compendium item ids referenced by [statblock=…] tokens in the content. */
    public static function statblockIds(?string $content): array
    {
        return self::idsFor($content, self::STATBLOCK_RE);
    }

    /** The compendium item ids referenced by any embed token in the content. */
    public static function embedIds(?string $content): array
    {
        return self::idsFor($content, self::EMBED_RE);
    }

    protected static function idsFor(?string $content, string $pattern): array
    {
        if (! $content || ! preg_match_all($pattern, $content, $m)) {
            return [];
        }

        return array_values(array_unique(array_map('intval', $m[1])));
    }
}
