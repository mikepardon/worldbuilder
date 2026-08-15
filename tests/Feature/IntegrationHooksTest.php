<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\NotifyDiscordRecap;
use App\Models\Session;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class IntegrationHooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plausible_domain_loads_for_readers_but_not_for_the_gm(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['reader_analytics' => 'myworld.com'],
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.analytics.provider', 'plausible')
                ->where('campaign.analytics.id', 'myworld.com'));

        // The GM's own preview must not be counted.
        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page->where('campaign.analytics', null));
    }

    public function test_a_ga4_measurement_id_is_detected_as_google_analytics(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['reader_analytics' => 'G-ABC1234'],
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.analytics.provider', 'ga')
                ->where('campaign.analytics.id', 'G-ABC1234'));
    }

    public function test_connecting_discord_stores_the_webhook_without_exposing_it(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $webhook = 'https://discord.com/api/webhooks/123/abcdef';

        $this->actingAs($gm)
            ->put(route('worlds.update', $world->id), ['discord_webhook' => $webhook])
            ->assertRedirect();

        // Stored and decryptable server-side.
        $this->assertSame($webhook, $world->refresh()->discord_webhook);

        // The settings page reports only that one is connected — never the secret URL.
        $this->actingAs($gm)->get(route('worlds.settings', $world->id))
            ->assertInertia(fn (Assert $page) => $page->where('settings.discord_connected', true))
            ->assertDontSee($webhook);
    }

    public function test_a_non_discord_webhook_url_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->put(route('worlds.update', $world->id), ['discord_webhook' => 'https://evil.example.com/hook'])
            ->assertSessionHasErrors('discord_webhook');

        $this->assertNull($world->refresh()->discord_webhook);
    }

    public function test_publishing_a_recap_notifies_a_connected_discord_channel(): void
    {
        Queue::fake();

        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['recap_auto_publish' => false],
            'discord_webhook' => 'https://discord.com/api/webhooks/1/xyz',
        ]);
        $session = $this->doneUnpublishedRecap($gm, $world);

        $this->actingAs($gm)
            ->post(route('sessions.recap.publish', $session), ['published' => true])
            ->assertOk();

        Queue::assertPushed(NotifyDiscordRecap::class, fn (NotifyDiscordRecap $job) => $job->sessionId === $session->id);
    }

    public function test_publishing_does_not_notify_when_no_webhook_is_connected(): void
    {
        Queue::fake();

        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['recap_auto_publish' => false],
        ]);
        $session = $this->doneUnpublishedRecap($gm, $world);

        $this->actingAs($gm)
            ->post(route('sessions.recap.publish', $session), ['published' => true])
            ->assertOk();

        Queue::assertNotPushed(NotifyDiscordRecap::class);
    }

    public function test_the_discord_job_posts_the_recap_to_the_webhook(): void
    {
        Http::fake();

        $gm = User::factory()->create();
        $webhook = 'https://discord.com/api/webhooks/1/xyz';
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'discord_webhook' => $webhook,
        ]);
        $session = $this->doneUnpublishedRecap($gm, $world);

        (new NotifyDiscordRecap($session->id))->handle();

        Http::assertSent(fn ($request) => $request->url() === $webhook
            && str_contains((string) $request['content'], $session->title));
    }

    public function test_the_discord_job_is_a_noop_without_a_webhook(): void
    {
        Http::fake();

        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $session = $this->doneUnpublishedRecap($gm, $world);

        (new NotifyDiscordRecap($session->id))->handle();

        Http::assertNothingSent();
    }

    /** A campaign session carrying a finished, still-unpublished recap. */
    private function doneUnpublishedRecap(User $gm, World $world): Session
    {
        $campaign = $world->campaigns()->create(['name' => 'C', 'visibility' => 'public']);
        $session = $campaign->sessions()->create(['title' => 'The Sunken Bell']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done', 'published_at' => null,
            'recap_short' => 'The party rang the drowned bell.',
        ]);

        return $session;
    }
}
