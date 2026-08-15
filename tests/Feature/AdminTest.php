<?php

namespace Tests\Feature;

use App\Jobs\RunCompendiumImport;
use App\Models\CompendiumImportRun;
use App\Models\CompendiumItem;
use App\Models\CompendiumSource;
use App\Models\GlobalAttribute;
use App\Models\User;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Services\CompendiumImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admins_are_locked_out_of_the_admin_area(): void
    {
        $user = User::factory()->create();

        foreach (['admin.dashboard', 'admin.users.index', 'admin.worlds.index', 'admin.compendium.index', 'admin.attributes.index'] as $name) {
            $this->actingAs($user)->get(route($name))->assertForbidden();
        }
    }

    public function test_admin_can_promote_and_revoke_a_user(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create();

        $this->actingAs($admin)->put(route('admin.users.role', $user), ['is_admin' => true])->assertRedirect();
        $this->assertTrue($user->fresh()->isAdmin());

        $this->actingAs($admin)->put(route('admin.users.role', $user), ['is_admin' => false])->assertRedirect();
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_admin_can_top_up_a_users_ai_credit_balance(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['ai_credit_balance' => 10]);

        $this->actingAs($admin)->put(route('admin.users.credits', $user), ['credits' => 100])->assertRedirect();

        $this->assertSame(110, $user->fresh()->ai_credit_balance);
    }

    public function test_topping_up_requires_a_positive_amount(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['ai_credit_balance' => 10]);

        $this->actingAs($admin)->put(route('admin.users.credits', $user), ['credits' => 0])->assertSessionHasErrors('credits');
        $this->assertSame(10, $user->fresh()->ai_credit_balance);
    }

    public function test_a_non_admin_cannot_top_up_credits(): void
    {
        $user = User::factory()->create(['ai_credit_balance' => 10]);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.users.credits', $user), ['credits' => 100])
            ->assertForbidden();

        $this->assertSame(10, $user->fresh()->ai_credit_balance);
    }

    public function test_admin_cannot_revoke_their_own_access(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.users.role', $admin), ['is_admin' => false]);

        $this->assertTrue($admin->fresh()->isAdmin());
    }

    public function test_admin_can_manage_global_attributes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('admin.attributes.store'), [
            'label' => 'Region', 'type' => 'select', 'options' => ['Coastal', 'Highland'],
            'kinds' => ['location'], 'visible' => true,
        ])->assertRedirect();

        $attr = GlobalAttribute::sole();
        $this->assertSame('region', $attr->key);
        $this->assertSame(['Coastal', 'Highland'], $attr->options);

        $this->actingAs($admin)->put(route('admin.attributes.update', $attr), [
            'label' => 'Region', 'type' => 'text', 'required' => true, 'visible' => false,
        ])->assertRedirect();
        $this->assertTrue($attr->fresh()->required);
        $this->assertFalse($attr->fresh()->visible);

        $this->actingAs($admin)->delete(route('admin.attributes.destroy', $attr))->assertRedirect();
        $this->assertDatabaseCount('global_attributes', 0);
    }

    public function test_admin_can_import_a_compendium_source_from_open5e(): void
    {
        $admin = $this->admin();
        $source = CompendiumSource::create([
            'key' => 'open5e-monsters', 'name' => 'Monsters', 'provider' => 'open5e',
            'item_type' => 'monster', 'api_url' => 'https://api.open5e.com/v1/monsters/?limit=100',
        ]);

        Http::fake(['api.open5e.com/*' => Http::sequence()
            ->push(['results' => [['slug' => 'goblin', 'name' => 'Goblin', 'desc' => 'x']], 'next' => 'https://api.open5e.com/v1/monsters/?limit=100&page=2', 'count' => 2])
            ->push(['results' => [['slug' => 'orc', 'name' => 'Orc', 'desc' => 'y']], 'next' => null, 'count' => 2]),
        ]);

        Queue::fake();
        $this->actingAs($admin)->post(route('admin.compendium.import', $source))->assertRedirect();

        // The import is now queued, not run inline.
        $run = $source->runs()->sole();
        $this->assertSame('queued', $run->status);
        Queue::assertPushed(RunCompendiumImport::class);

        // Drive the chunked chain to completion: page one (goblin) then page two (orc).
        $this->drainCompendiumRun($run);

        $this->assertSame(2, $source->items()->count());
        $run->refresh();
        $this->assertSame('complete', $run->status);
        $this->assertSame(2, $run->added);
        $this->assertNull($run->cursor);
        $this->assertNotNull($source->fresh()->last_run_at);
    }

    public function test_a_stale_running_import_is_cleared_when_a_new_one_starts(): void
    {
        $admin = $this->admin();
        $source = CompendiumSource::create([
            'key' => 'open5e-spells', 'name' => 'Spells (open5e)', 'provider' => 'open5e',
            'item_type' => 'spell', 'api_url' => 'https://api.open5e.com/v1/spells/?limit=100',
        ]);
        // A run stranded by an earlier synchronous import that timed out mid-fetch.
        $stale = $source->runs()->create(['status' => 'running', 'started_at' => now()->subHour()]);

        Queue::fake();
        $this->actingAs($admin)->post(route('admin.compendium.import', $source))->assertRedirect();

        $this->assertSame('failed', $stale->refresh()->status);
        $this->assertSame('queued', $source->runs()->where('status', 'queued')->sole()->status);
    }

    /** Advance a queued import's self-re-dispatching chain by hand until it finishes. */
    private function drainCompendiumRun(CompendiumImportRun $run): void
    {
        Queue::fake();
        $importer = app(CompendiumImporter::class);
        for ($step = 0; $step < 50 && ! $run->fresh()->isFinished(); $step++) {
            (new RunCompendiumImport($run->fresh()))->handle($importer);
        }
    }

    public function test_admin_editing_a_library_spells_fields_regenerates_its_document(): void
    {
        $admin = $this->admin();
        $item = CompendiumItem::create(['item_type' => 'spell', 'slug' => 'fireball', 'name' => 'Fireball']);

        $this->actingAs($admin)->put(route('admin.compendium.items.update', $item), [
            'name' => 'Fireball',
            'fields' => ['level' => '3rd', 'school' => 'Evocation', 'description' => 'A bright streak flashes.'],
        ])->assertRedirect();

        $fresh = $item->fresh();
        $this->assertSame('3rd', $fresh->fields['level']);
        $this->assertStringContainsString('#### Fireball', $fresh->document);
        $this->assertStringContainsString('**Level** 3rd', $fresh->document);
        $this->assertStringContainsString('A bright streak flashes.', $fresh->document);
    }

    public function test_admin_can_hide_a_library_entry_from_game_masters(): void
    {
        $admin = $this->admin();
        $item = CompendiumItem::create(['item_type' => 'spell', 'slug' => 'hidden', 'name' => 'Hidden Spell', 'visible' => true]);

        $this->actingAs($admin)->put(route('admin.compendium.items.update', $item), ['visible' => false])->assertRedirect();
        $this->assertFalse($item->fresh()->visible);

        // A game master browsing the library no longer sees the hidden entry.
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $this->actingAs($gm)
            ->getJson(route('compendium.browse', ['world' => $world->id, 'item_type' => 'spell']))
            ->assertOk()
            ->assertJsonMissing(['slug' => 'hidden']);
    }

    public function test_admin_can_draft_a_library_entry_with_unmetered_ai(): void
    {
        $admin = $this->admin();
        $item = CompendiumItem::create(['item_type' => 'spell', 'slug' => 'fb', 'name' => 'Fireball']);

        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chat')->andReturn(json_encode(['fields' => ['level' => '3rd', 'school' => 'Evocation'], 'reply' => 'Drafted.']));
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($admin)->postJson(route('admin.compendium.items.draft', $item), ['prompt' => 'draft it'])
            ->assertOk()
            ->assertJsonPath('fields.level', '3rd')
            ->assertJsonPath('fields.school', 'Evocation')
            ->assertJsonMissingPath('ai'); // unmetered — no world budget block
    }
}
