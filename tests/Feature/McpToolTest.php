<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mcp\Servers\WorldbuilderServer;
use App\Mcp\Tools\CreateCompendiumItem;
use App\Mcp\Tools\ListWorlds;
use App\Mcp\Tools\SearchCompendium;
use App\Models\CampaignCompendiumItem;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpToolTest extends TestCase
{
    use RefreshDatabase;

    private function worldFor(User $user, string $name): World
    {
        return $user->worlds()->create(['name' => $name, 'visibility' => 'private']);
    }

    private function itemIn(World $world, User $user, string $name, string $type = 'monster'): CampaignCompendiumItem
    {
        return $world->compendiumItems()->create([
            'user_id' => $user->id,
            'item_type' => $type,
            'slug' => \Illuminate\Support\Str::slug($name).'-abcd',
            'name' => $name,
            'provider' => 'custom',
            'fields' => [],
            'document' => '',
        ]);
    }

    public function test_the_mcp_endpoint_rejects_unauthenticated_requests(): void
    {
        $this->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ])->assertUnauthorized();
    }

    public function test_list_worlds_returns_only_the_users_own_worlds(): void
    {
        $user = User::factory()->create();
        $this->worldFor($user, 'Saltmere');

        $intruderWorld = $this->worldFor(User::factory()->create(), 'Forbidden Vale');

        WorldbuilderServer::actingAs($user)
            ->tool(ListWorlds::class)
            ->assertOk()
            ->assertSee('Saltmere')
            ->assertDontSee('Forbidden Vale');
    }

    public function test_search_compendium_returns_matching_entries_in_an_owned_world(): void
    {
        $user = User::factory()->create();
        $world = $this->worldFor($user, 'Saltmere');
        $this->itemIn($world, $user, 'Drowned Bell Wraith');
        $this->itemIn($world, $user, 'Harbour Cat');

        WorldbuilderServer::actingAs($user)
            ->tool(SearchCompendium::class, ['world_id' => $world->id, 'query' => 'Wraith'])
            ->assertOk()
            ->assertSee('Drowned Bell Wraith')
            ->assertDontSee('Harbour Cat');
    }

    public function test_search_compendium_refuses_a_world_the_user_cannot_access(): void
    {
        $user = User::factory()->create();
        $otherWorld = $this->worldFor(User::factory()->create(), 'Forbidden Vale');
        $this->itemIn($otherWorld, $otherWorld->owner, 'Secret Beast');

        WorldbuilderServer::actingAs($user)
            ->tool(SearchCompendium::class, ['world_id' => $otherWorld->id, 'query' => 'Secret'])
            ->assertSee('World not found')
            ->assertDontSee('Secret Beast');
    }

    public function test_create_compendium_item_creates_an_entry_in_an_owned_world(): void
    {
        $user = User::factory()->create();
        $world = $this->worldFor($user, 'Saltmere');

        WorldbuilderServer::actingAs($user)
            ->tool(CreateCompendiumItem::class, [
                'world_id' => $world->id,
                'name' => 'Tide Priestess',
                'item_type' => 'monster',
            ])
            ->assertOk()
            ->assertSee('Tide Priestess');

        $item = $world->compendiumItems()->firstOrFail();
        $this->assertSame('Tide Priestess', $item->name);
        $this->assertSame('monster', $item->item_type);
        $this->assertSame($user->id, $item->user_id);
    }

    public function test_create_compendium_item_refuses_a_world_the_user_cannot_edit(): void
    {
        $user = User::factory()->create();
        $otherWorld = $this->worldFor(User::factory()->create(), 'Forbidden Vale');

        WorldbuilderServer::actingAs($user)
            ->tool(CreateCompendiumItem::class, [
                'world_id' => $otherWorld->id,
                'name' => 'Injected Entry',
                'item_type' => 'monster',
            ])
            ->assertSee('permission');

        $this->assertSame(0, CampaignCompendiumItem::where('name', 'Injected Entry')->count());
    }

    public function test_create_compendium_item_rejects_an_unknown_item_type(): void
    {
        $user = User::factory()->create();
        $world = $this->worldFor($user, 'Saltmere');

        WorldbuilderServer::actingAs($user)
            ->tool(CreateCompendiumItem::class, [
                'world_id' => $world->id,
                'name' => 'Nonsense',
                'item_type' => 'not-a-real-type',
            ])
            ->assertHasErrors();

        $this->assertSame(0, $world->compendiumItems()->count());
    }
}
