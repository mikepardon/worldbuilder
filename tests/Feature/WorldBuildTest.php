<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\PopulateWorldFromSeed;
use App\Models\User;
use App\Models\WorldBuild;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WorldBuildTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The build runs as a chain of self-re-dispatching jobs; drive that chain to completion by hand
     * (each handle() does one phase — outline, a batch, or linking — then queues the next).
     */
    private function runBuild(WorldBuild $build): void
    {
        Queue::fake(); // The internal re-dispatch is a no-op; we advance the chain manually.
        $ai = app(AnthropicClient::class);
        for ($step = 0; $step < 50; $step++) {
            $build->refresh();
            if ($build->isFinished()) {
                return;
            }
            (new PopulateWorldFromSeed($build))->handle($ai);
        }
    }

    /** Fake the two-pass AI: the outline call and the entry-writing calls return distinct JSON. */
    private function fakeBuildAi(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(function ($request) {
            $system = $request->data()['system'] ?? '';
            $payload = str_contains($system, '"entities"')
                ? ['entities' => [
                    ['kind' => 'npc', 'title' => 'Claude Morgo', 'private' => false],
                    ['kind' => 'location', 'title' => 'Arcadia', 'private' => false],
                    ['kind' => 'lore', 'title' => 'The Name-Prayer', 'private' => true],
                ]]
                : ['entries' => [
                    ['title' => 'Claude Morgo', 'kind' => 'npc', 'summary' => 'A death cleric.', 'content' => "# Claude\nA priest of Thanatos, of [[Arcadia]].", 'facts' => ['role' => 'Death Cleric', 'species' => 'Demonkin']],
                    ['title' => 'Arcadia', 'kind' => 'location', 'summary' => 'The hub city.', 'content' => 'The capital region.', 'facts' => ['type' => 'City-state', 'ignored_key' => 'dropped']],
                    ['title' => 'The Name-Prayer', 'kind' => 'lore', 'summary' => 'Speaking a god’s name.', 'content' => 'A dangerous mechanic.', 'facts' => []],
                ]];

            return Http::response(['content' => [['type' => 'text', 'text' => json_encode($payload)]]], 200);
        });
    }

    public function test_starting_a_build_queues_the_job(): void
    {
        Queue::fake();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Mythos', 'visibility' => 'private', 'setting' => 'A greek myth campaign bible.']);

        $this->actingAs($gm)->postJson(route('worlds.build.store', $world->id))
            ->assertOk()
            ->assertJsonPath('build.status', 'queued');

        Queue::assertPushed(PopulateWorldFromSeed::class);
        $this->assertDatabaseHas('world_builds', ['world_id' => $world->id, 'status' => 'queued']);
    }

    public function test_starting_a_build_reuses_an_in_flight_run(): void
    {
        Queue::fake();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Mythos', 'visibility' => 'private', 'setting' => 'Seed.']);

        $this->actingAs($gm)->postJson(route('worlds.build.store', $world->id))->assertOk();
        $this->actingAs($gm)->postJson(route('worlds.build.store', $world->id))->assertOk();

        $this->assertSame(1, $world->worldBuilds()->count());
        Queue::assertPushed(PopulateWorldFromSeed::class, 1);
    }

    public function test_a_build_requires_a_seed(): void
    {
        Queue::fake();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Bare', 'visibility' => 'private']);

        $this->actingAs($gm)->postJson(route('worlds.build.store', $world->id))->assertStatus(422);

        Queue::assertNothingPushed();
    }

    public function test_a_stranger_cannot_start_a_build(): void
    {
        Queue::fake();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Mythos', 'visibility' => 'private', 'setting' => 'Seed.']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->postJson(route('worlds.build.store', $world->id))->assertForbidden();
    }

    public function test_the_job_builds_entries_from_the_seed(): void
    {
        $this->fakeBuildAi();
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Mythos', 'visibility' => 'private', 'setting' => 'A rich greek campaign bible with a party, NPCs and places.']);
        $build = $world->worldBuilds()->create(['user_id' => $gm->id, 'status' => 'queued']);

        $this->runBuild($build);

        $build->refresh();
        $this->assertSame('completed', $build->status);
        $this->assertSame(3, $build->created);

        $this->assertDatabaseHas('documents', ['world_id' => $world->id, 'title' => 'Claude Morgo', 'kind' => 'npc', 'is_private' => false]);
        $this->assertDatabaseHas('documents', ['world_id' => $world->id, 'title' => 'Arcadia', 'kind' => 'location']);
        // A flagged secret becomes a GM-only entry.
        $this->assertDatabaseHas('documents', ['world_id' => $world->id, 'title' => 'The Name-Prayer', 'kind' => 'lore', 'is_private' => true]);

        $entry = $world->documents()->where('title', 'Claude Morgo')->firstOrFail();
        $this->assertStringContainsString('Thanatos', $entry->content);
        $this->assertSame('A death cleric.', $entry->summary);

        // Quick facts are written into the entry's data, keyed by the kind's field keys…
        $this->assertSame('Death Cleric', $entry->data['role']);
        $this->assertSame('Demonkin', $entry->data['species']);
        // …and unknown keys the model invents are dropped.
        $arcadia = $world->documents()->where('title', 'Arcadia')->firstOrFail();
        $this->assertSame('City-state', $arcadia->data['type']);
        $this->assertArrayNotHasKey('ignored_key', $arcadia->data);

        // The [[Arcadia]] wiki-link in Claude's body resolves into a real connection.
        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $entry->id,
            'to_document_id' => $arcadia->id,
            'source' => 'wikilink',
        ]);
    }

    public function test_the_job_fails_gracefully_without_a_seed(): void
    {
        config(['services.anthropic.key' => 'test-key']);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Bare', 'visibility' => 'private']);
        $build = $world->worldBuilds()->create(['user_id' => $gm->id, 'status' => 'queued']);

        $this->runBuild($build);

        $this->assertSame('failed', $build->refresh()->status);
        $this->assertSame(0, $world->documents()->count());
    }

    public function test_the_status_endpoint_returns_the_latest_build(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Mythos', 'visibility' => 'private', 'setting' => 'Seed.']);
        $world->worldBuilds()->create(['user_id' => $gm->id, 'status' => 'running', 'planned' => 10, 'created' => 4]);

        $this->actingAs($gm)->getJson(route('worlds.build.status', $world->id))
            ->assertOk()
            ->assertJsonPath('build.status', 'running')
            ->assertJsonPath('build.created', 4);
    }
}
