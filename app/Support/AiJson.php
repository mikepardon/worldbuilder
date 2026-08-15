<?php

namespace App\Support;

/** Helpers for reading structured JSON out of a language model's free-text reply. */
class AiJson
{
    /**
     * Pull the first {...} block out of a model reply and decode it to an array. Returns null when
     * there is no balanced object or it does not decode (e.g. the model answered in prose instead).
     *
     * @return array<string, mixed>|null
     */
    public static function object(string $raw): ?array
    {
        $start = mb_strpos($raw, '{');
        $end = mb_strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        $decoded = json_decode(mb_substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
