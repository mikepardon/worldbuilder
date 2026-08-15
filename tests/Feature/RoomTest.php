<?php

namespace Tests\Feature;

use App\Events\RoomChanged;
use App\Events\TokenMoved;
use App\Models\Campaign;
use App\Models\Character;
use App\Models\Media;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RoomTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Campaign, 2: Room} */
    private function room(): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();
        $room = $campaign->rooms()->create(['name' => 'The Ambush', 'created_by' => $gm->id]);

        return [$gm, $campaign, $room];
    }

    /**
     * The chat messages a given viewer receives in the room payload (after server-side privacy filtering).
     *
     * @return list<array<string, mixed>>
     */
    private function messagesSeenBy(User $viewer, Room $room): array
    {
        $response = $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]));
        $response->assertOk();

        return $response->viewData('page')['props']['messages'];
    }

    public function test_gm_creates_a_room_with_a_join_code(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $campaign = $world->campaigns()->firstOrFail();

        $this->actingAs($gm)->post(route('rooms.store', $campaign), ['name' => 'Ambush'])->assertRedirect();

        $room = $campaign->rooms()->first();
        $this->assertSame('Ambush', $room->name);
        $this->assertSame(6, strlen($room->code));
    }

    public function test_a_non_owner_cannot_create_a_room(): void
    {
        [, $campaign] = $this->room();

        $this->actingAs(User::factory()->create())
            ->post(route('rooms.store', $campaign), ['name' => 'Sneaky'])->assertForbidden();
    }

    public function test_a_campaign_player_joins_by_code_and_can_view_the_room(): void
    {
        [, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);

        $this->actingAs($player)->post(route('rooms.join', $room->code))->assertRedirect(route('rooms.show', [$room->campaign_id, $room]));
        $this->assertDatabaseHas('room_members', ['room_id' => $room->id, 'user_id' => $player->id]);

        $this->actingAs($player)->get(route('rooms.show', [$room->campaign_id, $room]))
            ->assertInertia(fn (Assert $page) => $page->component('Rooms/Show')->where('isGm', false));
    }

    public function test_a_non_member_cannot_join_a_room(): void
    {
        [, , $room] = $this->room();

        $this->actingAs(User::factory()->create())->post(route('rooms.join', $room->code))->assertForbidden();
        $this->assertDatabaseCount('room_members', 0);
    }

    public function test_the_gm_kicks_a_player_and_clears_their_tokens(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $token = $room->tokens()->create(['x' => 1, 'y' => 1, 'user_id' => $player->id]);
        $gmToken = $room->tokens()->create(['x' => 2, 'y' => 2]);

        $this->actingAs($gm)->delete(route('rooms.kick', [$room, $player]))->assertRedirect();

        $this->assertDatabaseMissing('room_members', ['room_id' => $room->id, 'user_id' => $player->id]);
        $this->assertDatabaseMissing('room_tokens', ['id' => $token->id]);
        $this->assertDatabaseHas('room_tokens', ['id' => $gmToken->id]);
    }

    public function test_a_player_cannot_kick(): void
    {
        [, $campaign, $room] = $this->room();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $campaign->members()->create(['user_id' => $alice->id, 'role' => 'player']);
        $room->members()->attach([$alice->id, $bob->id]);

        $this->actingAs($alice)->delete(route('rooms.kick', [$room, $bob]))->assertForbidden();
    }

    public function test_the_room_payload_includes_ice_servers_for_voice_video(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->get(route('rooms.show', [$room->campaign_id, $room]))->assertInertia(fn (Assert $page) => $page
            ->where('iceServers.0.urls', config('webrtc.ice_servers.0.urls')));
    }

    public function test_a_non_member_cannot_view_a_room(): void
    {
        [, , $room] = $this->room();

        $this->actingAs(User::factory()->create())->get(route('rooms.show', [$room->campaign_id, $room]))->assertForbidden();
    }

    public function test_a_gm_can_place_a_person_token_from_a_people_entry(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $person = $campaign->world->documents()->create([
            'kind' => 'npc', 'title' => 'Lady Merrow', 'summary' => 'The harbourmaster of Saltmere.',
        ]);

        $this->actingAs($gm)->post(route('room-tokens.store', $room), [
            'source' => 'person', 'document_id' => $person->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'document_id' => $person->id, 'kind' => 'person',
            'label' => 'Lady Merrow', 'user_id' => null,
        ]);
    }

    public function test_a_person_token_cannot_reference_a_non_person_entry(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $place = $campaign->world->documents()->create(['kind' => 'location', 'title' => 'Saltmere Docks']);

        $this->actingAs($gm)->post(route('room-tokens.store', $room), [
            'source' => 'person', 'document_id' => $place->id,
        ])->assertSessionHasErrors('document_id');
    }

    public function test_a_gm_placing_a_monster_seeds_hp_and_ac_from_its_stat_block(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $ogre = $campaign->world->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'ogre', 'name' => 'Ogre',
            'fields' => ['block' => ['hp' => '59 (7d10+21)', 'ac' => '11 (hide armor)']],
        ]);

        $this->actingAs($gm)->post(route('room-tokens.store', $room), [
            'source' => 'monster', 'compendium_item_id' => $ogre->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'compendium_item_id' => $ogre->id, 'kind' => 'monster',
            'label' => 'Ogre', 'hp' => 59, 'max_hp' => 59, 'ac' => 11,
        ]);
    }

    public function test_a_player_can_add_a_monster_from_the_gm_shortlist(): void
    {
        [, $campaign, $room] = $this->room();
        $pet = $campaign->world->compendiumItems()->create(['item_type' => 'monster', 'slug' => 'wolf', 'name' => 'Wolf']);
        $room->update(['player_monster_ids' => [$pet->id]]);
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('room-tokens.store', $room), [
            'source' => 'monster', 'compendium_item_id' => $pet->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'user_id' => $player->id, 'compendium_item_id' => $pet->id,
        ]);
    }

    public function test_a_player_cannot_add_a_monster_outside_the_shortlist(): void
    {
        [, $campaign, $room] = $this->room();
        $boss = $campaign->world->compendiumItems()->create(['item_type' => 'monster', 'slug' => 'lich', 'name' => 'Lich']);
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('room-tokens.store', $room), [
            'source' => 'monster', 'compendium_item_id' => $boss->id,
        ])->assertForbidden();

        $this->assertDatabaseMissing('room_tokens', ['room_id' => $room->id, 'compendium_item_id' => $boss->id]);
    }

    public function test_a_monster_tokens_stat_block_is_sent_to_the_gm_only(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $goblin = $campaign->world->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin',
            'is_private' => false, 'is_active' => true,
            'fields' => ['block' => ['ac' => '15', 'hp' => '7 (2d6)', 'abilities' => [
                'str' => 8, 'dex' => 14, 'con' => 10, 'int' => 10, 'wis' => 8, 'cha' => 8,
            ]]],
        ]);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'kind' => 'monster', 'compendium_item_id' => $goblin->id, 'label' => 'Goblin',
        ]);
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $tokenFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['tokens']
        )->firstWhere('id', $token->id);

        $gmToken = $tokenFor($gm);
        $this->assertSame('Goblin', $gmToken['statblock']['name']);
        $this->assertSame(14, $gmToken['statblock']['block']['abilities']['dex']);

        $this->assertNull($tokenFor($player)['statblock'], 'players never receive the raw stat block');
    }

    public function test_a_player_cannot_place_a_person_token(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $person = $room->campaign->world->documents()->create(['kind' => 'npc', 'title' => 'An NPC']);

        $this->actingAs($player)->post(route('room-tokens.store', $room), [
            'source' => 'person', 'document_id' => $person->id,
        ])->assertForbidden();
    }

    public function test_a_player_cannot_move_another_players_token(): void
    {
        [, , $room] = $this->room();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $room->members()->attach([$alice->id, $bob->id]);
        $token = $room->tokens()->create(['user_id' => $alice->id, 'x' => 10, 'y' => 10]);

        $this->actingAs($bob)->put(route('room-tokens.update', $token), ['x' => 90, 'y' => 90])->assertForbidden();
    }

    public function test_a_plain_move_broadcasts_the_new_position_for_instant_sync(): void
    {
        Event::fake([TokenMoved::class, RoomChanged::class]);
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create(['x' => 10, 'y' => 10, 'layer' => 'token']);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['x' => 42, 'y' => 55])->assertRedirect();

        Event::assertDispatched(TokenMoved::class, fn (TokenMoved $e) => $e->tokenId === $token->id
            && $e->x === 42.0 && $e->y === 55.0);
        Event::assertNotDispatched(RoomChanged::class);
    }

    public function test_a_non_position_change_uses_the_scoped_reload(): void
    {
        Event::fake([TokenMoved::class, RoomChanged::class]);
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create(['x' => 1, 'y' => 1, 'hp' => 10, 'max_hp' => 10]);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 4])->assertRedirect();

        Event::assertDispatched(RoomChanged::class);
        Event::assertNotDispatched(TokenMoved::class);
    }

    public function test_moving_a_gm_layer_token_uses_the_scoped_reload_not_a_public_broadcast(): void
    {
        Event::fake([TokenMoved::class, RoomChanged::class]);
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create(['x' => 1, 'y' => 1, 'layer' => 'gm']);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['x' => 30, 'y' => 30])->assertRedirect();

        // A GM-layer position must never fan out on the public channel, or it leaks to players.
        Event::assertNotDispatched(TokenMoved::class);
        Event::assertDispatched(RoomChanged::class);
    }

    public function test_the_gm_can_move_any_token(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $token = $room->tokens()->create(['user_id' => $player->id, 'x' => 10, 'y' => 10]);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['x' => 90, 'y' => 90])->assertRedirect();

        $this->assertSame(90.0, $token->fresh()->x);
    }

    public function test_gm_can_toggle_whether_players_see_the_tracker(): void
    {
        [$gm, , $room] = $this->room();

        $this->assertTrue($room->fresh()->players_see_tracker, 'players see the tracker by default');

        $this->actingAs($gm)->put(route('rooms.update', $room), ['players_see_tracker' => false])->assertRedirect();

        $this->assertFalse($room->fresh()->players_see_tracker);
    }

    public function test_chat_messages_are_attributed_to_the_authors_character(): void
    {
        [, $campaign, $room] = $this->room();
        $player = User::factory()->create(['name' => 'Mike']);
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $campaign->characters()->create(['name' => 'Letharion', 'user_id' => $player->id]);
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.chat', $room), ['body' => 'hello'])->assertRedirect();

        $messages = $this->actingAs($player)->get(route('rooms.show', [$room->campaign_id, $room]))
            ->viewData('page')['props']['messages'];
        $this->assertSame('Letharion', collect($messages)->firstWhere('body', 'hello')['user']);
    }

    public function test_players_only_receive_hp_for_their_own_tokens(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $mine = $room->tokens()->create(['x' => 1, 'y' => 1, 'user_id' => $player->id, 'hp' => 10, 'max_hp' => 12, 'ac' => 15]);
        $enemy = $room->tokens()->create(['x' => 2, 'y' => 2, 'hp' => 50, 'max_hp' => 50, 'ac' => 18]);

        $tokensFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['tokens']
        );

        $playerTokens = $tokensFor($player);
        $this->assertSame(12, $playerTokens->firstWhere('id', $mine->id)['max_hp'], 'sees own HP');
        $this->assertSame(15, $playerTokens->firstWhere('id', $mine->id)['ac'], 'sees own AC');
        $this->assertNull($playerTokens->firstWhere('id', $enemy->id)['max_hp'], 'not the enemy HP');
        $this->assertNull($playerTokens->firstWhere('id', $enemy->id)['ac'], 'not the enemy AC');

        $this->assertSame(50, $tokensFor($gm)->firstWhere('id', $enemy->id)['max_hp'], 'GM sees all HP');
        $this->assertSame(18, $tokensFor($gm)->firstWhere('id', $enemy->id)['ac'], 'GM sees all AC');
    }

    public function test_players_see_party_ally_hp_but_not_ally_ac_or_enemy_hp(): void
    {
        [, $campaign, $room] = $this->room();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $campaign->members()->create(['user_id' => $alice->id, 'role' => 'player']);
        $campaign->members()->create(['user_id' => $bob->id, 'role' => 'player']);
        $room->members()->attach([$alice->id, $bob->id]);
        $ally = $room->tokens()->create(['x' => 1, 'y' => 1, 'user_id' => $bob->id, 'kind' => 'player', 'hp' => 18, 'max_hp' => 24, 'ac' => 16]);
        $enemy = $room->tokens()->create(['x' => 2, 'y' => 2, 'kind' => 'monster', 'hp' => 40, 'max_hp' => 40, 'ac' => 15]);

        $seen = collect(
            $this->actingAs($alice)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['tokens']
        );

        $this->assertSame(24, $seen->firstWhere('id', $ally->id)['max_hp'], 'sees a party ally HP for the bar');
        $this->assertSame(18, $seen->firstWhere('id', $ally->id)['hp']);
        $this->assertNull($seen->firstWhere('id', $ally->id)['ac'], 'but not the ally AC');
        $this->assertNull($seen->firstWhere('id', $enemy->id)['max_hp'], 'never the enemy HP');
    }

    public function test_a_new_room_starts_with_one_active_scene(): void
    {
        [, , $room] = $this->room();

        $this->assertSame(1, $room->scenes()->count());
        $this->assertNotNull($room->active_scene_id);
        $this->assertSame($room->scenes()->first()->id, $room->active_scene_id);
    }

    public function test_the_gm_previews_a_scene_without_moving_players_then_goes_live(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $sceneOne = $room->active_scene_id;
        $room->tokens()->create(['scene_id' => $sceneOne, 'x' => 1, 'y' => 1, 'label' => 'Guard']);

        // Adding a scene does NOT change the live board.
        $this->actingAs($gm)->post(route('rooms.scenes.store', $room), ['name' => 'The Cellar'])->assertRedirect();
        $room->refresh();
        $this->assertSame($sceneOne, $room->active_scene_id, 'adding a scene never goes live');
        $sceneTwo = $room->scenes()->where('id', '!=', $sceneOne)->value('id');

        $tokensFor = fn (User $viewer, array $query = []) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', ['campaign' => $room->campaign_id, 'room' => $room, ...$query]))
                ->viewData('page')['props']['tokens']
        );

        // GM previews scene two (?scene=) — their board is empty, but the player still sees scene one.
        $this->assertCount(0, $tokensFor($gm, ['scene' => $sceneTwo]), 'GM preview shows the empty scene');
        $this->assertSame('Guard', $tokensFor($player)->first()['label'], 'the player is unaffected');

        // A player cannot peek at another scene via the query param.
        $this->assertSame('Guard', $tokensFor($player, ['scene' => $sceneTwo])->first()['label']);

        // Go live → the player now sees scene two (empty).
        $this->actingAs($gm)->post(route('rooms.scenes.activate', $room->scenes()->find($sceneTwo)))->assertRedirect();
        $this->assertCount(0, $tokensFor($player), 'players followed the GM live');
    }

    public function test_previewing_a_scene_serves_that_scenes_map(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $mapImage = Media::create([
            'user_id' => $gm->id, 'world_id' => $campaign->world_id, 'disk' => 'public',
            'path' => 'media/scene1.png', 'filename' => 'scene1.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        $sceneOne = $room->activeScene;
        $sceneOne->update(['image_media_id' => $mapImage->id]);
        $sceneTwo = $room->scenes()->create(['name' => 'Empty']);

        $imageFor = fn (int $sceneId) => $this->actingAs($gm)
            ->get(route('rooms.show', ['campaign' => $room->campaign_id, 'room' => $room, 'scene' => $sceneId]))
            ->viewData('page')['props']['room']['image_url'];

        $this->assertNotNull($imageFor($sceneOne->id), 'scene one serves its map when previewed');
        $this->assertNull($imageFor($sceneTwo->id), 'the empty scene has no map');
    }

    public function test_importing_tokens_from_another_scene_preserves_their_stats(): void
    {
        [$gm, , $room] = $this->room();
        $sceneOne = $room->active_scene_id;
        $sceneTwo = $room->scenes()->create(['name' => 'The Cellar'])->id;
        $source = $room->tokens()->create([
            'scene_id' => $sceneOne, 'x' => 12, 'y' => 34, 'label' => 'Ogre', 'kind' => 'monster',
            'hp' => 21, 'max_hp' => 59, 'ac' => 11, 'conditions' => ['prone'],
            'temp_hp' => 4, 'death_fail' => 1, 'spell_slots_used' => ['1' => 2],
        ]);

        $this->actingAs($gm)->post(route('rooms.scenes.import-tokens', $sceneTwo), ['from' => $sceneOne])
            ->assertRedirect();

        $copy = $room->tokens()->where('scene_id', $sceneTwo)->first();
        $this->assertNotNull($copy, 'a copy landed on the target scene');
        $this->assertNotSame($source->id, $copy->id, 'it is a new token, not a move');
        // Every stat carried over.
        $this->assertSame('Ogre', $copy->label);
        $this->assertSame(21, $copy->hp);
        $this->assertSame(59, $copy->max_hp);
        $this->assertSame(11, $copy->ac);
        $this->assertSame(4, $copy->temp_hp);
        $this->assertSame(1, $copy->death_fail);
        $this->assertSame(['prone'], $copy->conditions);
        $this->assertSame(['1' => 2], $copy->spell_slots_used);
        $this->assertSame(12.0, $copy->x);
        // The source scene is untouched.
        $this->assertSame(1, $room->tokens()->where('scene_id', $sceneOne)->count());
    }

    public function test_a_player_cannot_import_scene_tokens(): void
    {
        [, , $room] = $this->room();
        $sceneOne = $room->active_scene_id;
        $sceneTwo = $room->scenes()->create(['name' => 'B'])->id;
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.scenes.import-tokens', $sceneTwo), ['from' => $sceneOne])
            ->assertForbidden();
    }

    public function test_the_last_scene_cannot_be_deleted(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->delete(route('rooms.scenes.destroy', $room->activeScene))
            ->assertSessionHasErrors('scene');
        $this->assertSame(1, $room->scenes()->count());
    }

    public function test_a_player_cannot_manage_scenes(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.scenes.store', $room), ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame(1, $room->scenes()->count());
    }

    public function test_gm_sets_fog_on_the_active_scene(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->put(route('rooms.update', $room), ['fog_enabled' => true, 'fog' => ['1,1', '2,2']])->assertRedirect();

        // Fog now belongs to the active scene, and the board payload reflects it.
        $scene = $room->activeScene->fresh();
        $this->assertTrue($scene->fog_enabled);
        $this->assertSame(['1,1', '2,2'], $scene->fog);

        $payload = $this->actingAs($gm)->get(route('rooms.show', [$room->campaign_id, $room]))
            ->viewData('page')['props']['room'];
        $this->assertTrue($payload['fog_enabled']);
        $this->assertSame(['1,1', '2,2'], $payload['fog']);
    }

    public function test_a_member_can_post_a_chat_message(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.chat', $room), ['body' => 'Hello table'])->assertRedirect();

        $this->assertDatabaseHas('room_messages', ['room_id' => $room->id, 'user_id' => $player->id, 'body' => 'Hello table']);
    }

    public function test_a_roll_command_is_rolled_server_side(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->post(route('rooms.chat', $room), ['body' => '/roll 1d1+2'])->assertRedirect();

        $message = $room->messages()->latest()->first();
        $this->assertSame(3, $message->roll['total']); // 1d1 is always 1, +2
    }

    public function test_a_non_member_cannot_post_to_the_room(): void
    {
        [, , $room] = $this->room();

        $this->actingAs(User::factory()->create())->post(route('rooms.chat', $room), ['body' => 'hi'])->assertForbidden();
    }

    public function test_a_room_change_broadcasts_on_its_channel(): void
    {
        Event::fake([RoomChanged::class]);
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->put(route('rooms.update', $room), ['round' => 3])->assertRedirect();

        Event::assertDispatched(RoomChanged::class, fn (RoomChanged $event) => $event->roomId === $room->id);
        $this->assertSame(3, $room->fresh()->round);
    }

    public function test_presence_names_use_the_assigned_character(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create(['name' => 'Mike']);
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $campaign->characters()->create(['name' => 'Letharion', 'user_id' => $player->id]);
        $stranger = User::factory()->create(['name' => 'Anon']);

        $this->assertSame('Game Master', Character::roomDisplayName($gm, $room));
        $this->assertSame('Letharion', Character::roomDisplayName($player->fresh(), $room));
        $this->assertSame('Anon', Character::roomDisplayName($stranger, $room));
    }

    public function test_a_member_keeps_a_private_journal(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.journal.store', $room), ['body' => 'trust no one'])
            ->assertRedirect();
        $this->assertDatabaseHas('room_notes', [
            'room_id' => $room->id, 'user_id' => $player->id, 'body' => 'trust no one',
        ]);

        $journalFor = fn (User $viewer) => $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
            ->viewData('page')['props']['journal'];
        $this->assertCount(1, $journalFor($player), 'the author sees their note');
        $this->assertCount(0, $journalFor($gm), 'others never see it');
    }

    public function test_a_player_cannot_delete_another_players_journal_note(): void
    {
        [, $campaign, $room] = $this->room();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $campaign->members()->create(['user_id' => $alice->id, 'role' => 'player']);
        $campaign->members()->create(['user_id' => $bob->id, 'role' => 'player']);
        $room->members()->attach([$alice->id, $bob->id]);
        $note = $room->notes()->create(['user_id' => $alice->id, 'body' => 'mine']);

        $this->actingAs($bob)->delete(route('rooms.journal.destroy', $note))->assertForbidden();
        $this->assertDatabaseHas('room_notes', ['id' => $note->id]);
    }

    public function test_room_changed_event_targets_the_room_channel(): void
    {
        $event = new RoomChanged(9);

        $this->assertSame('rooms.9', $event->broadcastOn()->name);
        $this->assertSame('RoomChanged', $event->broadcastAs());
    }

    public function test_a_gm_drops_a_roster_character_as_a_token(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $character = $campaign->characters()->create([
            'name' => 'Aria', 'user_id' => $player->id, 'hp' => 20, 'max_hp' => 24, 'ac' => 16,
        ]);

        $this->actingAs($gm)->post(route('room-tokens.store', $room), [
            'source' => 'character', 'character_id' => $character->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'character_id' => $character->id, 'user_id' => $player->id,
            'kind' => 'player', 'label' => 'Aria', 'hp' => 20, 'max_hp' => 24, 'ac' => 16,
        ]);
    }

    public function test_a_player_can_only_drop_their_own_character(): void
    {
        [, $campaign, $room] = $this->room();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $room->members()->attach([$alice->id, $bob->id]);
        $hers = $campaign->characters()->create(['name' => 'Hers', 'user_id' => $alice->id]);
        $his = $campaign->characters()->create(['name' => 'His', 'user_id' => $bob->id]);

        $this->actingAs($alice)->post(route('room-tokens.store', $room), [
            'source' => 'character', 'character_id' => $hers->id,
        ])->assertRedirect();
        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'character_id' => $hers->id, 'user_id' => $alice->id,
        ]);

        $this->actingAs($alice)->post(route('room-tokens.store', $room), [
            'source' => 'character', 'character_id' => $his->id,
        ])->assertForbidden();
    }

    public function test_a_player_imports_a_dnd_beyond_character_as_their_own_token(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        Http::fake(['character-service.dndbeyond.com/*' => Http::response([
            'data' => [
                'name' => 'Thistle', 'decorations' => ['avatarUrl' => ''],
                'baseHitPoints' => 24, 'removedHitPoints' => 4, 'armorClass' => 15,
                'race' => ['fullName' => 'Halfling'],
                'classes' => [['level' => 3, 'definition' => ['name' => 'Rogue']]],
            ],
        ])]);

        $this->actingAs($player)->post(route('room-tokens.ddb', $room), [
            'url' => 'https://www.dndbeyond.com/characters/122419601',
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'user_id' => $player->id, 'kind' => 'player',
            'ddb_character_id' => '122419601', 'label' => 'Thistle',
            'max_hp' => 24, 'hp' => 20, 'ac' => 15,
        ]);
    }

    public function test_a_gm_links_an_imported_character_to_a_player(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        Http::fake(['character-service.dndbeyond.com/*' => Http::response([
            'data' => ['name' => 'Vex', 'decorations' => ['avatarUrl' => ''], 'baseHitPoints' => 30, 'armorClass' => 16],
        ])]);

        $this->actingAs($gm)->post(route('room-tokens.ddb', $room), [
            'url' => 'https://www.dndbeyond.com/characters/999',
            'owner_user_id' => $player->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'ddb_character_id' => '999', 'user_id' => $player->id, 'kind' => 'player',
        ]);
    }

    public function test_linking_an_import_to_a_non_member_leaves_it_gm_controlled(): void
    {
        [$gm, , $room] = $this->room();
        $stranger = User::factory()->create();

        Http::fake(['character-service.dndbeyond.com/*' => Http::response([
            'data' => ['name' => 'Vex', 'decorations' => ['avatarUrl' => ''], 'baseHitPoints' => 30],
        ])]);

        $this->actingAs($gm)->post(route('room-tokens.ddb', $room), [
            'url' => 'https://www.dndbeyond.com/characters/999',
            'owner_user_id' => $stranger->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'ddb_character_id' => '999', 'user_id' => null,
        ]);
    }

    public function test_map_options_list_world_media_and_location_maps(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $picture = Media::create([
            'user_id' => $gm->id, 'world_id' => $campaign->world_id, 'disk' => 'public',
            'path' => 'media/pic.png', 'filename' => 'pic.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        $mapImage = Media::create([
            'user_id' => $gm->id, 'world_id' => $campaign->world_id, 'disk' => 'public',
            'path' => 'media/map.png', 'filename' => 'map.png', 'mime' => 'image/png', 'size' => 10,
        ]);
        $location = $campaign->world->documents()->create(['kind' => 'location', 'title' => 'The Docks']);
        $campaign->world->maps()->create([
            'image_media_id' => $mapImage->id, 'document_id' => $location->id, 'name' => 'Docks Map',
        ]);

        $data = $this->actingAs($gm)->getJson(route('rooms.map-options', $room))->json();

        $this->assertTrue(collect($data['media'])->contains(fn ($m) => $m['id'] === $picture->id));
        $this->assertSame('The Docks', $data['locations'][0]['name']);
        $this->assertSame($mapImage->id, $data['locations'][0]['image_media_id']);

        // Searching filters both media (by filename) and location maps (by name).
        $search = $this->actingAs($gm)->getJson(route('rooms.map-options', ['room' => $room, 'search' => 'pic']))->json();
        $this->assertEqualsCanonicalizing(['pic.png'], collect($search['media'])->pluck('filename')->all());
        $this->assertCount(0, $search['locations'], 'the Docks map does not match "pic"');

        $byLocation = $this->actingAs($gm)->getJson(route('rooms.map-options', ['room' => $room, 'search' => 'Docks']))->json();
        $this->assertSame('The Docks', $byLocation['locations'][0]['name']);
        $this->assertCount(0, $byLocation['media'], 'no media filename matches "Docks"');
    }

    public function test_a_malformed_dnd_beyond_link_is_rejected(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->post(route('room-tokens.ddb', $room), ['url' => 'https://example.com/nope'])
            ->assertSessionHasErrors('url');
    }

    public function test_adding_all_to_the_tracker_rolls_initiative_for_everyone(): void
    {
        [$gm, , $room] = $this->room();
        $withInit = $room->tokens()->create(['x' => 1, 'y' => 1, 'initiative' => 17]);
        $withoutInit = $room->tokens()->create(['x' => 2, 'y' => 2]);

        $this->actingAs($gm)->post(route('rooms.tracker', $room), ['action' => 'add_all'])->assertRedirect();

        $withInit->refresh();
        $withoutInit->refresh();
        $this->assertTrue($withInit->in_tracker);
        $this->assertSame(17, $withInit->initiative, 'an existing initiative is preserved');
        $this->assertTrue($withoutInit->in_tracker);
        $this->assertNotNull($withoutInit->initiative, 'a missing initiative is rolled');
        $this->assertGreaterThanOrEqual(1, $withoutInit->initiative);
        $this->assertLessThanOrEqual(20, $withoutInit->initiative);
    }

    public function test_clearing_the_tracker_removes_everyone_and_resets_the_round(): void
    {
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create(['x' => 1, 'y' => 1, 'initiative' => 12, 'in_tracker' => true]);
        $room->update(['active_token_id' => $token->id, 'round' => 4]);

        $this->actingAs($gm)->post(route('rooms.tracker', $room), ['action' => 'clear'])->assertRedirect();

        $this->assertFalse($token->fresh()->in_tracker);
        $room->refresh();
        $this->assertNull($room->active_token_id);
        $this->assertSame(1, $room->round);
    }

    public function test_a_player_cannot_run_tracker_actions(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.tracker', $room), ['action' => 'clear'])->assertForbidden();
    }

    public function test_the_monster_search_filters_the_compendium_by_source_and_cr(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $campaign->world->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin', 'provider' => 'custom', 'origin' => 'ddb',
            'fields' => ['block' => ['cr' => '1/4']],
        ]);
        $campaign->world->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'dragon', 'name' => 'Ancient Dragon', 'provider' => 'custom', 'origin' => 'ddb',
            'fields' => ['block' => ['cr' => '20']],
        ]);
        $campaign->world->compendiumItems()->create([
            'item_type' => 'monster', 'slug' => 'srd-orc', 'name' => 'SRD Orc', 'provider' => 'imported', 'origin' => 'open5e',
            'fields' => ['block' => ['cr' => '1/2']],
        ]);

        // Source filter: only D&D Beyond-origin monsters.
        $ddb = $this->actingAs($gm)->getJson(route('rooms.search', $room).'?source=ddb')->json('results');
        $this->assertEqualsCanonicalizing(['Goblin', 'Ancient Dragon'], collect($ddb)->pluck('name')->all());

        // CR window: only creatures between CR 1 and CR 5.
        $window = $this->actingAs($gm)->getJson(route('rooms.search', $room).'?min_cr=1&max_cr=5')->json('results');
        $this->assertSame([], collect($window)->pluck('name')->all(), 'nothing sits in CR 1–5');

        $low = $this->actingAs($gm)->getJson(route('rooms.search', $room).'?max_cr=1')->json('results');
        $this->assertEqualsCanonicalizing(['Goblin', 'SRD Orc'], collect($low)->pluck('name')->all());
    }

    public function test_the_search_can_return_people_entries(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $campaign->world->documents()->create(['kind' => 'npc', 'title' => 'Lady Merrow', 'summary' => 'Harbourmaster.']);
        $campaign->world->documents()->create(['kind' => 'location', 'title' => 'The Docks']);

        $results = $this->actingAs($gm)->getJson(route('rooms.search', $room).'?kind=person')->json('results');

        $this->assertSame(['Lady Merrow'], collect($results)->pluck('name')->all());
        $this->assertSame('person', $results[0]['type']);
    }

    public function test_a_player_cannot_use_the_gm_search(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->getJson(route('rooms.search', $room))->assertForbidden();
    }

    public function test_elevation_is_saved_on_a_token(): void
    {
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create(['x' => 1, 'y' => 1]);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['elevation' => 30])->assertRedirect();

        $this->assertSame(30, $token->fresh()->elevation);
    }

    public function test_a_player_writes_back_live_combat_state_on_their_token(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $player->id, 'hp' => 30, 'max_hp' => 30,
        ]);

        $this->actingAs($player)->put(route('room-tokens.update', $token), [
            'hp' => 12, 'temp_hp' => 5, 'death_fail' => 1, 'exhaustion' => 2,
            'spell_slots_used' => ['1' => 2, '3' => 1], 'hit_dice_used' => ['6' => 3],
        ])->assertRedirect();

        $token->refresh();
        $this->assertSame(12, $token->hp);
        $this->assertSame(5, $token->temp_hp);
        $this->assertSame(1, $token->death_fail);
        $this->assertSame(2, $token->exhaustion);
        $this->assertSame(['1' => 2, '3' => 1], $token->spell_slots_used);
        $this->assertSame(['6' => 3], $token->hit_dice_used);
    }

    public function test_concentration_is_saved_and_hidden_from_other_players(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $enemy = $room->tokens()->create(['x' => 1, 'y' => 1, 'kind' => 'monster', 'hp' => 20, 'max_hp' => 20]);

        $this->actingAs($gm)->put(route('room-tokens.update', $enemy), ['concentrating_on' => 'Hold Person'])
            ->assertRedirect();
        $this->assertSame('Hold Person', $enemy->fresh()->concentrating_on);

        $tokenFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['tokens']
        )->firstWhere('id', $enemy->id);

        $this->assertSame('Hold Person', $tokenFor($gm)['concentrating_on'], 'the GM sees it');
        $this->assertNull($tokenFor($player)['concentrating_on'], 'a player never sees the enemy concentrating');

        // It can be dropped.
        $this->actingAs($gm)->put(route('room-tokens.update', $enemy), ['concentrating_on' => ''])->assertRedirect();
        $this->assertNull($enemy->fresh()->concentrating_on);
    }

    public function test_combat_state_is_hidden_from_players_who_do_not_own_the_token(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $enemy = $room->tokens()->create([
            'x' => 2, 'y' => 2, 'kind' => 'monster', 'hp' => 40, 'max_hp' => 40,
            'temp_hp' => 8, 'death_fail' => 2, 'spell_slots_used' => ['1' => 1],
        ]);

        $tokenFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['tokens']
        )->firstWhere('id', $enemy->id);

        $seenByPlayer = $tokenFor($player);
        $this->assertNull($seenByPlayer['death_fail'], 'a player never sees enemy death saves');
        $this->assertNull($seenByPlayer['spell_slots_used'], 'nor their spell usage');

        $this->assertSame(2, $tokenFor($gm)['death_fail'], 'the GM sees it');
        $this->assertSame(['1' => 1], $tokenFor($gm)['spell_slots_used']);
    }

    public function test_a_player_persists_sheet_edits_and_notes_on_their_own_token(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $player->id, 'kind' => 'player', 'hp' => 30, 'max_hp' => 30,
        ]);

        $this->actingAs($player)->put(route('room-tokens.update', $token), [
            'sheet_state' => [
                'prepared' => ['Fireball' => true, 'Sleep' => false],
                'equipped' => ['Longsword' => true],
                'attuned' => ['Ring of Protection' => true],
                'currency' => ['gp' => 42, 'sp' => 7],
            ],
            'notes' => 'Owes the innkeeper 5gp.',
        ])->assertRedirect();

        $token->refresh();
        $this->assertSame(
            ['Fireball' => true, 'Sleep' => false],
            $token->sheet_state['prepared'],
        );
        $this->assertSame(['Longsword' => true], $token->sheet_state['equipped']);
        $this->assertSame(['Ring of Protection' => true], $token->sheet_state['attuned']);
        $this->assertSame(['gp' => 42, 'sp' => 7], $token->sheet_state['currency']);
        $this->assertSame('Owes the innkeeper 5gp.', $token->notes);
    }

    public function test_sheet_edits_are_hidden_from_players_who_do_not_own_the_token(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $enemy = $room->tokens()->create([
            'x' => 2, 'y' => 2, 'kind' => 'monster', 'hp' => 40, 'max_hp' => 40,
            'sheet_state' => ['prepared' => ['Hold Person' => true]],
        ]);

        $tokenFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['tokens']
        )->firstWhere('id', $enemy->id);

        $this->assertNull($tokenFor($player)['sheet_state'], 'a player never sees enemy sheet edits');
        $this->assertSame(['prepared' => ['Hold Person' => true]], $tokenFor($gm)['sheet_state']);
    }

    public function test_damaging_a_concentrating_token_posts_a_save_prompt_to_owner_and_gm_only(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $bystander = User::factory()->create();
        $campaign->members()->create(['user_id' => $bystander->id, 'role' => 'player']);
        $room->members()->attach($bystander->id);

        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $player->id, 'kind' => 'player',
            'hp' => 40, 'max_hp' => 40, 'concentrating_on' => 'Hold Person',
        ]);

        // Any HP-reducing update triggers it — here the sheet's damage box (hp 40 → 10 = 30 damage).
        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 10])->assertRedirect();

        $message = $room->messages()->latest('id')->firstOrFail();
        $this->assertSame('concentration', $message->roll['pending']);
        $this->assertSame(15, $message->roll['dc']); // half of 30
        $this->assertSame('Hold Person', $message->roll['spell']);
        $this->assertSame($player->id, $message->to_user_id, 'whispered to the owner (and the GM)');
        $this->assertSame(10, $token->fresh()->hp, 'the damage itself persists');

        $messagesFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['messages']
        )->pluck('id');
        $this->assertTrue($messagesFor($player)->contains($message->id), 'the owner sees the prompt');
        $this->assertTrue($messagesFor($gm)->contains($message->id), 'the GM sees the prompt');
        $this->assertFalse($messagesFor($bystander)->contains($message->id), 'other players do not');
    }

    public function test_temp_hp_counts_as_damage_taken_for_the_concentration_dc(): void
    {
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'kind' => 'monster', 'hp' => 40, 'max_hp' => 40,
            'temp_hp' => 10, 'concentrating_on' => 'Bless',
        ]);

        // 40hp + 10temp → 30hp + 0temp is 20 damage taken, even though temp absorbed some.
        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 30, 'temp_hp' => 0])
            ->assertRedirect();

        $this->assertSame(10, $room->messages()->latest('id')->firstOrFail()->roll['dc']); // half of 20
    }

    public function test_dropping_a_concentrating_token_to_zero_hp_ends_concentration_without_a_prompt(): void
    {
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'kind' => 'monster', 'hp' => 20, 'max_hp' => 20,
            'concentrating_on' => 'Bless',
        ]);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 0])->assertRedirect();

        $this->assertNull($token->fresh()->concentrating_on);
        $this->assertSame(0, $room->messages()->count(), 'no save prompt at 0 HP');
    }

    public function test_healing_or_editing_a_concentrating_token_does_not_post_a_save_prompt(): void
    {
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'kind' => 'monster', 'hp' => 10, 'max_hp' => 20,
            'concentrating_on' => 'Bless',
        ]);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 18])->assertRedirect();

        $this->assertSame(0, $room->messages()->count());
    }

    public function test_rolling_a_concentration_save_resolves_the_prompt_and_reflects_the_outcome(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $player->id, 'kind' => 'player',
            'hp' => 40, 'max_hp' => 40, 'concentrating_on' => 'Bless',
        ]);
        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 10])->assertRedirect();
        $message = $room->messages()->latest('id')->firstOrFail();

        $this->actingAs($player)->put(route('room-messages.concentration', $message))->assertRedirect();

        $message->refresh();
        $this->assertArrayNotHasKey('pending', $message->roll, 'the prompt is now a resolved roll');
        $this->assertSame('CON save vs DC 15', $message->roll['expr']);
        $this->assertIsInt($message->roll['total']);

        // Concentration is kept on a success (total ≥ DC) and dropped on a failure.
        $held = $message->roll['total'] >= 15;
        $this->assertStringContainsString($held ? 'held' : 'broken', $message->roll['detail']);
        $this->assertSame($held ? 'Bless' : null, $token->fresh()->concentrating_on);
    }

    public function test_rolling_a_concentration_save_with_advantage_keeps_the_higher_of_two_dice(): void
    {
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'kind' => 'monster', 'hp' => 40, 'max_hp' => 40,
            'concentrating_on' => 'Bless',
        ]);
        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 10]);
        $message = $room->messages()->latest('id')->firstOrFail();

        $this->actingAs($gm)->put(route('room-messages.concentration', $message), ['mode' => 'advantage'])
            ->assertRedirect();

        $message->refresh();
        $this->assertSame('advantage', $message->roll['mode']);
        $this->assertCount(1, $message->roll['dropped'], 'the lower of the two d20s is dropped');
    }

    public function test_a_player_who_does_not_own_the_token_cannot_roll_its_concentration_save(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $owner = User::factory()->create();
        $room->members()->attach($owner->id);
        $intruder = User::factory()->create();
        $campaign->members()->create(['user_id' => $intruder->id, 'role' => 'player']);
        $room->members()->attach($intruder->id);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $owner->id, 'kind' => 'player',
            'hp' => 40, 'max_hp' => 40, 'concentrating_on' => 'Bless',
        ]);
        $this->actingAs($gm)->put(route('room-tokens.update', $token), ['hp' => 10]);
        $message = $room->messages()->latest('id')->firstOrFail();

        $this->actingAs($intruder)->put(route('room-messages.concentration', $message))->assertForbidden();
        $this->assertSame('concentration', $message->fresh()->roll['pending'], 'the prompt is untouched');
    }

    public function test_spending_a_hit_die_heals_and_posts_a_roll_to_chat(): void
    {
        [, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $character = $campaign->characters()->create([
            'name' => 'Zelen', 'user_id' => $player->id,
            'stats' => ['con' => 12], // +1 modifier
            'sheet' => ['hit_dice' => [['die' => 6, 'total' => 7, 'used' => 0]]],
        ]);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $player->id, 'character_id' => $character->id,
            'kind' => 'player', 'hp' => 10, 'max_hp' => 30,
        ]);

        $this->actingAs($player)->post(route('room-tokens.hit-dice', $token), ['die' => 6])->assertRedirect();

        $token->refresh();
        // A d6 (1–6) + 1 CON heals 2–7, so HP rises above 10 (and never past max).
        $this->assertGreaterThan(10, $token->hp);
        $this->assertLessThanOrEqual(30, $token->hp);
        $this->assertSame([6 => 1], $token->hit_dice_used, 'one d6 is now spent');

        $message = $room->messages()->latest()->first();
        $this->assertNotNull($message->roll, 'the hit-die roll is posted to chat');
        $this->assertSame($token->hp - 10, $message->roll['total'], 'the roll card shows the HP healed');
    }

    public function test_a_hit_die_roll_card_shows_the_full_amount_even_when_capped(): void
    {
        [, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $character = $campaign->characters()->create([
            'name' => 'Zelen', 'user_id' => $player->id, 'stats' => ['con' => 12],
            'sheet' => ['hit_dice' => [['die' => 6, 'total' => 7, 'used' => 0]]],
        ]);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $player->id, 'character_id' => $character->id,
            'kind' => 'player', 'hp' => 29, 'max_hp' => 30,
        ]);

        $this->actingAs($player)->post(route('room-tokens.hit-dice', $token), ['die' => 6])->assertRedirect();

        $this->assertSame(30, $token->fresh()->hp, 'HP still caps at max');
        // d6 (1–6) + 1 CON is at least 2, so the card shows the full roll — not the 1 HP actually applied.
        $this->assertGreaterThanOrEqual(2, $room->messages()->latest()->first()->roll['total']);
    }

    public function test_a_player_cannot_spend_hit_dice_on_another_players_token(): void
    {
        [, $campaign, $room] = $this->room();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $room->members()->attach([$alice->id, $bob->id]);
        $character = $campaign->characters()->create([
            'name' => 'Hers', 'user_id' => $alice->id, 'stats' => ['con' => 10],
            'sheet' => ['hit_dice' => [['die' => 8, 'total' => 5, 'used' => 0]]],
        ]);
        $token = $room->tokens()->create([
            'x' => 1, 'y' => 1, 'user_id' => $alice->id, 'character_id' => $character->id, 'hp' => 5, 'max_hp' => 20,
        ]);

        $this->actingAs($bob)->post(route('room-tokens.hit-dice', $token), ['die' => 8])->assertForbidden();
        $this->assertSame(5, $token->fresh()->hp);
    }

    public function test_conditions_are_saved_on_a_token(): void
    {
        [$gm, , $room] = $this->room();
        $token = $room->tokens()->create(['x' => 1, 'y' => 1]);

        $this->actingAs($gm)->put(route('room-tokens.update', $token), [
            'conditions' => ['prone', 'poisoned'],
        ])->assertRedirect();

        $this->assertSame(['prone', 'poisoned'], $token->fresh()->conditions);
    }

    public function test_a_platform_admin_can_open_a_room_they_do_not_own(): void
    {
        [, , $room] = $this->room();
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get(route('rooms.show', [$room->campaign_id, $room]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Rooms/Show')->where('isGm', true));
    }

    public function test_a_whisper_reaches_only_its_recipient_the_sender_and_the_gm(): void
    {
        [$gm, , $room] = $this->room();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $carol = User::factory()->create(['name' => 'Carol']);
        $room->members()->attach([$alice->id, $bob->id, $carol->id]);

        $this->actingAs($alice)->post(route('rooms.chat', $room), ['body' => '/w Bob the door is trapped'])->assertRedirect();

        $this->assertDatabaseHas('room_messages', [
            'room_id' => $room->id, 'user_id' => $alice->id, 'to_user_id' => $bob->id, 'body' => 'the door is trapped',
        ]);

        $hasWhisper = fn (User $viewer) => collect($this->messagesSeenBy($viewer, $room))
            ->contains(fn (array $message) => $message['body'] === 'the door is trapped');

        $this->assertTrue($hasWhisper($bob), 'recipient should see the whisper');
        $this->assertTrue($hasWhisper($alice), 'sender should see the whisper');
        $this->assertTrue($hasWhisper($gm), 'GM should see the whisper');
        $this->assertFalse($hasWhisper($carol), 'a third player must not see the whisper');
    }

    public function test_a_message_can_be_directed_to_a_player_by_dropdown_recipient(): void
    {
        [$gm, , $room] = $this->room();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $carol = User::factory()->create(['name' => 'Carol']);
        $room->members()->attach([$alice->id, $bob->id, $carol->id]);

        $this->actingAs($alice)->post(route('rooms.chat', $room), [
            'body' => 'meet me at the docks', 'to' => (string) $bob->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_messages', [
            'room_id' => $room->id, 'user_id' => $alice->id, 'to_user_id' => $bob->id,
            'body' => 'meet me at the docks',
        ]);

        $hasMessage = fn (User $viewer) => collect($this->messagesSeenBy($viewer, $room))
            ->contains(fn (array $message) => $message['body'] === 'meet me at the docks');
        $this->assertTrue($hasMessage($bob), 'the chosen recipient sees it');
        $this->assertTrue($hasMessage($gm), 'the GM sees it');
        $this->assertFalse($hasMessage($carol), 'another player in the room never receives it');
    }

    public function test_a_dropdown_message_to_the_gm_reaches_only_the_gm(): void
    {
        [$gm, , $room] = $this->room();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $room->members()->attach([$alice->id, $bob->id]);

        $this->actingAs($alice)->post(route('rooms.chat', $room), ['body' => 'psst GM', 'to' => 'gm'])
            ->assertRedirect();

        $this->assertDatabaseHas('room_messages', [
            'room_id' => $room->id, 'user_id' => $alice->id, 'to_user_id' => $gm->id, 'body' => 'psst GM',
        ]);

        $hasMessage = fn (User $viewer) => collect($this->messagesSeenBy($viewer, $room))
            ->contains(fn (array $message) => $message['body'] === 'psst GM');
        $this->assertTrue($hasMessage($gm));
        $this->assertFalse($hasMessage($bob), 'another player never sees a GM-directed message');
    }

    public function test_a_dropdown_recipient_outside_the_room_is_rejected(): void
    {
        [, , $room] = $this->room();
        $alice = User::factory()->create();
        $room->members()->attach($alice->id);
        $stranger = User::factory()->create();

        $this->actingAs($alice)->post(route('rooms.chat', $room), [
            'body' => 'hello', 'to' => (string) $stranger->id,
        ])->assertSessionHasErrors('to');

        $this->assertDatabaseMissing('room_messages', ['room_id' => $room->id, 'body' => 'hello']);
    }

    public function test_a_gm_roll_is_hidden_from_other_players(): void
    {
        [$gm, , $room] = $this->room();
        $alice = User::factory()->create(['name' => 'Alice']);
        $bob = User::factory()->create(['name' => 'Bob']);
        $room->members()->attach([$alice->id, $bob->id]);

        $this->actingAs($alice)->post(route('rooms.chat', $room), ['body' => '/gmroll 1d1+4'])->assertRedirect();

        $message = $room->messages()->latest()->first();
        $this->assertSame(5, $message->roll['total']);
        $this->assertSame($gm->id, $message->to_user_id);

        $hasRoll = fn (User $viewer) => collect($this->messagesSeenBy($viewer, $room))
            ->contains(fn (array $message) => $message['roll'] !== null);

        $this->assertTrue($hasRoll($gm), 'GM should see the gmroll');
        $this->assertTrue($hasRoll($alice), 'the roller should see their own gmroll');
        $this->assertFalse($hasRoll($bob), 'another player must not see the gmroll');
    }

    public function test_whispering_a_player_not_in_the_room_returns_an_error(): void
    {
        [, , $room] = $this->room();
        $alice = User::factory()->create(['name' => 'Alice']);
        $room->members()->attach($alice->id);

        $this->actingAs($alice)->post(route('rooms.chat', $room), ['body' => '/w Nobody psst'])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseMissing('room_messages', ['room_id' => $room->id, 'body' => 'psst']);
    }

    public function test_a_member_places_an_aoe_template(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.templates.store', $room), [
            'kind' => 'cone', 'x' => 40, 'y' => 55, 'length' => 15, 'angle' => 90, 'color' => '#33aaff',
        ])->assertRedirect();

        $this->assertDatabaseHas('room_templates', [
            'room_id' => $room->id, 'created_by' => $player->id, 'kind' => 'cone',
            'x' => 40, 'y' => 55, 'length' => 15, 'angle' => 90, 'color' => '#33aaff',
        ]);
    }

    public function test_a_gm_layer_template_is_hidden_from_players_and_can_be_revealed(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $template = $room->templates()->create([
            'created_by' => $gm->id, 'kind' => 'circle', 'layer' => 'gm', 'x' => 5, 'y' => 5, 'length' => 20,
        ]);

        $templatesFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['templates']
        );
        $this->assertCount(0, $templatesFor($player), 'a staged template is hidden');
        $this->assertCount(1, $templatesFor($gm), 'the GM sees it');

        // Reveal it → players now receive it.
        $this->actingAs($gm)->put(route('rooms.templates.update', $template), ['layer' => 'token'])->assertRedirect();
        $this->assertCount(1, $templatesFor($player), 'revealed to the table');
    }

    public function test_a_player_cannot_stage_or_reveal_a_template_on_the_gm_layer(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        // A player's requested GM layer is forced to the token layer.
        $this->actingAs($player)->post(route('rooms.templates.store', $room), [
            'kind' => 'circle', 'x' => 10, 'y' => 10, 'length' => 20, 'layer' => 'gm',
        ])->assertRedirect();
        $this->assertDatabaseHas('room_templates', ['room_id' => $room->id, 'layer' => 'token']);

        // And a player can't flip a template's layer at all.
        $staged = $room->templates()->create(['created_by' => $gm->id, 'kind' => 'circle', 'layer' => 'gm', 'x' => 1, 'y' => 1, 'length' => 10]);
        $this->actingAs($player)->put(route('rooms.templates.update', $staged), ['layer' => 'token'])->assertForbidden();
    }

    public function test_a_non_member_cannot_place_a_template(): void
    {
        [, , $room] = $this->room();

        $this->actingAs(User::factory()->create())->post(route('rooms.templates.store', $room), [
            'kind' => 'circle', 'x' => 10, 'y' => 10, 'length' => 20,
        ])->assertForbidden();

        $this->assertDatabaseCount('room_templates', 0);
    }

    public function test_an_unknown_template_shape_is_rejected(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->post(route('rooms.templates.store', $room), [
            'kind' => 'hexagon', 'x' => 10, 'y' => 10, 'length' => 20,
        ])->assertSessionHasErrors('kind');

        $this->assertDatabaseCount('room_templates', 0);
    }

    public function test_the_creator_removes_their_own_template_but_another_player_cannot(): void
    {
        [, , $room] = $this->room();
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $room->members()->attach([$alice->id, $bob->id]);
        $template = $room->templates()->create([
            'created_by' => $alice->id, 'kind' => 'circle', 'x' => 5, 'y' => 5, 'length' => 20,
        ]);

        $this->actingAs($bob)->delete(route('rooms.templates.destroy', $template))->assertForbidden();
        $this->assertDatabaseHas('room_templates', ['id' => $template->id]);

        $this->actingAs($alice)->delete(route('rooms.templates.destroy', $template))->assertRedirect();
        $this->assertDatabaseMissing('room_templates', ['id' => $template->id]);
    }

    public function test_the_gm_can_remove_a_players_template_and_clear_them_all(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $first = $room->templates()->create(['created_by' => $player->id, 'kind' => 'line', 'x' => 1, 'y' => 1, 'length' => 30]);
        $room->templates()->create(['created_by' => $player->id, 'kind' => 'square', 'x' => 2, 'y' => 2, 'length' => 15]);

        $this->actingAs($gm)->delete(route('rooms.templates.destroy', $first))->assertRedirect();
        $this->assertDatabaseMissing('room_templates', ['id' => $first->id]);

        $this->actingAs($gm)->delete(route('rooms.templates.clear', $room))->assertRedirect();
        $this->assertDatabaseCount('room_templates', 0);
    }

    public function test_a_player_cannot_clear_all_templates(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $room->templates()->create(['created_by' => $player->id, 'kind' => 'circle', 'x' => 1, 'y' => 1, 'length' => 20]);

        $this->actingAs($player)->delete(route('rooms.templates.clear', $room))->assertForbidden();
        $this->assertDatabaseCount('room_templates', 1);
    }

    public function test_the_room_payload_marks_removable_templates_per_viewer(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $template = $room->templates()->create([
            'created_by' => $player->id, 'kind' => 'circle', 'x' => 5, 'y' => 5, 'length' => 20, 'color' => '#d8a94a',
        ]);

        $templateFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['templates']
        )->firstWhere('id', $template->id);

        $this->assertTrue($templateFor($player)['can_remove'], 'the creator may remove it');
        $this->assertTrue($templateFor($gm)['can_remove'], 'the GM may remove any');
        $this->assertSame(20.0, $templateFor($player)['length']);

        $other = User::factory()->create();
        $room->members()->attach($other->id);
        $this->assertFalse($templateFor($other)['can_remove'], 'a bystander may not remove it');
    }

    public function test_gm_layer_tokens_and_drawings_are_hidden_from_players(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $staged = $room->tokens()->create(['x' => 1, 'y' => 1, 'layer' => 'gm', 'label' => 'Ambush']);
        $onBoard = $room->tokens()->create(['x' => 2, 'y' => 2, 'layer' => 'token', 'label' => 'Guard']);
        $room->drawings()->create(['created_by' => $gm->id, 'kind' => 'rect', 'layer' => 'gm', 'points' => [['x' => 1, 'y' => 1], ['x' => 5, 'y' => 5]]]);

        $payloadFor = fn (User $viewer) => $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
            ->viewData('page')['props'];

        $playerView = $payloadFor($player);
        $this->assertSame(['Guard'], collect($playerView['tokens'])->pluck('label')->all(), 'player sees only the token-layer token');
        $this->assertCount(0, $playerView['drawings'], 'player sees no GM-layer drawing');

        $gmView = $payloadFor($gm);
        $this->assertEqualsCanonicalizing(['Ambush', 'Guard'], collect($gmView['tokens'])->pluck('label')->all(), 'GM sees both');
        $this->assertCount(1, $gmView['drawings']);
        $this->assertSame($staged->id, collect($gmView['tokens'])->firstWhere('label', 'Ambush')['id']);
    }

    public function test_a_gm_stages_a_monster_on_the_gm_layer_when_adding_it(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $ogre = $campaign->world->compendiumItems()->create(['item_type' => 'monster', 'slug' => 'ogre', 'name' => 'Ogre']);

        $this->actingAs($gm)->post(route('room-tokens.store', $room), [
            'source' => 'monster', 'compendium_item_id' => $ogre->id, 'layer' => 'gm',
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'compendium_item_id' => $ogre->id, 'layer' => 'gm',
        ]);
    }

    public function test_a_player_added_token_ignores_a_requested_gm_layer(): void
    {
        [, $campaign, $room] = $this->room();
        $pet = $campaign->world->compendiumItems()->create(['item_type' => 'monster', 'slug' => 'wolf', 'name' => 'Wolf']);
        $room->update(['player_monster_ids' => [$pet->id]]);
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('room-tokens.store', $room), [
            'source' => 'monster', 'compendium_item_id' => $pet->id, 'layer' => 'gm',
        ])->assertRedirect();

        $this->assertDatabaseHas('room_tokens', [
            'room_id' => $room->id, 'compendium_item_id' => $pet->id, 'user_id' => $player->id, 'layer' => 'token',
        ]);
    }

    public function test_a_player_cannot_move_a_token_to_the_gm_layer(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $token = $room->tokens()->create(['x' => 1, 'y' => 1, 'user_id' => $player->id, 'layer' => 'token']);

        $this->actingAs($player)->put(route('room-tokens.update', $token), ['layer' => 'gm'])->assertRedirect();

        $this->assertSame('token', $token->fresh()->layer, 'the layer change is ignored for players');
    }

    public function test_the_gm_draws_an_annotation_the_table_sees(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);

        $this->actingAs($gm)->post(route('rooms.drawings.store', $room), [
            'kind' => 'freehand',
            'points' => [['x' => 10, 'y' => 10], ['x' => 20, 'y' => 25]],
            'color' => '#33aaff',
        ])->assertRedirect();

        $this->assertDatabaseHas('room_drawings', [
            'room_id' => $room->id, 'created_by' => $gm->id, 'kind' => 'freehand', 'color' => '#33aaff',
        ]);

        $drawings = collect(
            $this->actingAs($player)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['drawings']
        );
        $this->assertCount(1, $drawings, 'players see GM drawings');
        $this->assertSame([['x' => 10, 'y' => 10], ['x' => 20, 'y' => 25]], $drawings->first()['points']);
    }

    public function test_a_player_cannot_draw_or_clear(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.drawings.store', $room), [
            'kind' => 'line', 'points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
        ])->assertForbidden();
        $this->assertDatabaseCount('room_drawings', 0);

        $room->drawings()->create(['created_by' => $gm->id, 'kind' => 'rect', 'points' => [['x' => 1, 'y' => 1], ['x' => 5, 'y' => 5]]]);
        $this->actingAs($player)->delete(route('rooms.drawings.clear', $room))->assertForbidden();
        $this->assertDatabaseCount('room_drawings', 1);
    }

    public function test_an_unknown_drawing_shape_is_rejected(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->post(route('rooms.drawings.store', $room), [
            'kind' => 'spiral', 'points' => [['x' => 1, 'y' => 1], ['x' => 2, 'y' => 2]],
        ])->assertSessionHasErrors('kind');
        $this->assertDatabaseCount('room_drawings', 0);
    }

    public function test_the_gm_shares_a_handout_the_whole_table_sees(): void
    {
        [$gm, $campaign, $room] = $this->room();
        $player = User::factory()->create();
        $campaign->members()->create(['user_id' => $player->id, 'role' => 'player']);
        $room->members()->attach($player->id);
        $image = Media::create([
            'user_id' => $gm->id, 'world_id' => $campaign->world_id, 'disk' => 'public',
            'path' => 'media/letter.png', 'filename' => 'letter.png', 'mime' => 'image/png', 'size' => 10,
        ]);

        $this->actingAs($gm)->post(route('rooms.handouts.store', $room), [
            'title' => 'A sealed letter', 'body' => 'You may open it.', 'image_media_id' => $image->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('room_handouts', [
            'room_id' => $room->id, 'created_by' => $gm->id, 'title' => 'A sealed letter',
            'body' => 'You may open it.', 'image_media_id' => $image->id,
        ]);

        $handoutFor = fn (User $viewer) => collect(
            $this->actingAs($viewer)->get(route('rooms.show', [$room->campaign_id, $room]))
                ->viewData('page')['props']['handouts']
        )->first();

        $this->assertSame('A sealed letter', $handoutFor($player)['title'], 'a player sees the handout');
        $this->assertFalse($handoutFor($player)['can_manage'], 'but cannot manage it');
        $this->assertTrue($handoutFor($gm)['can_manage'], 'the GM can manage it');
    }

    public function test_a_handout_needs_a_note_or_an_image(): void
    {
        [$gm, , $room] = $this->room();

        $this->actingAs($gm)->post(route('rooms.handouts.store', $room), ['title' => 'Just a title'])
            ->assertSessionHasErrors('body');

        $this->assertDatabaseCount('room_handouts', 0);
    }

    public function test_a_player_cannot_share_a_handout(): void
    {
        [, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);

        $this->actingAs($player)->post(route('rooms.handouts.store', $room), ['body' => 'sneaky'])
            ->assertForbidden();

        $this->assertDatabaseCount('room_handouts', 0);
    }

    public function test_only_the_gm_can_delete_a_handout(): void
    {
        [$gm, , $room] = $this->room();
        $player = User::factory()->create();
        $room->members()->attach($player->id);
        $handout = $room->handouts()->create(['created_by' => $gm->id, 'body' => 'clue']);

        $this->actingAs($player)->delete(route('rooms.handouts.destroy', $handout))->assertForbidden();
        $this->assertDatabaseHas('room_handouts', ['id' => $handout->id]);

        $this->actingAs($gm)->delete(route('rooms.handouts.destroy', $handout))->assertRedirect();
        $this->assertDatabaseMissing('room_handouts', ['id' => $handout->id]);
    }
}
