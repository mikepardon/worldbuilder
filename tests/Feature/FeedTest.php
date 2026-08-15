<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_atom_feed_lists_public_entries_but_not_private_ones(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Amber Temple', 'kind' => 'location',
            'slug' => 'the-amber-temple', 'summary' => 'A frozen vault.', 'is_private' => false,
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'GM Secret', 'kind' => 'location',
            'slug' => 'gm-secret', 'is_private' => true,
        ]);

        $response = $this->get(route('public.feed', $world));

        $response->assertOk();
        $this->assertStringContainsString('application/atom+xml', (string) $response->headers->get('Content-Type'));
        $response->assertSee('<feed', false);
        $response->assertSee('The Amber Temple', false);
        $response->assertSee("/w/{$world->slug}/location/the-amber-temple", false);
        $response->assertDontSee('GM Secret', false);
    }

    public function test_the_feed_escapes_entry_titles(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Smugglers & <Thieves>', 'kind' => 'location',
            'slug' => 'smugglers', 'is_private' => false,
        ]);

        $response = $this->get(route('public.feed', $world));

        $response->assertOk();
        $response->assertSee('Smugglers &amp; &lt;Thieves&gt;', false);
        $response->assertDontSee('<Thieves>', false);
    }

    public function test_a_private_worlds_feed_is_not_found(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Secret', 'visibility' => 'private']);

        $this->get(route('public.feed', $world))->assertNotFound();
    }

    public function test_the_ics_feed_lists_scheduled_events_as_vevents(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);
        $campaign->scheduleEvents()->create([
            'title' => 'Session 6', 'starts_at' => now()->addWeek(), 'notes' => 'At the tavern',
        ]);

        $response = $this->get(route('public.campaign.ics', [$world, $campaign]));

        $response->assertOk();
        $this->assertStringContainsString('text/calendar', (string) $response->headers->get('Content-Type'));
        $response->assertSee('BEGIN:VCALENDAR', false);
        $response->assertSee('BEGIN:VEVENT', false);
        $response->assertSee('SUMMARY:Session 6', false);
        $response->assertSee('DESCRIPTION:At the tavern', false);
    }
}
