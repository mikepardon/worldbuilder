<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SessionWriteUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_write_up_creates_a_linked_recap_article_and_leaves_the_session_untouched(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $session = $campaign->documents()->create([
            'title' => 'Session One', 'slug' => 'session-one', 'kind' => 'session',
            'content' => '# Session One', 'is_private' => false,
        ]);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chat')->once()->andReturn("# The Battle of Session One\n\nThe party fought goblins.");
        $this->app->instance(AnthropicClient::class, $ai);

        $response = $this->actingAs($gm)->postJson(route('documents.writeup', $session), [
            'notes' => 'fought goblins, found treasure',
        ]);

        $response->assertOk();

        $recap = $campaign->documents()->where('kind', 'article')->first();
        $this->assertNotNull($recap);
        $this->assertSame('Session One — Recap', $recap->title);
        $this->assertStringContainsString('The party fought goblins.', $recap->content);
        $response->assertJsonPath('redirect', route('documents.edit', $recap));

        // The original session is left exactly as it was.
        $this->assertSame('# Session One', $session->fresh()->content);
        $this->assertSame('session', $session->fresh()->kind);

        // The session links to its recap.
        $this->assertTrue($session->outgoingLinks()->where('to_document_id', $recap->id)->where('label', 'Session recap')->exists());
    }

    public function test_write_up_requires_notes(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $session = $campaign->documents()->create(['title' => 'S', 'slug' => 's', 'kind' => 'session']);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('chat')->never();
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->postJson(route('documents.writeup', $session), ['notes' => ''])
            ->assertStatus(422);
    }
}
