<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Character;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CharacterTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_player_creates_a_personal_character_from_the_dashboard(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('characters.personal.store'), ['name' => 'Aria'])
            ->assertRedirect();

        $character = Character::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('Aria', $character->name);
        $this->assertNull($character->campaign_id, 'a personal character is attached to no campaign');
    }

    public function test_only_the_owner_can_delete_a_personal_character(): void
    {
        $owner = User::factory()->create();
        $character = Character::create(['user_id' => $owner->id, 'campaign_id' => null, 'name' => 'Temp']);

        $this->actingAs(User::factory()->create())
            ->delete(route('characters.destroy', $character))->assertForbidden();
        $this->assertDatabaseHas('characters', ['id' => $character->id]);

        $this->actingAs($owner)->delete(route('characters.destroy', $character))->assertRedirect();
        $this->assertDatabaseMissing('characters', ['id' => $character->id]);
    }

    /** @return array{0: User, 1: Campaign} */
    private function world(): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();

        return [$gm, $campaign];
    }

    public function test_a_player_attaches_and_detaches_a_personal_character(): void
    {
        [, $campaign] = $this->world();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $character = Character::create(['user_id' => $player->id, 'campaign_id' => null, 'name' => 'Aria']);

        $this->actingAs($player)->post(route('characters.attach', $campaign), ['character_id' => $character->id])
            ->assertRedirect();
        $this->assertSame($campaign->id, $character->fresh()->campaign_id);

        // Detaching keeps the character but returns it to the personal roster.
        $this->actingAs($player)->put(route('characters.detach', $character))->assertRedirect();
        $this->assertNull($character->fresh()->campaign_id);
    }

    public function test_a_player_cannot_attach_someone_elses_character(): void
    {
        [, $campaign] = $this->world();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $character = Character::create(['user_id' => User::factory()->create()->id, 'campaign_id' => null, 'name' => 'NotYours']);

        $this->actingAs($player)->post(route('characters.attach', $campaign), ['character_id' => $character->id])
            ->assertForbidden();
        $this->assertNull($character->fresh()->campaign_id);
    }

    public function test_a_gm_creates_a_character_from_scratch(): void
    {
        [$gm, $campaign] = $this->world();

        $this->actingAs($gm)->post(route('characters.store', $campaign), ['name' => 'Aria Windborne'])
            ->assertRedirect();

        $this->assertDatabaseHas('characters', [
            'campaign_id' => $campaign->id, 'name' => 'Aria Windborne', 'slug' => 'aria-windborne',
        ]);
    }

    public function test_a_player_creates_a_character_owned_by_themself(): void
    {
        [, $campaign] = $this->world();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);

        $this->actingAs($player)->post(route('characters.store', $campaign), ['name' => 'Thistle'])
            ->assertRedirect();

        $this->assertDatabaseHas('characters', [
            'campaign_id' => $campaign->id, 'name' => 'Thistle', 'user_id' => $player->id,
        ]);
    }

    public function test_a_non_member_cannot_create_a_character(): void
    {
        [, $campaign] = $this->world();

        $this->actingAs(User::factory()->create())
            ->post(route('characters.store', $campaign), ['name' => 'Intruder'])->assertForbidden();
    }

    public function test_importing_from_dnd_beyond_populates_stats(): void
    {
        [$gm, $campaign] = $this->world();

        Http::fake(['character-service.dndbeyond.com/*' => Http::response(['data' => [
            'name' => 'Vex', 'decorations' => ['avatarUrl' => ''],
            'baseHitPoints' => 42, 'removedHitPoints' => 5, 'armorClass' => 17,
            'race' => ['fullName' => 'Tiefling'],
            'classes' => [['level' => 6, 'definition' => ['name' => 'Warlock']]],
            'stats' => [['id' => 1, 'value' => 10], ['id' => 4, 'value' => 18]],
        ]])]);

        $this->actingAs($gm)->post(route('characters.store', $campaign), [
            'ddb_url' => 'https://www.dndbeyond.com/characters/555',
        ])->assertRedirect();

        $character = $campaign->characters()->first();
        $this->assertSame('Vex', $character->name);
        $this->assertSame('555', $character->ddb_character_id);
        $this->assertSame(6, $character->level);
        $this->assertSame('Warlock', $character->class);
        $this->assertSame('Tiefling', $character->race);
        $this->assertSame(17, $character->ac);
        $this->assertSame(42, $character->max_hp);
        $this->assertSame(37, $character->hp);
        $this->assertSame(18, $character->stats['int']);
    }

    public function test_the_gm_pulls_the_linked_dnd_beyond_campaign_roster(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'World', 'visibility' => 'public',
            'ddb_enabled' => true, 'ddb_cobalt' => 'cobalt-token', 'ddb_campaign_id' => '42',
        ]);
        $campaign = $world->campaigns()->firstOrFail();

        Http::fake([
            'auth-service.dndbeyond.com/*' => Http::response(['token' => 'bearer']),
            'dndbeyond.com/api/campaign/stt/user-campaigns' => Http::response(['data' => [
                ['id' => 42, 'name' => 'The Sundered Coast', 'characters' => [
                    ['characterId' => 100, 'characterName' => 'Vex', 'avatarUrl' => ''],
                ]],
                ['id' => 99, 'name' => 'Other', 'characters' => [
                    ['characterId' => 200, 'characterName' => 'NotMine'],
                ]],
            ]]),
            'character-service.dndbeyond.com/*' => Http::response(['data' => [
                'name' => 'Vex', 'decorations' => ['avatarUrl' => ''],
                'baseHitPoints' => 40, 'armorClass' => 15,
                'classes' => [['level' => 5, 'definition' => ['name' => 'Rogue']]],
                'race' => ['fullName' => 'Elf'],
            ]]),
        ]);

        $this->actingAs($gm)->post(route('characters.pull', $campaign))->assertRedirect();

        // Only the linked campaign's character is imported, with full stats.
        $this->assertDatabaseHas('characters', [
            'campaign_id' => $campaign->id, 'ddb_character_id' => '100', 'name' => 'Vex',
            'level' => 5, 'class' => 'Rogue', 'ac' => 15, 'max_hp' => 40,
        ]);
        $this->assertDatabaseMissing('characters', ['ddb_character_id' => '200']);

        // Re-syncing updates in place rather than duplicating.
        $this->actingAs($gm)->post(route('characters.pull', $campaign))->assertRedirect();
        $this->assertSame(1, $campaign->characters()->count());
    }

    public function test_the_sync_flags_characters_it_cannot_read(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'World', 'visibility' => 'public',
            'ddb_enabled' => true, 'ddb_cobalt' => 'cobalt-token', 'ddb_campaign_id' => '42',
        ]);
        $campaign = $world->campaigns()->firstOrFail();

        Http::fake([
            'auth-service.dndbeyond.com/*' => Http::response(['token' => 'bearer']),
            'dndbeyond.com/api/campaign/stt/user-campaigns' => Http::response(['data' => [
                ['id' => 42, 'name' => 'C', 'characters' => [
                    ['characterId' => 100, 'characterName' => 'Public Vex', 'avatarUrl' => ''],
                    ['characterId' => 101, 'characterName' => 'Private Ana', 'avatarUrl' => ''],
                ]],
            ]]),
            // 101 is private → 403; 100 resolves.
            'character-service.dndbeyond.com/*' => fn ($request) => str_contains($request->url(), '/101')
                ? Http::response([], 403)
                : Http::response(['data' => ['name' => 'Public Vex', 'baseHitPoints' => 20]]),
        ]);

        $this->actingAs($gm)->post(route('characters.pull', $campaign))->assertRedirect();

        $this->assertDatabaseHas('characters', [
            'campaign_id' => $campaign->id, 'ddb_character_id' => '100', 'ddb_unreadable' => false,
        ]);
        $this->assertDatabaseHas('characters', [
            'campaign_id' => $campaign->id, 'ddb_character_id' => '101',
            'name' => 'Private Ana', 'ddb_unreadable' => true,
        ]);
    }

    public function test_pulling_without_a_linked_campaign_errors(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public', 'ddb_enabled' => true]);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->post(route('characters.pull', $campaign))->assertSessionHasErrors('ddb');
    }

    public function test_a_player_cannot_pull_the_dnd_beyond_roster(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public', 'ddb_enabled' => true]);
        $campaign = $world->campaigns()->firstOrFail();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);

        $this->actingAs($player)->post(route('characters.pull', $campaign))->assertForbidden();
    }

    public function test_a_player_can_edit_their_own_character_but_not_anothers(): void
    {
        [, $campaign] = $this->world();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $campaign->members()->create(['user_id' => $alice->id, 'role' => 'player']);
        $campaign->members()->create(['user_id' => $bob->id, 'role' => 'player']);
        $hers = $campaign->characters()->create(['name' => 'Hers', 'user_id' => $alice->id]);

        $this->actingAs($alice)->put(route('characters.update', $hers), ['level' => 4])->assertRedirect();
        $this->assertSame(4, $hers->fresh()->level);

        $this->actingAs($bob)->put(route('characters.update', $hers), ['level' => 9])->assertForbidden();
    }

    public function test_only_the_gm_can_reassign_a_characters_owner(): void
    {
        [$gm, $campaign] = $this->world();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $campaign->members()->create(['user_id' => $alice->id, 'role' => 'player']);
        $campaign->members()->create(['user_id' => $bob->id, 'role' => 'player']);
        $character = $campaign->characters()->create(['name' => 'Rogue', 'user_id' => $alice->id]);

        // A player cannot reassign, even their own character.
        $this->actingAs($alice)->put(route('characters.update', $character), ['owner_user_id' => $bob->id])
            ->assertRedirect();
        $this->assertSame($alice->id, $character->fresh()->user_id);

        // The GM can.
        $this->actingAs($gm)->put(route('characters.update', $character), ['owner_user_id' => $bob->id])
            ->assertRedirect();
        $this->assertSame($bob->id, $character->fresh()->user_id);
    }

    public function test_a_character_owner_can_delete_it(): void
    {
        [, $campaign] = $this->world();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $character = $campaign->characters()->create(['name' => 'Doomed', 'user_id' => $player->id]);

        $this->actingAs($player)->delete(route('characters.destroy', $character))->assertRedirect();

        $this->assertDatabaseMissing('characters', ['id' => $character->id]);
    }

    public function test_a_slug_is_unique_within_a_campaign(): void
    {
        [$gm, $campaign] = $this->world();
        $first = $campaign->characters()->create(['name' => 'Aria']);
        $second = $campaign->characters()->create(['name' => 'Aria']);

        $this->assertSame('aria', $first->slug);
        $this->assertSame('aria-2', $second->slug);
        $this->assertNotSame($gm->id, 0);
    }
}
