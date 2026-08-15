<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_register_a_webhook_with_a_generated_secret(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.webhooks.store', $world->id), [
            'url' => 'https://example.com/hook',
            'events' => ['recap.published', 'session.scheduled'],
        ])->assertRedirect();

        $webhook = $world->webhooks()->firstOrFail();
        $this->assertSame('https://example.com/hook', $webhook->url);
        $this->assertTrue($webhook->is_active);
        $this->assertEqualsCanonicalizing(['recap.published', 'session.scheduled'], $webhook->events);
        $this->assertNotEmpty($webhook->secret);
    }

    public function test_an_unknown_event_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.webhooks.store', $world->id), [
            'url' => 'https://example.com/hook',
            'events' => ['bogus.event'],
        ])->assertSessionHasErrors('events.0');
    }

    public function test_a_stranger_cannot_register_a_webhook(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('worlds.webhooks.store', $world->id), [
            'url' => 'https://example.com/hook', 'events' => ['recap.published'],
        ])->assertForbidden();
    }

    public function test_scheduling_a_session_dispatches_only_subscribed_active_webhooks(): void
    {
        Queue::fake();

        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);

        $world->webhooks()->create(['url' => 'https://a.test', 'events' => ['session.scheduled'], 'is_active' => true, 'secret' => 'x']);
        $world->webhooks()->create(['url' => 'https://b.test', 'events' => ['rsvp.updated'], 'is_active' => true, 'secret' => 'x']);
        $world->webhooks()->create(['url' => 'https://c.test', 'events' => ['session.scheduled'], 'is_active' => false, 'secret' => 'x']);

        $this->actingAs($gm)->post(route('schedule.store', $world), [
            'campaign_id' => $campaign->id, 'title' => 'Session 1', 'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertRedirect();

        Queue::assertPushed(DeliverWebhook::class, 1);
    }

    public function test_the_delivery_job_posts_a_signed_payload(): void
    {
        Http::fake();

        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $webhook = $world->webhooks()->create([
            'url' => 'https://a.test/hook', 'events' => ['recap.published'], 'is_active' => true, 'secret' => 'shh',
        ]);

        (new DeliverWebhook($webhook->id, 'recap.published', ['world' => ['slug' => 'w']]))->handle();

        Http::assertSent(function ($request) {
            $body = $request->body();
            $expected = hash_hmac('sha256', $body, 'shh');

            return $request->url() === 'https://a.test/hook'
                && $request->hasHeader('X-Worldbuilder-Event', 'recap.published')
                && $request->hasHeader('X-Worldbuilder-Signature', "sha256={$expected}")
                && str_contains($body, '"event":"recap.published"');
        });
    }

    public function test_an_inactive_webhook_delivers_nothing(): void
    {
        Http::fake();

        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $webhook = $world->webhooks()->create([
            'url' => 'https://a.test/hook', 'events' => ['recap.published'], 'is_active' => false, 'secret' => 'shh',
        ]);

        (new DeliverWebhook($webhook->id, 'recap.published', []))->handle();

        Http::assertNothingSent();
    }
}
