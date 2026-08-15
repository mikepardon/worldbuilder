<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlayDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_campaign_inherits_the_world_game_system(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['default_game_system' => 'PF2e'],
        ]);

        $this->actingAs($gm)->post(route('campaigns.store', $world), ['name' => 'C'])->assertRedirect();

        $this->assertSame('PF2e', $world->campaigns()->where('name', 'C')->firstOrFail()->game_system);
    }

    public function test_recaps_await_gm_review_when_auto_publish_is_off(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'settings' => ['recap_auto_publish' => false],
        ]);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);
        $session = $campaign->sessions()->create(['title' => 'S1']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done', 'published_at' => null,
            'recap_stylized' => 'The tide rolled in.',
        ]);

        $url = route('public.campaign.session', [$world, $campaign, $session]);

        // Unpublished: hidden from the public, visible to the GM.
        $this->get($url)->assertNotFound();
        $this->actingAs($gm)->get($url)->assertOk();

        // The GM publishes it — now the public sees it.
        $this->actingAs($gm)->post(route('sessions.recap.publish', $session), ['published' => true])->assertOk();
        $this->assertNotNull($session->recap->fresh()->published_at);
        $this->get($url)->assertOk();
    }

    public function test_recaps_auto_publish_by_default(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);
        $session = $campaign->sessions()->create(['title' => 'S1']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done', 'published_at' => null,
            'recap_stylized' => 'The tide rolled in.',
        ]);

        // Default world auto-publishes, so even an unpublished-in-DB recap is visible.
        $this->get(route('public.campaign.session', [$world, $campaign, $session]))->assertOk();
    }
}
