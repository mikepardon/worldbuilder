<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CampaignCompendiumItem;
use App\Models\Document;
use App\Models\User;
use App\Models\World;
use App\Services\AnthropicClient;
use App\Support\AiUsageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorldIngestionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A stand-in for the Anthropic client that routes each call by inspecting its prompt, so the plan pass,
     * document generation and compendium drafter all get shaped replies without hitting the network.
     *
     * @param  array<string, mixed>  $plan
     */
    private function fakeAi(array $plan): void
    {
        $ai = new class($plan) extends AnthropicClient
        {
            /** @param array<string, mixed> $plan */
            public function __construct(private array $plan) {}

            public function configured(): bool
            {
                return true;
            }

            public function chat(string $system, array $messages, int $maxTokens = 1500, ?AiUsageContext $usage = null, int $timeout = 60): string
            {
                if (str_contains($system, 'Propose a plan')) {
                    return json_encode($this->plan);
                }
                if (str_contains($system, 'revising the existing')) {
                    return json_encode(['summary' => 'Revised.', 'content' => "The updated body, now mentioning [[The Ashen Concord]]."]);
                }
                if (str_contains($system, 'writing a wiki entry')) {
                    return json_encode(['summary' => 'A brand new entry.', 'content' => 'A freshly written body.', 'facts' => []]);
                }
                if (str_contains($system, 'compendium entry')) {
                    return json_encode([
                        'block' => ['cr' => '3', 'ac' => '14', 'abilities' => ['str' => 16]],
                        'summary' => 'A lurking horror.',
                        'reply' => 'Drafted the monster.',
                    ]);
                }

                return '{}';
            }
        };

        $this->app->instance(AnthropicClient::class, $ai);
    }

    private function world(User $gm): World
    {
        // The ingestion tool is an admin-granted per-world feature; enable it for these flows.
        return $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public', 'knowledge_ingestion_enabled' => true]);
    }

    public function test_planning_proposes_create_and_update_changes_matched_to_existing_entries(): void
    {
        $gm = User::factory()->create(['ai_credit_balance' => 100]);
        $world = $this->world($gm);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Kel Vashti', 'slug' => Document::uniqueSlug($world->id, 'location', 'Kel Vashti'),
            'kind' => 'location', 'content' => 'The old body.', 'summary' => 'A city.',
        ]);

        $this->fakeAi(['changes' => [
            ['action' => 'create', 'target' => 'document', 'kind' => 'faction', 'title' => 'The Ashen Concord', 'rationale' => 'A new faction.', 'instruction' => 'Write the faction.'],
            ['action' => 'update', 'target' => 'document', 'kind' => 'location', 'title' => 'Kel Vashti', 'rationale' => 'Adds detail.', 'instruction' => 'Add the Weaver machinery.'],
        ]]);

        $this->actingAs($gm)->postJson(route('worlds.ingest.store', $world), [
            'source_text' => 'The Ashen Concord scuttled Kel Vashti using Weaver machinery beneath the city.',
        ])->assertOk()->assertJsonPath('ingestion.status', 'ready');

        $ingestion = $world->worldIngestions()->firstOrFail();
        $this->assertSame(2, $ingestion->proposedChanges()->count());
        // The "update" was matched to the existing document, not turned into a create.
        $update = $ingestion->proposedChanges()->where('action', 'update')->firstOrFail();
        $this->assertNotNull($update->document_id);
    }

    public function test_applying_approved_changes_creates_and_updates_documents(): void
    {
        $gm = User::factory()->create(['ai_credit_balance' => 100]);
        $world = $this->world($gm);
        $existing = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Kel Vashti', 'slug' => Document::uniqueSlug($world->id, 'location', 'Kel Vashti'),
            'kind' => 'location', 'content' => 'The old body.', 'summary' => 'A city.',
        ]);

        $this->fakeAi(['changes' => [
            ['action' => 'create', 'target' => 'document', 'kind' => 'faction', 'title' => 'The Ashen Concord', 'rationale' => 'New.', 'instruction' => 'Write it.'],
            ['action' => 'update', 'target' => 'document', 'kind' => 'location', 'title' => 'Kel Vashti', 'rationale' => 'Detail.', 'instruction' => 'Add machinery.'],
        ]]);

        $this->actingAs($gm)->postJson(route('worlds.ingest.store', $world), ['source_text' => str_repeat('Lore about the Concord and Kel Vashti. ', 3)])->assertOk();

        $ingestion = $world->worldIngestions()->firstOrFail();
        $ids = $ingestion->proposedChanges()->pluck('id')->all();

        $this->actingAs($gm)->postJson(route('worlds.ingest.apply', [$world, $ingestion]), ['approved' => $ids])
            ->assertOk()
            ->assertJsonPath('ingestion.status', 'completed');

        // A new faction document was created…
        $this->assertDatabaseHas('documents', ['world_id' => $world->id, 'title' => 'The Ashen Concord', 'kind' => 'faction']);
        // …and the existing location was rewritten, not duplicated.
        $this->assertSame(1, $world->documents()->where('title', 'Kel Vashti')->count());
        $this->assertSame('The updated body, now mentioning [[The Ashen Concord]].', $existing->fresh()->content);
    }

    public function test_applying_a_compendium_change_creates_a_stat_block_entry(): void
    {
        $gm = User::factory()->create(['ai_credit_balance' => 100]);
        $world = $this->world($gm);

        $this->fakeAi(['changes' => [
            ['action' => 'create', 'target' => 'compendium', 'kind' => 'monster', 'title' => 'Sahn', 'rationale' => 'It eats people.', 'instruction' => 'A CR 3 people-eater.'],
        ]]);

        $this->actingAs($gm)->postJson(route('worlds.ingest.store', $world), ['source_text' => 'Sahn is a monster from below that eats people whole.'])->assertOk();

        $ingestion = $world->worldIngestions()->firstOrFail();
        $ids = $ingestion->proposedChanges()->pluck('id')->all();

        $this->actingAs($gm)->postJson(route('worlds.ingest.apply', [$world, $ingestion]), ['approved' => $ids])
            ->assertOk()->assertJsonPath('ingestion.status', 'completed');

        $item = CampaignCompendiumItem::where('world_id', $world->id)->where('name', 'Sahn')->firstOrFail();
        $this->assertSame('monster', $item->item_type);
        $this->assertSame('3', data_get($item->fields, 'block.cr'));
    }

    public function test_only_approved_changes_are_applied(): void
    {
        $gm = User::factory()->create(['ai_credit_balance' => 100]);
        $world = $this->world($gm);

        $this->fakeAi(['changes' => [
            ['action' => 'create', 'target' => 'document', 'kind' => 'faction', 'title' => 'Keep This', 'rationale' => 'x', 'instruction' => 'Write it.'],
            ['action' => 'create', 'target' => 'document', 'kind' => 'faction', 'title' => 'Reject This', 'rationale' => 'x', 'instruction' => 'Write it.'],
        ]]);

        $this->actingAs($gm)->postJson(route('worlds.ingest.store', $world), ['source_text' => 'Two factions worth writing about here.'])->assertOk();

        $ingestion = $world->worldIngestions()->firstOrFail();
        $keep = $ingestion->proposedChanges()->where('title', 'Keep This')->firstOrFail();

        $this->actingAs($gm)->postJson(route('worlds.ingest.apply', [$world, $ingestion]), ['approved' => [$keep->id]])
            ->assertOk()->assertJsonPath('ingestion.status', 'completed');

        $this->assertDatabaseHas('documents', ['world_id' => $world->id, 'title' => 'Keep This']);
        $this->assertDatabaseMissing('documents', ['world_id' => $world->id, 'title' => 'Reject This']);
    }

    public function test_apply_pauses_when_credits_run_out_and_resumes_after_a_top_up(): void
    {
        // No daily allowance left and only 2 credits: enough for the plan (1) + one apply (1), then dry.
        $gm = User::factory()->create([
            'ai_credit_balance' => 2, 'daily_ai_used' => 5, 'daily_ai_reset_on' => now()->toDateString(),
        ]);
        $world = $this->world($gm);

        $this->fakeAi(['changes' => [
            ['action' => 'create', 'target' => 'document', 'kind' => 'faction', 'title' => 'First', 'rationale' => 'x', 'instruction' => 'Write it.'],
            ['action' => 'create', 'target' => 'document', 'kind' => 'faction', 'title' => 'Second', 'rationale' => 'x', 'instruction' => 'Write it.'],
        ]]);

        $this->actingAs($gm)->postJson(route('worlds.ingest.store', $world), ['source_text' => 'Two factions to write about in detail.'])->assertOk();

        $ingestion = $world->worldIngestions()->firstOrFail();
        $ids = $ingestion->proposedChanges()->pluck('id')->all();

        $this->actingAs($gm)->postJson(route('worlds.ingest.apply', [$world, $ingestion]), ['approved' => $ids])
            ->assertOk()->assertJsonPath('ingestion.status', 'paused');

        $ingestion->refresh();
        $this->assertSame(1, $ingestion->applied);
        $this->assertSame(1, $world->documents()->count());

        // Top up and resume — the second change now applies and the run completes.
        $gm->update(['ai_credit_balance' => 10]);
        $this->actingAs($gm)->postJson(route('worlds.ingest.resume', [$world, $ingestion]))
            ->assertOk()->assertJsonPath('ingestion.status', 'completed');

        $this->assertSame(2, $world->documents()->count());
    }

    public function test_a_non_manager_cannot_start_an_ingestion(): void
    {
        $gm = User::factory()->create();
        $world = $this->world($gm);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson(route('worlds.ingest.store', $world), ['source_text' => 'Trying to inject knowledge into a world I do not own.'])
            ->assertForbidden();
    }

    public function test_the_feature_is_blocked_when_an_admin_has_not_enabled_it_for_the_world(): void
    {
        $gm = User::factory()->create(['ai_credit_balance' => 100]);
        // Flag off (the default) — the manager still can't use it.
        $world = $gm->worlds()->create(['name' => 'Locked', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.ingest', $world))->assertForbidden();
        $this->actingAs($gm)->postJson(route('worlds.ingest.store', $world), ['source_text' => str_repeat('some notes here ', 3)])
            ->assertForbidden();
    }

    public function test_an_admin_can_toggle_the_feature_for_a_world(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $world = User::factory()->create()->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $this->assertFalse($world->fresh()->knowledge_ingestion_enabled);

        $this->actingAs($admin)->put(route('admin.worlds.knowledge-access', $world), ['knowledge_ingestion_enabled' => true])
            ->assertRedirect();
        $this->assertTrue($world->fresh()->knowledge_ingestion_enabled);

        $this->actingAs($admin)->put(route('admin.worlds.knowledge-access', $world), ['knowledge_ingestion_enabled' => false])
            ->assertRedirect();
        $this->assertFalse($world->fresh()->knowledge_ingestion_enabled);
    }
}
