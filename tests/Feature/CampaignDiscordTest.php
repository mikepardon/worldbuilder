<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\NotifyDiscord;
use App\Models\Campaign;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignDiscordTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World, 2: Campaign} */
    private function campaign(?string $campaignHook = null, ?string $worldHook = null): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'discord_webhook' => $worldHook,
        ]);
        $campaign = $world->campaigns()->create(['name' => 'C']);

        if ($campaignHook !== null) {
            $campaign->discord_webhook = $campaignHook;
            $campaign->save();
        }

        return [$gm, $world, $campaign];
    }

    public function test_the_notify_job_posts_to_the_campaign_webhook(): void
    {
        Http::fake();
        [, , $campaign] = $this->campaign('https://discord.com/api/webhooks/1/abc');

        (new NotifyDiscord($campaign->id, 'Hello there'))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/webhooks/1/abc'
            && $request['content'] === 'Hello there');
    }

    public function test_it_falls_back_to_the_world_webhook_when_the_campaign_has_none(): void
    {
        Http::fake();
        [, , $campaign] = $this->campaign(null, 'https://discord.com/api/webhooks/9/world');

        (new NotifyDiscord($campaign->id, 'Hi'))->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://discord.com/api/webhooks/9/world');
    }

    public function test_it_sends_nothing_when_neither_campaign_nor_world_has_a_webhook(): void
    {
        Http::fake();
        [, , $campaign] = $this->campaign();

        (new NotifyDiscord($campaign->id, 'Hi'))->handle();

        Http::assertNothingSent();
    }

    public function test_scheduling_a_session_queues_a_discord_announcement(): void
    {
        Bus::fake();
        [$gm, $world, $campaign] = $this->campaign('https://discord.com/api/webhooks/1/abc');

        $this->actingAs($gm)->post(route('schedule.store', $world), [
            'campaign_id' => $campaign->id,
            'title' => 'The Sunken Vault',
            'starts_at' => now()->addWeek()->toDateTimeString(),
        ])->assertRedirect();

        Bus::assertDispatched(NotifyDiscord::class);
    }

    public function test_a_gm_can_connect_and_then_clear_a_campaign_webhook(): void
    {
        [$gm, , $campaign] = $this->campaign();

        $this->actingAs($gm)->put(route('campaigns.update', $campaign), [
            'discord_webhook' => 'https://discord.com/api/webhooks/1/abc',
        ])->assertRedirect();
        $this->assertSame('https://discord.com/api/webhooks/1/abc', $campaign->fresh()->discord_webhook);

        // Saving a blank webhook clears the campaign override.
        $this->actingAs($gm)->put(route('campaigns.update', $campaign), [
            'discord_webhook' => '',
        ])->assertRedirect();
        $this->assertNull($campaign->fresh()->discord_webhook);
    }

    public function test_an_invalid_webhook_url_is_rejected(): void
    {
        [$gm, $world, $campaign] = $this->campaign();

        $this->actingAs($gm)
            ->from(route('campaigns.settings', [$world, $campaign]))
            ->put(route('campaigns.update', $campaign), ['discord_webhook' => 'https://evil.example.com/hook'])
            ->assertSessionHasErrors('discord_webhook');

        $this->assertNull($campaign->fresh()->discord_webhook);
    }
}
