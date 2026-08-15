<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccessRolesTest extends TestCase
{
    use RefreshDatabase;

    private function png(): UploadedFile
    {
        return UploadedFile::fake()->create('a.png', 100, 'image/png');
    }

    public function test_a_co_author_can_edit_lore_but_not_run_play(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $coauthor = User::factory()->create();
        $world->members()->create(['user_id' => $coauthor->id, 'role' => 'editor']);

        // Lore: may upload media.
        $this->actingAs($coauthor)->post(route('media.store', $world), ['file' => $this->png()])->assertCreated();

        // Play: may not create a campaign.
        $this->actingAs($coauthor)->post(route('campaigns.store', $world), ['name' => 'C'])->assertForbidden();
    }

    public function test_a_moderator_can_run_play_but_not_edit_lore(): void
    {
        Storage::fake('public');
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $moderator = User::factory()->create();
        $world->members()->create(['user_id' => $moderator->id, 'role' => 'moderator']);

        // Play: may create a campaign.
        $this->actingAs($moderator)->post(route('campaigns.store', $world), ['name' => 'C'])->assertRedirect();

        // Lore: may not upload media.
        $this->actingAs($moderator)->post(route('media.store', $world), ['file' => $this->png()])->assertForbidden();
    }

    public function test_a_co_gm_can_manage_their_campaign(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();
        $coGm = User::factory()->create();
        $campaign->members()->create(['user_id' => $coGm->id, 'role' => 'co-gm']);

        $this->actingAs($coGm)->post(route('sessions.store', $campaign), ['title' => 'S1'])->assertRedirect();
    }

    public function test_a_plain_player_cannot_manage_a_campaign(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);

        $this->actingAs($player)->post(route('sessions.store', $campaign), ['title' => 'S1'])->assertForbidden();
    }

    public function test_only_the_owner_can_change_a_collaborators_role(): void
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $member = $world->members()->create(['user_id' => User::factory()->create()->id, 'role' => 'editor']);

        // A co-author can't reassign roles.
        $coauthor = User::factory()->create();
        $world->members()->create(['user_id' => $coauthor->id, 'role' => 'editor']);
        $this->actingAs($coauthor)->put(route('worlds.members.update', [$world, $member]), ['role' => 'moderator'])->assertForbidden();

        // The owner can.
        $this->actingAs($owner)->put(route('worlds.members.update', [$world, $member]), ['role' => 'moderator'])->assertRedirect();
        $this->assertSame('moderator', $member->fresh()->role);
    }
}
