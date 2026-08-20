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
        if ($start === false) {
            return null;
        }

        $body = mb_substr($raw, $start);
        $end = mb_strrpos($body, '}');
        if ($end !== false) {
            $decoded = json_decode(mb_substr($body, 0, $end + 1), true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Nothing decoded: the reply may have been cut off mid-object because the model ran into its output
        // cap (a long recap does this). Salvage the part that arrived intact rather than losing it all.
        $repaired = self::repairTruncated($body);
        if ($repaired === null) {
            return null;
        }

        $decoded = json_decode($repaired, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Rebuild an object that stops mid-way: trim back to the last element that finished cleanly and close
     * whatever is still open. Returns null when nothing complete arrived before the cut.
     *
     * Works on bytes — every character it looks at is ASCII, and UTF-8 continuation bytes can never collide
     * with one, so multi-byte text inside the strings passes through untouched.
     */
    private static function repairTruncated(string $json): ?string
    {
        $stack = [];
        $inString = false;
        $escaped = false;
        $cut = null;
        $open = null;

        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $stack[] = $char === '{' ? '}' : ']';
            } elseif ($char === '}' || $char === ']') {
                array_pop($stack);
                // A closed container is a finished value, so everything up to here can stand on its own.
                $cut = $i + 1;
                $open = $stack;
            } elseif ($char === ',') {
                // A structural comma always follows a finished value; dropping it leaves the container
                // holding exactly the elements that arrived whole.
                $cut = $i;
                $open = $stack;
            }
        }

        if ($cut === null || $open === null) {
            return null;
        }

        return substr($json, 0, $cut).implode('', array_reverse($open));
    }
}
