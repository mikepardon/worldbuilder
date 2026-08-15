<?php

namespace Tests\Feature;

use App\Models\CampaignCompendiumItem;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GeneratorTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(array $items): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['items' => $items])]],
        ], 200)]);
    }

    public function test_the_generators_page_renders_for_the_gm(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('generators.index', $world))->assertInertia(fn (Assert $page) => $page
            ->component('Worlds/Generators')
            ->where('generators.0.key', 'names')
            ->has('creditsRemaining'));
    }

    public function test_the_prompt_is_grounded_in_the_worlds_style_and_existing_entries(): void
    {
        $this->fakeAi([['title' => 'Vell the Younger', 'detail' => 'Nephew of the harbourmaster.']]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'Saltmere', 'visibility' => 'public', 'setting' => 'grimdark nautical fantasy',
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'kind' => 'location', 'title' => 'The Deepmarket',
            'slug' => 'the-deepmarket', 'summary' => 'a sunken bazaar', 'is_private' => false,
        ]);

        $this->actingAs($gm)->postJson(route('generators.run', $world), ['kind' => 'npc', 'count' => 1])->assertOk();

        Http::assertSent(function ($request) {
            $system = $request->data()['system'] ?? '';

            return str_contains($system, 'grimdark nautical fantasy')
                && str_contains($system, 'The Deepmarket');
        });
    }

    public function test_generating_returns_items_and_spends_one_credit(): void
    {
        $this->fakeAi([['title' => 'Aria Saltcaller', 'detail' => 'A smuggler-turned-informant.']]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->postJson(route('generators.run', $world), ['kind' => 'npc', 'count' => 3])
            ->assertOk()
            ->assertJson([
                'items' => [['title' => 'Aria Saltcaller', 'detail' => 'A smuggler-turned-informant.']],
                'creditsRemaining' => 4, // free plan grants 5/day
            ]);

        $this->assertSame(1, $gm->fresh()->daily_ai_used);
    }

    public function test_generating_is_refused_and_sends_nothing_when_out_of_credits(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake();

        $gm = User::factory()->create([
            'daily_ai_used' => 5,
            'daily_ai_reset_on' => now()->toDateString(),
            'ai_credit_balance' => 0,
        ]);
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->postJson(route('generators.run', $world), ['kind' => 'names'])
            ->assertStatus(402)
            ->assertJson(['outOfCredits' => true]);

        Http::assertNothingSent();
    }

    public function test_an_unknown_generator_kind_is_rejected(): void
    {
        $this->fakeAi([['title' => 'X', 'detail' => '']]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->postJson(route('generators.run', $world), ['kind' => 'spaceships'])
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
    }

    public function test_a_non_manager_cannot_use_the_generators(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson(route('generators.run', $world), ['kind' => 'names'])
            ->assertForbidden();
    }

    public function test_a_result_can_be_turned_into_an_entry(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->post(route('generators.create', $world), [
                'kind' => 'npc',
                'title' => 'Aria Saltcaller',
                'detail' => 'A smuggler-turned-informant.',
            ])
            ->assertRedirect();

        $document = Document::where('world_id', $world->id)->where('kind', 'npc')->sole();
        $this->assertSame('Aria Saltcaller', $document->title);
        $this->assertStringContainsString('A smuggler-turned-informant.', (string) $document->content);
    }

    public function test_generating_passes_through_a_structured_stat_block(): void
    {
        $this->fakeAi([[
            'title' => 'Brackish Hulk', 'detail' => 'A barnacled brute.',
            'description' => 'It rose from the tide, dripping and furious.',
            'block' => [
                'ac' => '15', 'hp' => '82 (11d10 + 22)', 'cr' => '5',
                'abilities' => ['str' => 19, 'dex' => 8, 'con' => 15, 'int' => 6, 'wis' => 10, 'cha' => 5],
                'actions' => [['name' => 'Slam', 'desc' => '+7 to hit, 2d8+4 bludgeoning.']],
            ],
        ]]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->postJson(route('generators.run', $world), [
                'kind' => 'npc', 'count' => 1, 'options' => ['description' => true, 'statblock' => true],
            ])
            ->assertOk()
            ->assertJson(['items' => [[
                'description' => 'It rose from the tide, dripping and furious.',
                'block' => [
                    'ac' => '15', 'hp' => '82 (11d10 + 22)', 'cr' => '5',
                    'abilities' => ['str' => 19, 'dex' => 8],
                    'actions' => [['name' => 'Slam', 'desc' => '+7 to hit, 2d8+4 bludgeoning.']],
                ],
            ]]]);
    }

    public function test_a_generation_is_saved_as_a_reopenable_batch(): void
    {
        $this->fakeAi([['title' => 'Aria Saltcaller', 'detail' => 'A smuggler-turned-informant.']]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $response = $this->actingAs($gm)
            ->postJson(route('generators.run', $world), ['kind' => 'npc', 'count' => 1, 'context' => 'dockside'])
            ->assertOk()
            ->assertJsonPath('batch.kind', 'npc')
            ->assertJsonPath('batch.count', 1)
            ->assertJsonPath('batches.0.context', 'dockside');

        $batchId = $response->json('batch.id');
        $this->assertDatabaseHas('generator_batches', [
            'id' => $batchId, 'world_id' => $world->id, 'kind' => 'npc', 'context' => 'dockside',
        ]);

        // Reopen it.
        $this->actingAs($gm)
            ->getJson(route('generators.batches.show', ['world' => $world->id, 'batch' => $batchId]))
            ->assertOk()
            ->assertJsonPath('items.0.title', 'Aria Saltcaller');
    }

    public function test_a_batch_can_be_edited_and_deleted(): void
    {
        $this->fakeAi([['title' => 'Aria Saltcaller', 'detail' => 'A smuggler.']]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $batchId = $this->actingAs($gm)
            ->postJson(route('generators.run', $world), ['kind' => 'npc', 'count' => 1])
            ->json('batch.id');

        $this->actingAs($gm)
            ->putJson(route('generators.batches.update', ['world' => $world->id, 'batch' => $batchId]), [
                'items' => [['title' => 'Aria the Informant', 'detail' => 'Now working for the crown.']],
            ])
            ->assertOk()
            ->assertJsonPath('items.0.title', 'Aria the Informant');

        $this->actingAs($gm)
            ->deleteJson(route('generators.batches.destroy', ['world' => $world->id, 'batch' => $batchId]))
            ->assertOk();

        $this->assertDatabaseMissing('generator_batches', ['id' => $batchId]);
    }

    public function test_a_batch_from_another_world_cannot_be_opened(): void
    {
        $this->fakeAi([['title' => 'X', 'detail' => '']]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'A', 'visibility' => 'public']);
        $other = $gm->worlds()->create(['name' => 'B', 'visibility' => 'public']);

        $batchId = $this->actingAs($gm)
            ->postJson(route('generators.run', $world), ['kind' => 'npc', 'count' => 1])
            ->json('batch.id');

        $this->actingAs($gm)
            ->getJson(route('generators.batches.show', ['world' => $other->id, 'batch' => $batchId]))
            ->assertNotFound();
    }

    public function test_an_entry_import_carries_description_meta_and_stat_block_into_the_body(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->post(route('generators.create', $world), [
                'kind' => 'item',
                'title' => 'Tidecaller Blade',
                'detail' => 'A cutlass that hums near salt water.',
                'description' => 'Forged from a drowned sailor’s vow.',
                'meta' => ['Type' => 'weapon', 'Rarity' => 'rare'],
            ])
            ->assertRedirect();

        $document = Document::where('world_id', $world->id)->where('kind', 'item')->sole();
        $content = (string) $document->content;
        $this->assertStringContainsString('Forged from a drowned sailor’s vow.', $content);
        $this->assertStringContainsString('Type: weapon', $content);
        $this->assertStringContainsString('Rarity: rare', $content);
        $this->assertSame('A cutlass that hums near salt water.', $document->summary);
    }

    public function test_item_generation_includes_magic_item_mechanics(): void
    {
        $this->fakeAi([[
            'title' => 'Tidecaller Blade', 'detail' => 'A humming cutlass.', 'description' => 'Forged from a vow.',
            'item' => [
                'category' => 'Weapon', 'rarity' => 'Rare', 'attunement' => 'Yes',
                'mechanics' => 'You gain a +1 bonus to attack and damage rolls made with this weapon.',
            ],
        ]]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->postJson(route('generators.run', $world), ['kind' => 'item', 'count' => 1])
            ->assertOk()
            ->assertJsonPath('items.0.item.category', 'Weapon')
            ->assertJsonPath('items.0.item.rarity', 'Rare')
            ->assertJsonPath('items.0.item.mechanics', 'You gain a +1 bonus to attack and damage rolls made with this weapon.');
    }

    public function test_importing_an_item_creates_a_magic_item_with_structured_fields(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->post(route('generators.statblock', $world), [
                'type' => 'magicitem',
                'title' => 'Tidecaller Blade',
                'detail' => 'A humming cutlass.',
                'description' => 'Forged from a drowned sailor’s vow.',
                'item' => [
                    'category' => 'Weapon', 'rarity' => 'Rare', 'attunement' => 'Yes',
                    'mechanics' => 'You gain a +1 bonus to attack and damage rolls made with this weapon.',
                ],
            ])
            ->assertRedirect();

        $item = CampaignCompendiumItem::where('world_id', $world->id)->sole();
        $this->assertSame('magicitem', $item->item_type);
        $this->assertSame('Weapon', $item->fields['category']);
        $this->assertSame('Rare', $item->fields['rarity']);
        $this->assertStringContainsString('+1 bonus to attack', $item->fields['description']);
        $this->assertStringContainsString('Forged from a drowned sailor', $item->fields['description']);
        // Rendered into the compendium markdown via the shared schema renderer.
        $this->assertStringContainsString('Item Type', (string) $item->document);
        $this->assertStringContainsString('+1 bonus to attack', (string) $item->document);
    }

    public function test_a_result_can_be_imported_as_a_compendium_stat_block(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->post(route('generators.statblock', $world), [
                'type' => 'monster',
                'title' => 'Brackish Hulk',
                'detail' => 'A barnacled brute.',
                'block' => [
                    'ac' => '15', 'hp' => '82 (11d10 + 22)', 'cr' => '5',
                    'abilities' => ['str' => 19, 'dex' => 8, 'con' => 15],
                    'actions' => [['name' => 'Slam', 'desc' => '+7 to hit.']],
                ],
            ])
            ->assertRedirect();

        $item = CampaignCompendiumItem::where('world_id', $world->id)->sole();
        $this->assertSame('monster', $item->item_type);
        $this->assertSame('Brackish Hulk', $item->name);
        $this->assertTrue($item->is_private);
        $this->assertSame('custom', $item->provider);
        // The structured block is persisted and rendered into the item's markdown.
        $this->assertSame('15', $item->fields['block']['ac']);
        $this->assertSame(19, $item->fields['block']['abilities']['str']);
        $this->assertStringContainsString('Brackish Hulk', (string) $item->document);
        $this->assertStringContainsString('Slam', (string) $item->document);
    }

    public function test_an_unknown_stat_block_type_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)
            ->post(route('generators.statblock', $world), ['type' => 'spaceship', 'title' => 'X'])
            ->assertSessionHasErrors('type');

        $this->assertSame(0, CampaignCompendiumItem::count());
    }
}
