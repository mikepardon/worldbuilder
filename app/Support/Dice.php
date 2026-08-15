<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Server-authoritative dice roller for room chat. Parses expressions like "1d20+5", "2d6", "d20-1",
 * "3d8+2d6+1" and rolls each die with a cryptographically-strong source. Supports advantage /
 * disadvantage via keep-highest / keep-lowest ("2d20kh1" / "2d20kl1"), reporting the dropped dice.
 * Returns null for anything that isn't a valid dice expression (caller treats it as a plain message).
 */
class Dice
{
    /**
     * @return array{expr: string, total: int, detail: string, dropped: list<int>, mode: string|null, d20: int|null}|null
     */
    public static function roll(string $input): ?array
    {
        $expression = preg_replace('/\s+/', '', mb_strtolower(trim($input))) ?? '';

        // A sum of dice/number terms, with at least one die (NdM, optionally with kh/kl).
        $termPattern = '(\d*d\d+(k[hl]\d+)?|\d+)';
        if ($expression === '' || ! preg_match('/^[+-]?'.$termPattern.'([+-]'.$termPattern.')*$/', $expression)) {
            return null;
        }
        if (! str_contains($expression, 'd')) {
            return null;
        }

        preg_match_all('/[+-]?(?:\d*d\d+(?:k[hl]\d+)?|\d+)/', $expression, $matches);

        $total = 0;
        $parts = [];
        $dropped = [];
        $mode = null;
        $d20 = null; // the natural value of a single kept d20 (for nat-1 / nat-20 highlighting)
        foreach ($matches[0] as $term) {
            $sign = str_starts_with($term, '-') ? -1 : 1;
            $body = ltrim($term, '+-');

            if (str_contains($body, 'd')) {
                if (preg_match('/^(\d*)d(\d+)(?:k([hl])(\d+))?$/', $body, $die) !== 1) {
                    return null;
                }
                $count = $die[1] === '' ? 1 : (int) $die[1];
                $sides = (int) $die[2];
                if ($count < 1 || $count > 100 || $sides < 1 || $sides > 1000) {
                    return null;
                }

                $rolls = [];
                for ($i = 0; $i < $count; $i++) {
                    $rolls[] = random_int(1, $sides);
                }

                $kept = $rolls;
                if (($die[3] ?? '') !== '') {
                    $keep = max(1, min($count, (int) $die[4]));
                    $sorted = $rolls;
                    rsort($sorted); // highest first
                    $kept = $die[3] === 'h' ? array_slice($sorted, 0, $keep) : array_slice($sorted, -$keep);
                    $dropped = [...$dropped, ...self::difference($rolls, $kept)];
                    $mode ??= $die[3] === 'h' ? 'advantage' : 'disadvantage';
                }

                if ($sides === 20 && count($kept) === 1) {
                    $d20 = $kept[0];
                }

                $total += $sign * array_sum($kept);
                $parts[] = ($sign < 0 ? '−' : '').$body.' ['.implode(', ', $rolls).']';
            } else {
                $total += $sign * (int) $body;
                $parts[] = ($sign < 0 ? '− ' : '+ ').$body;
            }
        }

        // A d20 Test (check/save/attack) can't total below 1, even after penalties like exhaustion.
        if (str_contains($expression, 'd20')) {
            $total = max(1, $total);
        }

        return [
            'expr' => trim($input),
            'total' => $total,
            'detail' => trim(implode(' ', $parts), ' +'),
            'dropped' => $dropped,
            'mode' => $mode,
            'd20' => $d20,
        ];
    }

    /**
     * The values in $all that aren't in $kept (multiset difference — a duplicate value is only removed once).
     *
     * @param  list<int>  $all
     * @param  list<int>  $kept
     * @return list<int>
     */
    private static function difference(array $all, array $kept): array
    {
        $out = $all;
        foreach ($kept as $value) {
            $index = array_search($value, $out, true);
            if ($index !== false) {
                unset($out[$index]);
            }
        }

        return array_values($out);
    }
}
