<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\RuleSystem;
use App\Models\User;
use App\Support\DemoRuleSystem;
use Illuminate\Database\Seeder;

/**
 * Seeds the "Ascendancy" starter as a global template in the admin library, so every GM has a rich,
 * complete system to clone into their world on day one.
 */
class TtrpgSystemSeeder extends Seeder
{
    private const DESCRIPTION = 'A d20-flavoured starter: five stats, fifteen skills, and a radial web of gateways, avenues, keystones and spells across five trees.';

    public function run(): void
    {
        // Match the current name or the old "talent-web" slug, so re-seeding migrates an existing copy
        // in place (keeping any clones pointing at it) rather than leaving a duplicate behind.
        $existing = RuleSystem::where('is_template', true)->whereIn('slug', ['ascendancy', 'talent-web'])->first();

        if ($existing !== null) {
            $existing->webs()->get()->each->delete();
            $existing->stats()->delete();
            $existing->resources()->delete();
            $existing->skills()->delete();
            $existing->levels()->delete();
            $existing->nodeKinds()->delete();
            $existing->update([
                'name' => 'Ascendancy',
                'slug' => 'ascendancy',
                'description' => self::DESCRIPTION,
                'settings' => DemoRuleSystem::defaultSettings(),
            ]);
            (new DemoRuleSystem)->populate($existing->fresh());

            return;
        }

        $admin = User::where('is_admin', true)->orderBy('id')->first();

        $system = RuleSystem::create([
            'world_id' => null,
            'user_id' => $admin?->id,
            'is_template' => true,
            'name' => 'Ascendancy',
            'slug' => 'ascendancy',
            'description' => self::DESCRIPTION,
            'settings' => DemoRuleSystem::defaultSettings(),
        ]);

        (new DemoRuleSystem)->populate($system);
    }
}
