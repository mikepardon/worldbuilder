<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\User;
use App\Models\World;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicCampaignTest extends TestCase
{
    use RefreshDatabase;

    private function publicWorld(User $gm): World
    {
        return $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
    }

    /** A campaign with one finished-recap session, ready to appear in the reader. */
    private function campaignWithRecap(World $world, string $visibility, array $sessionOverrides = []): Campaign
    {
        $campaign = $world->campaigns()->create(['name' => 'The Salt Accord', 'visibility' => $visibility]);
        $session = $campaign->sessions()->create(['title' => 'Session 1'] + $sessionOverrides);
        $session->recap()->create([
            'user_id' => $world->user_id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
            'recap_stylized' => 'The tide rolled in.',
            'recap_full' => 'Full GM analysis of the tide.',
            'recap_short' => 'Tide.',
            'transcript' => 'GM: roll initiative.',
            'rating' => 4,
        ]);

        return $campaign;
    }

    public function test_a_public_campaign_is_listed_and_readable_by_anyone(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');

        $this->get(route('public.campaigns', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Campaigns')
                ->where('campaign.hasCampaigns', true)
                ->has('campaigns', 1)
                ->where('campaigns.0.slug', $campaign->slug)
                ->where('campaigns.0.session_count', 1));
    }

    public function test_a_private_campaign_is_not_listed_and_404s_for_non_members(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'private');

        $this->get(route('public.campaigns', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.hasCampaigns', false)
                ->has('campaigns', 0));

        $this->get(route('public.campaign', [$world, $campaign]))->assertNotFound();
    }

    public function test_a_member_can_read_their_private_campaign(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'private');
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);

        $this->actingAs($player)->get(route('public.campaign.sessions', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/CampaignSessions')
                ->has('sessions', 1));
    }

    public function test_a_hidden_campaign_is_reachable_by_link_but_not_listed(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'hidden');

        $this->get(route('public.campaigns', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('campaigns', 0));

        $this->get(route('public.campaign', [$world, $campaign]))->assertOk();
    }

    public function test_players_get_the_recap_content_but_not_the_raw_transcript(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $session = $campaign->sessions()->firstOrFail();

        $this->get(route('public.campaign.session', [$world, $campaign, $session]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Session')
                ->where('recap.recap_stylized', 'The tide rolled in.')
                ->where('recap.recap_full', 'Full GM analysis of the tide.')
                ->where('recap.transcript', null)
                ->where('recap.rating', null));
    }

    public function test_the_owner_gm_also_sees_the_transcript_and_rating(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $session = $campaign->sessions()->firstOrFail();

        $this->actingAs($gm)->get(route('public.campaign.session', [$world, $campaign, $session]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('recap.transcript', 'GM: roll initiative.')
                ->where('recap.rating', 4));
    }

    public function test_a_private_session_is_hidden_from_anonymous_visitors(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public', ['is_private' => true]);
        $session = $campaign->sessions()->firstOrFail();

        // Not listed on the campaign's sessions page …
        $this->get(route('public.campaign.sessions', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sessions', 0));

        // … and its recap 404s directly.
        $this->get(route('public.campaign.session', [$world, $campaign, $session]))->assertNotFound();

        // The owner still sees it.
        $this->actingAs($gm)->get(route('public.campaign.session', [$world, $campaign, $session]))->assertOk();
    }

    public function test_a_session_without_a_finished_recap_does_not_appear(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $world->campaigns()->create(['name' => 'Fresh', 'visibility' => 'public']);
        $campaign->sessions()->create(['title' => 'Unplayed']);

        $this->get(route('public.campaign.sessions', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('sessions', 0));
    }

    public function test_a_linked_entity_carries_a_reader_url(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $session = $campaign->sessions()->firstOrFail();

        $document = $world->documents()->create([
            'title' => 'The Amber Temple', 'slug' => 'the-amber-temple',
            'kind' => 'location', 'content' => 'A vault.', 'is_private' => false,
        ]);
        $session->recap->entities()->create([
            'name' => 'The Amber Temple', 'type' => 'location', 'status' => 'linked',
            'linked_document_id' => $document->id,
        ]);

        $expected = url("/w/{$world->slug}/".Sections::typeSlug('location').'/the-amber-temple');

        $this->get(route('public.campaign.session', [$world, $campaign, $session]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recap.entities.0.url', $expected));
    }

    public function test_a_linked_private_entry_is_not_clickable_for_the_public_but_is_for_the_owner(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $session = $campaign->sessions()->firstOrFail();

        $document = $world->documents()->create([
            'title' => 'Secret Vault', 'slug' => 'secret-vault',
            'kind' => 'location', 'content' => 'x', 'is_private' => true,
        ]);
        $session->recap->entities()->create([
            'name' => 'Secret Vault', 'type' => 'location', 'status' => 'linked',
            'linked_document_id' => $document->id,
        ]);

        $this->get(route('public.campaign.session', [$world, $campaign, $session]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('recap.entities.0.url', null));

        $this->actingAs($gm)->get(route('public.campaign.session', [$world, $campaign, $session]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->whereNot('recap.entities.0.url', null));
    }

    public function test_a_member_can_add_and_see_a_private_session_note(): void
    {
        $gm = User::factory()->create();
        $player = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $session = $campaign->sessions()->firstOrFail();

        $this->actingAs($player)->post("/sessions/{$session->id}/notes", ['body' => 'Remember the amber key.'])
            ->assertRedirect();

        $this->assertDatabaseHas('session_notes', [
            'session_id' => $session->id, 'user_id' => $player->id, 'body' => 'Remember the amber key.',
        ]);

        $this->actingAs($player)->get(route('public.campaign.session', [$world, $campaign, $session]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canNote', true)
                ->has('notes', 1)
                ->where('notes.0.body', 'Remember the amber key.'));
    }

    public function test_a_non_member_cannot_add_a_session_note(): void
    {
        $gm = User::factory()->create();
        $stranger = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $session = $campaign->sessions()->firstOrFail();

        $this->actingAs($stranger)->post("/sessions/{$session->id}/notes", ['body' => 'sneaky'])->assertForbidden();
    }

    public function test_an_anonymous_visitor_gets_no_notes_affordance(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $session = $campaign->sessions()->firstOrFail();

        $this->get(route('public.campaign.session', [$world, $campaign, $session]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('canNote', false)->has('notes', 0));
    }

    public function test_the_campaign_overview_shows_its_stats(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $campaign->characters()->create(['name' => 'Aria']);

        $this->get(route('public.campaign', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/CampaignOverview')
                ->where('stats.sessions', 1)
                ->where('stats.characters', 1));
    }

    public function test_a_campaigns_characters_are_listed_and_openable(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'public');
        $character = $campaign->characters()->create(['name' => 'Aria', 'class' => 'Wizard', 'level' => 3]);

        $this->get(route('public.campaign.characters', [$world, $campaign]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/CampaignCharacters')
                ->has('characters', 1)
                ->where('characters.0.name', 'Aria'));

        $this->get(route('public.campaign.character', [$world, $campaign, $character]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/CampaignCharacter')
                ->where('character.name', 'Aria'));
    }

    public function test_characters_are_hidden_from_a_private_campaign_for_non_members(): void
    {
        $gm = User::factory()->create();
        $world = $this->publicWorld($gm);
        $campaign = $this->campaignWithRecap($world, 'private');

        $this->get(route('public.campaign.characters', [$world, $campaign]))->assertNotFound();
    }

    public function test_a_private_world_hides_the_campaign_reader_from_strangers(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Hidden', 'visibility' => 'private']);
        $campaign = $this->campaignWithRecap($world, 'public');

        $this->get(route('public.campaigns', $world))->assertNotFound();
        $this->get(route('public.campaign', [$world, $campaign]))->assertNotFound();
    }
}
