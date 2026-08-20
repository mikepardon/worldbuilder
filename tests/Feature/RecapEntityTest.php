<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RecapEntity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RecapEntityTest extends TestCase
{
    use RefreshDatabase;

    private function entityFor(User $gm, string $type = 'location', string $status = 'unmatched', string $name = 'Gloomgrove'): RecapEntity
    {
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'private']);
        $session = $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'Session 3']);
        $recap = $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
        ]);

        return $recap->entities()->create([
            'name' => $name, 'type' => $type, 'description' => 'A hidden goblin enclave.', 'status' => $status,
        ]);
    }

    public function test_a_gm_can_edit_an_entity_name_and_description(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm);

        $this->actingAs($gm)->putJson(route('recap.entities.update', $entity), [
            'name' => 'Gloomgrove Enclave',
            'description' => 'A hidden commune of goblins.',
        ])->assertOk()->assertJsonPath('name', 'Gloomgrove Enclave');

        $entity->refresh();
        $this->assertSame('Gloomgrove Enclave', $entity->name);
        $this->assertSame('A hidden commune of goblins.', $entity->description);
    }

    public function test_editing_rejects_a_blank_name(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm);

        $this->actingAs($gm)->putJson(route('recap.entities.update', $entity), [
            'name' => '',
        ])->assertStatus(422);
    }

    public function test_a_gm_can_link_an_entity_to_an_existing_document(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm, 'location');
        $world = $entity->recap->session->campaign->world;
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Gloomgrove', 'slug' => 'gloomgrove',
            'kind' => 'location', 'content' => '# Gloomgrove', 'is_private' => false,
        ]);

        $this->actingAs($gm)->postJson(route('recap.entities.link', $entity), [
            'target' => 'document', 'id' => $document->id,
        ])->assertOk()->assertJsonPath('status', 'linked')
            ->assertJsonPath('link.edit_url', route('documents.edit', $document->id))
            ->assertJsonPath('link.view_url', route('public.article', [$world->slug, 'location', 'gloomgrove']));

        $entity->refresh();
        $this->assertSame('linked', $entity->status);
        $this->assertSame($document->id, $entity->linked_document_id);
    }

    public function test_linking_to_a_document_from_another_world_is_rejected(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm, 'location');
        $otherWorld = User::factory()->create()->worlds()->create(['name' => 'Elsewhere', 'visibility' => 'private']);
        $foreign = $otherWorld->documents()->create([
            'user_id' => $otherWorld->user_id, 'title' => 'Faraway', 'slug' => 'faraway',
            'kind' => 'location', 'content' => '# Faraway', 'is_private' => false,
        ]);

        $this->actingAs($gm)->postJson(route('recap.entities.link', $entity), [
            'target' => 'document', 'id' => $foreign->id,
        ])->assertStatus(422);

        $this->assertNull($entity->fresh()->linked_document_id);
    }

    public function test_creating_a_new_document_from_a_location_entity(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm, 'location', 'unmatched', 'Gloomgrove');
        $world = $entity->recap->session->campaign->world;

        $this->actingAs($gm)->postJson(route('recap.entities.create', $entity))
            ->assertOk()->assertJsonPath('status', 'created');

        $document = $world->documents()->where('kind', 'location')->where('title', 'Gloomgrove')->first();
        $this->assertNotNull($document);
        $this->assertStringContainsString('hidden goblin enclave', $document->content);
        $this->assertSame($document->id, $entity->fresh()->linked_document_id);
    }

    public function test_creating_a_monster_entity_makes_a_compendium_item(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm, 'monster', 'unmatched', 'Bog Shadow');
        $world = $entity->recap->session->campaign->world;

        $this->actingAs($gm)->postJson(route('recap.entities.create', $entity))
            ->assertOk()->assertJsonPath('status', 'created');

        $item = $world->compendiumItems()->where('item_type', 'monster')->where('name', 'Bog Shadow')->first();
        $this->assertNotNull($item);
        $this->assertSame($item->id, $entity->fresh()->linked_compendium_item_id);
    }

    public function test_candidates_returns_matching_world_entries_for_the_entity_kind(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm, 'location');
        $world = $entity->recap->session->campaign->world;
        foreach (['Gloomgrove', 'Kobold Keep'] as $title) {
            $world->documents()->create([
                'user_id' => $gm->id, 'title' => $title, 'slug' => Str::slug($title),
                'kind' => 'location', 'content' => "# {$title}", 'is_private' => false,
            ]);
        }

        $this->actingAs($gm)->getJson(route('recap.entities.candidates', $entity).'?q=gloom')
            ->assertOk()
            ->assertJsonCount(1, 'candidates')
            ->assertJsonPath('candidates.0.name', 'Gloomgrove');
    }

    public function test_dismissing_then_restoring_an_entity(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm);

        $this->actingAs($gm)->postJson(route('recap.entities.dismiss', $entity))
            ->assertOk()->assertJsonPath('status', 'dismissed');
        $this->assertSame('dismissed', $entity->fresh()->status);

        $this->actingAs($gm)->postJson(route('recap.entities.unlink', $entity))
            ->assertOk()->assertJsonPath('status', 'unmatched');
        $this->assertSame('unmatched', $entity->fresh()->status);
    }

    public function test_a_non_gm_cannot_reconcile_an_entity(): void
    {
        $gm = User::factory()->create();
        $entity = $this->entityFor($gm);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->postJson(route('recap.entities.create', $entity))->assertForbidden();
        $this->actingAs($intruder)->putJson(route('recap.entities.update', $entity), ['name' => 'Hacked'])->assertForbidden();
    }
}
