<?php

namespace Database\Seeders;

use App\Models\GlobalAttribute;
use App\Support\DocFields;
use Illuminate\Database\Seeder;

class GlobalAttributeSeeder extends Seeder
{
    public function run(): void
    {
        // Collapse the built-in per-kind starter fields into shared attributes keyed by `key`,
        // merging the kinds a field applies to (e.g. "type" applies to both locations and factions).
        $byKey = [];
        $order = 0;

        foreach (DocFields::DEFAULTS as $kind => $fields) {
            foreach ($fields as $field) {
                $key = $field['key'];

                if (! isset($byKey[$key])) {
                    $byKey[$key] = [
                        'label' => $field['label'],
                        'type' => 'text',
                        'placeholder' => $field['placeholder'] ?? null,
                        'kinds' => [],
                        'visible' => true,
                        'sort_order' => $order++,
                    ];
                }

                $byKey[$key]['kinds'][] = $kind;
            }
        }

        foreach ($byKey as $key => $attr) {
            GlobalAttribute::updateOrCreate(['key' => $key], $attr);
        }
    }
}
