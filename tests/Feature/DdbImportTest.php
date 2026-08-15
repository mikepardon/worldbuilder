<?php

namespace Tests\Feature;

use App\Jobs\RunDdbImport;
use App\Models\Media;
use App\Models\User;
use App\Models\World;
use App\Services\DdbClient;
use App\Services\DdbImageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class DdbImportTest extends TestCase
{
    use RefreshDatabase;

    private function enabledWorld(User $gm): World
    {
        return $gm->worlds()->create([
            'name' => 'World', 'visibility' => 'public', 'ddb_enabled' => true, 'ddb_cobalt' => 'cobalt-token',
        ]);
    }

    public function test_starting_an_import_is_forbidden_when_the_world_is_not_ddb_enabled(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']); // ddb_enabled false

        $this->actingAs($gm)->post(route('ddb.import.start', $world), ['types' => ['monster']])->assertForbidden();
    }

    public function test_starting_an_import_queues_a_job_and_records_a_pending_import(): void
    {
        Queue::fake();
        $gm = User::factory()->create();
        $world = $this->enabledWorld($gm);

        $this->actingAs($gm)
            ->post(route('ddb.import.start', $world), ['types' => ['monster', 'spell'], 'homebrew' => true])
            ->assertRedirect();

        $this->assertDatabaseHas('compendium_imports', [
            'world_id' => $world->id, 'status' => 'queued', 'user_id' => $gm->id,
        ]);
        Queue::assertPushed(RunDdbImport::class);
    }

    public function test_starting_an_import_requires_a_saved_token(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public', 'ddb_enabled' => true]);

        $this->actingAs($gm)
            ->post(route('ddb.import.start', $world), ['types' => ['monster']])
            ->assertSessionHasErrors('types');
    }

    public function test_the_import_job_stores_gm_only_entries_with_shared_images(): void
    {
        Storage::fake('public');
        Http::fake(['https://ddb-images.example/*' => Http::response('FAKE-IMAGE-BYTES', 200, ['Content-Type' => 'image/png'])]);

        $gm = User::factory()->create();
        $world = $this->enabledWorld($gm);
        $import = $world->compendiumImports()->create([
            'user_id' => $gm->id, 'source' => 'ddb', 'status' => 'queued', 'types' => ['monster', 'item'], 'homebrew' => true,
        ]);

        $ddb = Mockery::mock(DdbClient::class);
        $ddb->shouldReceive('token')->andReturn('bearer');
        $ddb->shouldReceive('lookups')->andReturn([
            'sizes' => [3 => 'Medium'], 'monsterTypes' => [7 => 'Giant'], 'alignments' => [],
            'challengeRatings' => [2 => '2'], 'movements' => [1 => 'Walk'], 'senses' => [], 'conditions' => [],
        ]);
        $ddb->shouldReceive('monsters')->andReturn([[
            'name' => 'Ogre', 'id' => 17, 'sizeId' => 3, 'typeId' => 7, 'challengeRatingId' => 2, 'stats' => [],
            'movements' => [['movementId' => 1, 'speed' => 40]], 'avatarUrl' => 'https://ddb-images.example/ogre.png',
            'actionsDescription' => '<p><strong>Greatclub.</strong> Smash.</p>',
        ]]);
        $ddb->shouldReceive('items')->andReturn([[
            'name' => 'Bag of Holding', 'id' => 5, 'magic' => true, 'rarity' => 'Uncommon',
            'filterType' => 'Wondrous item', 'avatarUrl' => 'https://ddb-images.example/bag.png', 'description' => '<p>Roomy.</p>',
        ]]);
        $this->app->instance(DdbClient::class, $ddb);

        (new RunDdbImport($import))->handle(app(DdbClient::class), app(DdbImageStore::class));

        $import->refresh();
        $this->assertSame('completed', $import->status);
        $this->assertSame(2, $import->imported);
        $this->assertSame(2, $import->images);
        $this->assertSame(['monster' => 1, 'magicitem' => 1], $import->counts);

        $ogre = $world->compendiumItems()->where('name', 'Ogre')->first();
        $this->assertNotNull($ogre);
        $this->assertSame('monster', $ogre->item_type);
        $this->assertSame('ddb', $ogre->origin);
        $this->assertTrue($ogre->is_private);
        $this->assertNotNull($ogre->image_media_id);
        $this->assertStringContainsString('Greatclub', $ogre->document);

        // Each distinct image stored once, keyed for dedup, and written to the disk.
        $this->assertSame(2, Media::whereNotNull('external_key')->count());
        $this->assertDatabaseHas('media', ['external_key' => 'ddb-monster-17', 'world_id' => null, 'user_id' => null]);
        Storage::disk('public')->assertExists('ddb/ddb-monster-17.png');
    }

    public function test_reimporting_the_same_monster_reuses_the_shared_image(): void
    {
        Storage::fake('public');
        Http::fake(['https://ddb-images.example/*' => Http::response('FAKE-IMAGE-BYTES', 200, ['Content-Type' => 'image/png'])]);

        $monster = [[
            'name' => 'Ogre', 'id' => 17, 'sizeId' => 3, 'typeId' => 7, 'challengeRatingId' => 2, 'stats' => [],
            'movements' => [], 'avatarUrl' => 'https://ddb-images.example/ogre.png', 'actionsDescription' => '',
        ]];
        $lookups = [
            'sizes' => [3 => 'Medium'], 'monsterTypes' => [7 => 'Giant'], 'alignments' => [],
            'challengeRatings' => [2 => '2'], 'movements' => [], 'senses' => [], 'conditions' => [],
        ];

        $ddb = Mockery::mock(DdbClient::class);
        $ddb->shouldReceive('token')->andReturn('bearer');
        $ddb->shouldReceive('lookups')->andReturn($lookups);
        $ddb->shouldReceive('monsters')->andReturn($monster);
        $this->app->instance(DdbClient::class, $ddb);

        foreach (['World A', 'World B'] as $name) {
            $gm = User::factory()->create();
            $world = $gm->worlds()->create([
                'name' => $name, 'visibility' => 'public', 'ddb_enabled' => true, 'ddb_cobalt' => 'cobalt',
            ]);
            $import = $world->compendiumImports()->create([
                'user_id' => $gm->id, 'source' => 'ddb', 'status' => 'queued', 'types' => ['monster'], 'homebrew' => true,
            ]);
            (new RunDdbImport($import))->handle(app(DdbClient::class), app(DdbImageStore::class));
        }

        // The image is fetched and stored exactly once, then reused by the second world.
        $this->assertSame(1, Media::where('external_key', 'ddb-monster-17')->count());
        Http::assertSentCount(1);
    }

    public function test_fetching_campaigns_validates_the_token_and_lists_them(): void
    {
        $gm = User::factory()->create();
        $world = $this->enabledWorld($gm);

        $ddb = Mockery::mock(DdbClient::class);
        $ddb->shouldReceive('token')->andReturn('bearer');
        $ddb->shouldReceive('campaigns')->andReturn([['id' => '99', 'name' => 'Curse of Strahd']]);
        $this->app->instance(DdbClient::class, $ddb);

        $this->actingAs($gm)
            ->post(route('ddb.campaigns', $world), ['cobalt' => 'fresh-token'])
            ->assertRedirect()
            ->assertSessionHas('ddbCampaigns', [['id' => '99', 'name' => 'Curse of Strahd']]);

        $this->assertSame('fresh-token', $world->fresh()->ddb_cobalt);
    }

    public function test_saving_a_rejected_token_returns_an_error(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public', 'ddb_enabled' => true]);

        $ddb = Mockery::mock(DdbClient::class);
        $ddb->shouldReceive('token')->andReturn(null);
        $this->app->instance(DdbClient::class, $ddb);

        $this->actingAs($gm)
            ->put(route('ddb.settings.save', $world), ['cobalt' => 'bad-token'])
            ->assertSessionHasErrors('cobalt');
    }

    public function test_saving_settings_persists_the_selected_campaign(): void
    {
        $gm = User::factory()->create();
        $world = $this->enabledWorld($gm);

        $this->actingAs($gm)
            ->put(route('ddb.settings.save', $world), ['ddb_campaign_id' => '99'])
            ->assertRedirect();

        $this->assertSame('99', $world->fresh()->ddb_campaign_id);
    }
}
