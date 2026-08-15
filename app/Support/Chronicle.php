<?php

namespace App\Support;

/**
 * Builds a chronological event list from timeline documents — a PHP port of the original TS logic.
 * Each doc's `| When | Event | Detail |` table becomes events; a doc without a table contributes a
 * single event from its own title/summary.
 */
class Chronicle
{
    /** Parse a leading year; BC/BCE → negative; undated → PHP_INT_MAX so it sorts last. */
    public static function parseYear(?string $value): int
    {
        $text = trim($value ?? '');
        if ($text === '' || ! preg_match('/-?\d+/', $text, $m)) {
            return PHP_INT_MAX;
        }
        $year = (int) $m[0];
        if ($year > 0 && preg_match('/\bb\.?c\.?(e\.?)?\b/i', $text)) {
            $year = -$year;
        }

        return $year;
    }

    /** Rows from the first markdown table in the content, skipping the header + separator rows. */
    public static function parseTimelineTable(?string $content): array
    {
        $rows = [];
        $inTable = false;
        foreach (explode("\n", $content ?? '') as $line) {
            $trimmed = trim($line);
            $isRow = strlen($trimmed) > 1 && str_starts_with($trimmed, '|') && str_ends_with($trimmed, '|');
            if (! $isRow) {
                if ($inTable) {
                    break;
                }

                continue;
            }
            $cells = array_map('trim', explode('|', substr($trimmed, 1, -1)));
            $isSeparator = count(array_filter($cells, fn ($c) => $c !== '' && ! preg_match('/^:?-{2,}:?$/', $c))) === 0;
            if ($isSeparator) {
                $inTable = true;

                continue;
            }
            if (! $inTable) {
                $inTable = true; // header row

                continue;
            }
            [$when, $title, $detail] = array_pad($cells, 3, '');
            if ($when === '' && $title === '' && $detail === '') {
                continue;
            }
            $rows[] = ['when' => $when, 'title' => $title, 'detail' => $detail];
        }

        return $rows;
    }

    /**
     * Build the chronicle as a list of Ages (one per timeline document), each carrying its own dated
     * events, sorted so the Ages — and the events within them — run oldest first (undated last).
     *
     * A timeline stores its events as structured data (data.events: [{when, title, detail, link}]).
     * Legacy timelines with a `| When | Event | Detail |` markdown table (or none at all) are still
     * understood, so existing worlds keep rendering.
     *
     * @param  iterable  $docs  each with id, title, slug, kind, summary, content, and data
     * @param  array<int, array{type: string, slug: string, title: string}>  $linkMap  visible entries an event may link to, keyed by id
     * @return list<array{id: int, type: string, slug: string, title: string, span: string, summary: string, sort: int, events: list<array{when: string, sort: int, title: string, detail: string, link: array{type: string, slug: string, title: string}|null}>}>
     */
    public static function build(iterable $docs, array $linkMap = []): array
    {
        $ages = [];
        foreach ($docs as $doc) {
            $span = trim((string) data_get($doc, 'data.span', ''));
            $events = self::eventsFor($doc, $linkMap);

            $ages[] = [
                'id' => data_get($doc, 'id'),
                'type' => Sections::typeSlug(data_get($doc, 'kind', 'timeline')),
                'slug' => data_get($doc, 'slug'),
                'title' => $doc->title,
                'span' => $span,
                'summary' => trim((string) ($doc->summary ?? '')),
                // An Age sits at its earliest event, or its span, or (failing both) its title.
                'sort' => $events === []
                    ? self::parseYear($span !== '' ? $span : $doc->title)
                    : min(array_map(fn (array $event): int => $event['sort'], $events)),
                'events' => $events,
            ];
        }

        usort($ages, fn ($a, $b) => [$a['sort'], $a['title']] <=> [$b['sort'], $b['title']]);

        return $ages;
    }

    /**
     * The events for one timeline: its structured events when present, else the legacy table, else the
     * document itself as a single event. Sorted oldest first, preserving authored order within a year.
     *
     * @param  array<int, array{type: string, slug: string, title: string}>  $linkMap
     * @return list<array{when: string, sort: int, title: string, detail: string, link: array{type: string, slug: string, title: string}|null}>
     */
    private static function eventsFor(mixed $doc, array $linkMap): array
    {
        $structured = data_get($doc, 'data.events');
        $rows = is_array($structured) && $structured !== []
            ? array_map(fn (mixed $event): array => [
                'when' => trim((string) data_get($event, 'when', '')),
                'title' => trim((string) data_get($event, 'title', '')),
                'detail' => trim((string) data_get($event, 'detail', '')),
                'link' => self::resolveLink(data_get($event, 'link'), $linkMap),
            ], array_values($structured))
            : self::legacyRows($doc);

        $events = [];
        $index = 0;
        foreach ($rows as $row) {
            $events[] = [
                'when' => $row['when'],
                'sort' => self::parseYear($row['when']),
                'title' => $row['title'] !== '' ? $row['title'] : '—',
                'detail' => $row['detail'],
                'link' => $row['link'] ?? null,
                'index' => $index++,
            ];
        }

        usort($events, fn ($a, $b) => [$a['sort'], $a['index']] <=> [$b['sort'], $b['index']]);

        return array_map(fn (array $event): array => array_diff_key($event, ['index' => 0]), $events);
    }

    /**
     * Legacy timelines: rows from the markdown table, or the document as one event when it has none.
     *
     * @return list<array{when: string, title: string, detail: string, link: null}>
     */
    private static function legacyRows(mixed $doc): array
    {
        $rows = self::parseTimelineTable($doc->content ?? '');

        if ($rows !== []) {
            return array_map(fn (array $row): array => [
                'when' => $row['when'], 'title' => $row['title'], 'detail' => $row['detail'], 'link' => null,
            ], $rows);
        }

        return [[
            'when' => trim((string) data_get($doc, 'data.span', '')),
            'title' => (string) $doc->title,
            'detail' => trim((string) ($doc->summary ?? '')),
            'link' => null,
        ]];
    }

    /**
     * Resolve an event's linked entry id to its reader coordinates, but only when that entry is in the
     * visible-entry map — so a link never leaks a private target.
     *
     * @param  array<int, array{type: string, slug: string, title: string}>  $linkMap
     * @return array{type: string, slug: string, title: string}|null
     */
    private static function resolveLink(mixed $id, array $linkMap): ?array
    {
        if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
            return null;
        }

        return $linkMap[(int) $id] ?? null;
    }
}
