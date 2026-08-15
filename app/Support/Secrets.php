<?php

namespace App\Support;

/** GM-only {{secret}}…{{/}} blocks: split for rendering, count, strip, and reveal-by-index. */
class Secrets
{
    private const RE = '/\{\{secret\}\}(.*?)\{\{\/\}\}/s';

    /** Split content into ordered segments: ['secret' => bool, 'text' => string]. */
    public static function split(?string $content): array
    {
        $content ??= '';
        $segments = [];
        $last = 0;

        if (preg_match_all(self::RE, $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $i => $match) {
                $start = $match[1];
                if ($start > $last) {
                    $segments[] = ['secret' => false, 'text' => substr($content, $last, $start - $last)];
                }
                $segments[] = ['secret' => true, 'text' => $matches[1][$i][0]];
                $last = $start + strlen($match[0]);
            }
        }
        if ($last < strlen($content)) {
            $segments[] = ['secret' => false, 'text' => substr($content, $last)];
        }

        return $segments;
    }

    public static function count(?string $content): int
    {
        return preg_match_all(self::RE, $content ?? '');
    }

    /**
     * Remove every GM-only region before content reaches a player. Depth-aware so it fails safe on the
     * adversarial cases a regex misses: an unclosed {{secret}} hides through to the end, nested blocks
     * are removed whole, and stray tokens never survive. Only ever cuts at the ASCII token boundaries,
     * so multibyte text outside secrets is preserved byte-for-byte.
     */
    public static function strip(?string $content): string
    {
        $content ??= '';
        $open = '{{secret}}';
        $close = '{{/}}';
        $out = '';
        $depth = 0;
        $i = 0;
        $length = strlen($content);

        while ($i < $length) {
            $nextOpen = strpos($content, $open, $i);
            $nextClose = strpos($content, $close, $i);
            $positions = array_filter([$nextOpen, $nextClose], fn ($position) => $position !== false);

            if ($positions === []) {
                if ($depth === 0) {
                    $out .= substr($content, $i);
                }
                break;
            }

            $position = min($positions);
            if ($depth === 0) {
                $out .= substr($content, $i, $position - $i);
            }

            if ($position === $nextOpen) {
                $depth++;
                $i = $position + strlen($open);

                continue;
            }

            // A close token: drop it, and step out one level if we were inside a secret.
            if ($depth > 0) {
                $depth--;
            }
            $i = $position + strlen($close);
        }

        return $out;
    }

    /** Unwrap the Nth (0-based) secret block, publishing it to players. */
    public static function reveal(?string $content, int $index): string
    {
        $n = 0;

        return preg_replace_callback(self::RE, function ($m) use (&$n, $index) {
            return $n++ === $index ? $m[1] : $m[0];
        }, $content ?? '') ?? '';
    }
}
