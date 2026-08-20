<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_creates_edits_and_deletes_a_session(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->post(route('sessions.store', $campaign), ['title' => 'Session 1', 'summary' => 'Arrival'])
            ->assertRedirect();
        $session = $campaign->sessions()->firstOrFail();
        $this->assertSame('Session 1', $session->title);
        $this->assertFalse($session->is_private);

        $this->actingAs($gm)->put(route('sessions.update', $session), [
            'title' => 'Session 1 — Low Tide',
            'body' => 'The party arrived at the drowned bell.',
            'is_private' => true,
        ])->assertRedirect();

        $session->refresh();
        $this->assertSame('Session 1 — Low Tide', $session->title);
        $this->assertSame('The party arrived at the drowned bell.', $session->body);
        $this->assertTrue($session->is_private);

        $this->actingAs($gm)->delete(route('sessions.destroy', $session))->assertRedirect();
        $this->assertSame(0, $campaign->sessions()->count());
    }

    public function test_a_non_gm_cannot_manage_a_campaigns_sessions(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'Secret prep']);

        $intruder = User::factory()->create();

        $this->actingAs($intruder)->post(route('sessions.store', $campaign), ['title' => 'X'])->assertForbidden();
        $this->actingAs($intruder)->put(route('sessions.update', $session), ['title' => 'X'])->assertForbidden();
        $this->actingAs($intruder)->delete(route('sessions.destroy', $session))->assertForbidden();
        $this->actingAs($intruder)->get(route('sessions.edit', [$session->campaign->world_id, $session->campaign_id, $session->id]))->assertForbidden();
        $this->assertSame('Secret prep', $session->fresh()->title);
    }

    public function test_the_full_editor_loads_a_session_with_its_body_as_content(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'The Sunken Cathedral', 'body' => "# Recap\n\nThe party descended."]);

        $this->actingAs($gm)->get(route('sessions.edit', [$session->campaign->world_id, $session->campaign_id, $session->id]))->assertInertia(fn (Assert $page) => $page
            ->component('Sessions/Edit')
            ->where('document.kind', 'session')
            ->where('document.title', 'The Sunken Cathedral')
            ->where('document.content', "# Recap\n\nThe party descended.")
            ->where('campaign.name', $campaign->name));
    }

    public function test_the_legacy_flat_session_edit_url_redirects_to_the_nested_one(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $session = $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'S']);

        $this->actingAs($gm)->get("/sessions/{$session->id}/edit")
            ->assertRedirect(route('sessions.edit', [$session->campaign->world_id, $session->campaign_id, $session->id]));
    }

    public function test_the_editor_saves_the_markdown_body_sent_as_content(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'Session 4']);

        // The BrewEditor sends the markdown body under `content`, not `body`.
        $this->actingAs($gm)->put(route('sessions.update', $session), [
            'title' => 'Session 4',
            'content' => '## The party regrouped at the drowned bell.',
            'summary' => 'They regrouped.',
        ])->assertRedirect();

        $this->assertSame('## The party regrouped at the drowned bell.', $session->fresh()->body);
        $this->assertSame('They regrouped.', $session->fresh()->summary);
    }

    public function test_the_muse_assistant_replies_for_a_session_and_spends_a_credit(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => 'Here is an expanded recap.']],
            'usage' => ['input_tokens' => 50, 'output_tokens' => 30],
        ], 200)]);

        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'Session 4', 'body' => 'Rough notes.']);

        $this->actingAs($gm)->postJson(route('sessions.ai', $session), ['prompt' => 'Expand these notes.'])
            ->assertOk()
            ->assertJsonPath('reply', 'Here is an expanded recap.');

        $this->assertSame(1, $gm->fresh()->daily_ai_used);
    }

    public function test_a_non_gm_cannot_use_the_session_assistant(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();
        $session = $campaign->sessions()->create(['title' => 'Session 4']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson(route('sessions.ai', $session), ['prompt' => 'hi'])->assertForbidden();
    }
}
