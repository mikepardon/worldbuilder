<?php

namespace Database\Seeders;

use App\Models\CompendiumSource;
use App\Services\Open5eClient;
use App\Support\Compendium;
use Illuminate\Database\Seeder;

class CompendiumSourceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Open5eClient::endpoints() as $itemType => $endpoint) {
            CompendiumSource::updateOrCreate(
                ['key' => "open5e-{$endpoint}"],
                [
                    'name' => (Compendium::TYPES[$itemType]['plural'] ?? ucfirst($endpoint)).' (open5e)',
                    'provider' => 'open5e',
                    'item_type' => $itemType,
                    'api_url' => "https://api.open5e.com/v1/{$endpoint}/?limit=100",
                    'enabled' => true,
                ],
            );
        }

        // Open5e's weapons + armor both feed the single "equipment" type.
        foreach (['weapons' => 'Weapons', 'armor' => 'Armor'] as $endpoint => $label) {
            CompendiumSource::updateOrCreate(
                ['key' => "open5e-{$endpoint}"],
                [
                    'name' => "Equipment · {$label} (open5e)",
                    'provider' => 'open5e',
                    'item_type' => 'equipment',
                    'api_url' => "https://api.open5e.com/v1/{$endpoint}/?limit=100",
                    'enabled' => true,
                ],
            );
        }

        // dnd5eapi.co — an alternative SRD 5.1 source (list endpoints return refs; the provider fetches
        // each detail). Its "equipment" endpoint bundles weapons + armor + gear into one list.
        $dnd5e = [
            'monster' => 'monsters', 'spell' => 'spells', 'magicitem' => 'magic-items',
            'equipment' => 'equipment', 'condition' => 'conditions', 'race' => 'races', 'feat' => 'feats',
        ];
        foreach ($dnd5e as $itemType => $resource) {
            CompendiumSource::updateOrCreate(
                ['key' => "dnd5eapi-{$resource}"],
                [
                    'name' => (Compendium::TYPES[$itemType]['plural'] ?? ucfirst($resource)).' (dnd5eapi)',
                    'provider' => 'dnd5eapi',
                    'item_type' => $itemType,
                    'api_url' => "https://www.dnd5eapi.co/api/2014/{$resource}",
                    'enabled' => true,
                ],
            );
        }
    }
}
