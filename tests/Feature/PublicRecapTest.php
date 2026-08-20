<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recap;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicRecapTest extends TestCase
{
    use RefreshDatabase;

    private function doneRecap(User $gm): Recap
    {
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $session = $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'Session 3']);

        return $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
            'recap_full' => 'The party rang the bell.', 'recap_short' => 'They rang it.',
            'moments' => [['type' => 'epic', 'description' => 'The bell tolled.', 'context' => '']],
            'outline' => [['title' => 'The Bell', 'detail' => 'They rang it.']],
            'next_steps' => ['Return at the next low tide'],
        ]);
    }

    public function test_a_gm_can_create_a_public_share_link(): void
    {
        $gm = User::factory()->create();
        $recap = $this->doneRecap($gm);

        $response = $this->actingAs($gm)->postJson(route('sessions.recap.share', $recap->session))->assertOk();

        $this->assertStringContainsString('/recap/', (string) $response->json('share_url'));
        $this->assertNotNull($recap->fresh()->share_token);
    }

    public function test_the_public_recap_page_renders_for_anyone_with_the_link(): void
    {
        $gm = User::factory()->create();
        $recap = $this->doneRecap($gm);
        $recap->update(['share_token' => 'sharetoken123']);

        // No authentication — a public visitor.
        $this->get(route('public.recap', 'sharetoken123'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Recap')
                ->where('session.title', 'Session 3')
                ->where('recap.recap_short', 'They rang it.')
                ->has('recap.moments', 1)
                ->has('recap.next_steps', 1));
    }

    public function test_the_public_recap_page_does_not_expose_the_gm_only_transcript_or_rating(): void
    {
        $gm = User::factory()->create();
        $recap = $this->doneRecap($gm);
        $recap->update([
            'share_token' => 'sharetoken123',
            'transcript' => 'GM: The bell tolls. Player: I ring it again.',
            'rating' => 5,
        ]);

        $this->get(route('public.recap', 'sharetoken123'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Recap')
                // The narrative is public; the raw transcript and the GM's private rating must not be.
                ->where('recap.recap_short', 'They rang it.')
                ->missing('recap.transcript')
                ->missing('recap.rating'));
    }

    public function test_an_unknown_token_is_not_found(): void
    {
        $this->get(route('public.recap', 'does-not-exist'))->assertNotFound();
    }

    public function test_revoking_the_link_makes_it_no_longer_public(): void
    {
        $gm = User::factory()->create();
        $recap = $this->doneRecap($gm);
        $recap->update(['share_token' => 'tok']);

        $this->actingAs($gm)->deleteJson(route('sessions.recap.unshare', $recap->session))->assertOk();

        $this->assertNull($recap->fresh()->share_token);
        $this->get('/recap/tok')->assertNotFound();
    }

    public function test_a_recap_that_is_not_done_cannot_be_shared(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $session = $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'S']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'x',
            'detail_level' => 'comprehensive', 'status' => 'transcribing',
        ]);

        $this->actingAs($gm)->postJson(route('sessions.recap.share', $session))->assertStatus(422);
    }

    public function test_a_non_gm_cannot_share_a_recap(): void
    {
        $gm = User::factory()->create();
        $recap = $this->doneRecap($gm);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson(route('sessions.recap.share', $recap->session))->assertForbidden();
    }
}
