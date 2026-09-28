<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignCompendiumItem;
use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Models\RuleSystem;
use App\Models\User;
use App\Support\DemoRuleSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RuleSystemPrerequisiteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: CompendiumSource, 1: CompendiumItem}
     */
    private function librarySpell(string $slug = 'firebolt', string $name = 'Firebolt'): array
    {
        $source = CompendiumSource::create(['key' => "src-{$slug}", 'name' => 'Test Spells', 'provider' => 'open5e', 'item_type' => 'spell', 'api_url' => 'https://example.test/spells', 'enabled' => true]);
        $item = CompendiumItem::create([
            'source_id' => $source->id, 'item_type' => 'spell', 'slug' => $slug, 'name' => $name,
            'summary' => 'A bolt.', 'document' => '', 'fields' => ['level' => 'Cantrip'], 'data' => [], 'visible' => true,
        ]);

        return [$source, $item];
    }

    public function test_importing_prerequisites_copies_the_sources_entries_into_the_world(): void
    {
        [$source, $spell] = $this->librarySpell();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);
        $system = $world->ruleSystems()->create([
            'name' => 'Homebrew',
            'settings' => array_merge(DemoRuleSystem::defaultSettings(), ['compendium_sources' => [$source->id]]),
        ]);

        $this->actingAs($gm)->post(route('rule-systems.import-compendium', $system))->assertRedirect();

        $this->assertDatabaseHas('campaign_compendium_items', [
            'world_id' => $world->id, 'item_type' => 'spell', 'slug' => 'firebolt',
            'provider' => 'imported', 'source_item_id' => $spell->id,
        ]);
    }

    public function test_cloning_a_template_imports_its_prerequisites_and_remaps_node_grants(): void
    {
        [$source, $spell] = $this->librarySpell();
        $admin = User::factory()->create(['is_admin' => true]);
        $template = RuleSystem::create([
            'user_id' => $admin->id, 'is_template' => true, 'name' => 'Template',
            'settings' => array_merge(DemoRuleSystem::defaultSettings(), ['compendium_sources' => [$source->id]]),
        ]);
        $template->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $template->webs()->create(['name' => 'Core']);
        // A template node that grants the GLOBAL library spell (templates reference the library, not a world).
        $web->nodes()->create(['key' => 'fb', 'name' => 'Firebolt', 'kind' => 'minor', 'cost' => 1, 'effects' => [['type' => 'spell', 'item_id' => $spell->id]]]);

        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Aeldra', 'visibility' => 'private']);

        $this->actingAs($gm)->post(route('rule-systems.clone', [$world, $template]))->assertRedirect();

        // The prerequisite entry was imported into the world…
        $worldSpell = CampaignCompendiumItem::where('world_id', $world->id)->where('source_item_id', $spell->id)->sole();
        // …and the cloned node's grant now points at the world copy, not the library id.
        $copy = $world->ruleSystems()->sole();
        $clonedNode = $copy->webs()->first()->nodes()->where('key', 'fb')->sole();
        $this->assertSame([['type' => 'spell', 'item_id' => $worldSpell->id]], $clonedNode->effects);
    }

    public function test_a_template_node_grant_must_come_from_a_prerequisite_source(): void
    {
        [$source, $spell] = $this->librarySpell('firebolt', 'Firebolt');
        [, $stray] = $this->librarySpell('shield', 'Shield'); // a library spell NOT marked as a prerequisite

        $admin = User::factory()->create(['is_admin' => true]);
        $template = RuleSystem::create([
            'user_id' => $admin->id, 'is_template' => true, 'name' => 'Template',
            'settings' => array_merge(DemoRuleSystem::defaultSettings(), ['compendium_sources' => [$source->id]]),
        ]);
        $template->nodeKinds()->create(['key' => 'minor', 'label' => 'Minor', 'default_cost' => 1]);
        $web = $template->webs()->create(['name' => 'Core']);
        $node = $web->nodes()->create(['key' => 'n', 'name' => 'N', 'kind' => 'minor', 'cost' => 1]);

        $this->actingAs($admin)->put(route('talent-nodes.update', $node->id), [
            'name' => 'N', 'kind' => 'minor', 'x' => 0, 'y' => 0, 'gate_level' => 1, 'cost' => 1,
            'effects' => [
                ['type' => 'spell', 'item_id' => $spell->id],   // in a prerequisite source → kept
                ['type' => 'spell', 'item_id' => $stray->id],   // not a prerequisite → dropped
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([['type' => 'spell', 'item_id' => $spell->id]], $node->refresh()->effects);
    }
}
