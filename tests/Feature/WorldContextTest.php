<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\WorldContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorldContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_digest_carries_the_setting_and_groups_existing_entries_by_kind(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create([
            'name' => 'Saltmere',
            'visibility' => 'public',
            'setting' => 'grimdark nautical fantasy',
        ]);
        $world->documents()->create([
            'user_id' => $owner->id, 'kind' => 'location', 'title' => 'The Deepmarket',
            'slug' => 'the-deepmarket', 'summary' => 'a sunken bazaar', 'is_private' => false,
        ]);
        $world->documents()->create([
            'user_id' => $owner->id, 'kind' => 'npc', 'title' => 'Aria Saltcaller',
            'slug' => 'aria-saltcaller', 'summary' => 'a smuggler-turned-informant', 'is_private' => false,
        ]);

        $digest = WorldContext::forPrompt($world);

        $this->assertStringContainsString('grimdark nautical fantasy', $digest);
        $this->assertStringContainsString('Locations: The Deepmarket (a sunken bazaar)', $digest);
        $this->assertStringContainsString('People: Aria Saltcaller (a smuggler-turned-informant)', $digest);
    }

    public function test_the_digest_notes_how_many_entries_were_omitted_past_the_cap(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        foreach (range(1, 15) as $index) {
            $world->documents()->create([
                'user_id' => $owner->id, 'kind' => 'location', 'title' => "Place {$index}",
                'slug' => "place-{$index}", 'is_private' => false,
            ]);
        }

        // 15 locations, cap of 12 → "(+3 more)".
        $this->assertStringContainsString('(+3 more)', WorldContext::forPrompt($world, 12));
    }
}
