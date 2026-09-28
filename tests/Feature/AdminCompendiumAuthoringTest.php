<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Models\User;
use Database\Seeders\CompendiumSourceSeeder;
use Database\Seeders\WorldbuilderContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCompendiumAuthoringTest extends TestCase
{
    use RefreshDatabase;

    private function worldbuilderSource(string $type = 'ability'): CompendiumSource
    {
        return CompendiumSource::create([
            'key' => "worldbuilder-{$type}", 'name' => ucfirst($type).' (ascendancy)',
            'provider' => 'ascendancy', 'item_type' => $type, 'api_url' => '', 'enabled' => true,
        ]);
    }

    public function test_an_admin_creates_a_blank_worldbuilder_entry_and_opens_it(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $source = $this->worldbuilderSource('ability');

        $response = $this->actingAs($admin)->post(route('admin.compendium.items.store', $source));

        $item = $source->items()->sole();
        $this->assertSame('ability', $item->item_type);
        $response->assertRedirect(route('admin.compendium.items.edit', $item));
    }

    public function test_importing_a_worldbuilder_source_is_refused(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $source = $this->worldbuilderSource('ability');

        $this->actingAs($admin)->post(route('admin.compendium.import', $source))->assertRedirect();

        $this->assertSame(0, $source->runs()->count());
    }

    public function test_the_worldbuilder_library_seeds_starter_feats_and_abilities(): void
    {
        $this->seed(CompendiumSourceSeeder::class);
        $this->seed(WorldbuilderContentSeeder::class);

        $this->assertGreaterThanOrEqual(4, CompendiumItem::where('item_type', 'feat')->whereHas('source', fn ($q) => $q->where('provider', 'ascendancy'))->count());
        $this->assertGreaterThanOrEqual(4, CompendiumItem::where('item_type', 'ability')->count());
        // An action-flavoured feat carries its activation, so the sheet can route it to the Actions tab.
        $this->assertSame('Reaction', CompendiumItem::where('slug', 'wb-sentinel')->sole()->fields['activation']);
    }

    public function test_a_non_admin_cannot_author_library_entries(): void
    {
        $source = $this->worldbuilderSource('ability');

        $this->actingAs(User::factory()->create())
            ->post(route('admin.compendium.items.store', $source))
            ->assertForbidden();
    }
}
