<?php

namespace App\Support;

use App\Models\GlobalAttribute;
use App\Models\World;
use App\Models\WorldAttribute;

/**
 * Structured fields for the "field editor" kinds. The schema now lives in the admin-managed
 * global_attributes table; DEFAULTS below is the built-in starter set (seeded into that table,
 * and used as a fallback for any kind that has no global attributes defined yet).
 */
class DocFields
{
    /** Built-in starter fields, keyed by document kind. Seeded into global_attributes. */
    public const DEFAULTS = [
        'location' => [
            ['key' => 'type', 'label' => 'Type', 'placeholder' => 'City, dungeon, plane…'],
            ['key' => 'region', 'label' => 'Region', 'placeholder' => 'The Sundered Coast'],
            ['key' => 'population', 'label' => 'Population', 'placeholder' => '12,000'],
            ['key' => 'ruler', 'label' => 'Ruled by', 'placeholder' => 'Lady Merrow'],
        ],
        'npc' => [
            ['key' => 'role', 'label' => 'Role', 'placeholder' => 'Harbourmaster'],
            ['key' => 'species', 'label' => 'Species', 'placeholder' => 'Half-elf'],
            ['key' => 'faction', 'label' => 'Faction', 'placeholder' => 'The Gilded Net'],
            ['key' => 'location', 'label' => 'Found at', 'placeholder' => 'Saltmere Docks'],
        ],
        'faction' => [
            ['key' => 'type', 'label' => 'Type', 'placeholder' => 'Guild, cult, kingdom…'],
            ['key' => 'leader', 'label' => 'Leader', 'placeholder' => 'Archon Vale'],
            ['key' => 'seat', 'label' => 'Seat of power', 'placeholder' => 'The Brass Spire'],
            ['key' => 'goal', 'label' => 'Goal', 'placeholder' => 'Control the salt trade'],
        ],
        'spell' => [
            ['key' => 'level', 'label' => 'Level', 'placeholder' => '3rd'],
            ['key' => 'school', 'label' => 'School', 'placeholder' => 'Evocation'],
            ['key' => 'casting_time', 'label' => 'Casting time', 'placeholder' => '1 action'],
            ['key' => 'range', 'label' => 'Range', 'placeholder' => '120 feet'],
            ['key' => 'components', 'label' => 'Components', 'placeholder' => 'V, S, M'],
            ['key' => 'duration', 'label' => 'Duration', 'placeholder' => 'Instantaneous'],
        ],
    ];

    /**
     * Fields for a kind: the platform defaults (admin global attributes, else the built-in set),
     * layered with a world's own attributes — a world attribute overrides a default of the same key,
     * hides it when not visible, or adds a brand-new field.
     */
    public static function for(string $kind, ?World $world = null): array
    {
        $fields = collect(self::globalFor($kind))->keyBy('key');

        if ($world !== null) {
            $worldAttributes = WorldAttribute::where('world_id', $world->id)
                ->orderBy('sort_order')->orderBy('label')->get()
                ->filter(fn (WorldAttribute $a) => in_array($kind, $a->kinds ?? [], true));

            foreach ($worldAttributes as $attribute) {
                if (! $attribute->visible) {
                    $fields->forget($attribute->key);

                    continue;
                }

                $fields->put($attribute->key, self::shape(
                    $attribute->key, $attribute->label, $attribute->placeholder, $attribute->type,
                    $attribute->options, $attribute->ref_kinds, $attribute->help, $attribute->required,
                    $attribute->multiple, $attribute->link_label, $attribute->inverse_label,
                ));
            }

            // Apply the world's saved display order for this kind. Keys the GM has ordered come first
            // in that order; anything not yet ordered keeps its position after them (stable sort).
            $order = $world->field_order[$kind] ?? null;

            if (is_array($order) && $order !== []) {
                $rank = array_flip($order);
                $fields = $fields->sortBy(fn (array $field, string $key): int => $rank[$key] ?? PHP_INT_MAX);
            }
        }

        return $fields->values()->all();
    }

    /** The platform-level fields for a kind (admin global attributes if any, else the built-in defaults). */
    private static function globalFor(string $kind): array
    {
        $fromAdmin = GlobalAttribute::query()
            ->where('visible', true)
            ->orderBy('sort_order')
            ->orderBy('label')
            ->get()
            ->filter(fn (GlobalAttribute $a) => in_array($kind, $a->kinds ?? [], true))
            ->map(fn (GlobalAttribute $a) => self::shape(
                $a->key, $a->label, $a->placeholder, $a->type, $a->options, $a->ref_kinds, $a->help, $a->required,
                false, $a->link_label, $a->inverse_label,
            ))
            ->values()
            ->all();

        return $fromAdmin ?: array_map(
            fn (array $field) => self::shape($field['key'], $field['label'], $field['placeholder'] ?? null),
            self::DEFAULTS[$kind] ?? [],
        );
    }

    /**
     * @param  list<string>|null  $options
     * @param  list<string>|null  $refKinds
     * @return array{key: string, label: string, placeholder: string|null, type: string, multiple: bool, options: list<string>|null, ref_kinds: list<string>|null, help: string|null, required: bool, link_label: string|null, inverse_label: string|null}
     */
    private static function shape(string $key, string $label, ?string $placeholder = null, string $type = 'text', ?array $options = null, ?array $refKinds = null, ?string $help = null, bool $required = false, bool $multiple = false, ?string $linkLabel = null, ?string $inverseLabel = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'placeholder' => $placeholder,
            'type' => $type,
            'multiple' => $multiple,
            'options' => $options,
            'ref_kinds' => $refKinds,
            'help' => $help,
            'required' => $required,
            'link_label' => $linkLabel,
            'inverse_label' => $inverseLabel,
        ];
    }

    public static function isFormKind(string $kind): bool
    {
        return count(self::for($kind)) > 0;
    }
}
