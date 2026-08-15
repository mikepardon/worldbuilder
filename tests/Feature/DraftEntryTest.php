<?php

namespace Tests\Feature;

use App\Models\GlobalAttribute;
use App\Models\User;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DraftEntryTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(string $reply): void
    {
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chat')->andReturn($reply);
        $this->app->instance(AnthropicClient::class, $ai);
    }

    public function test_draft_fills_known_fields_rewrites_content_and_drops_unknown_keys(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $location = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        // Default location fields are type/region/population/ruler; "smell" is not a field and must be dropped.
        $this->fakeAi(json_encode([
            'fields' => ['type' => 'City', 'region' => 'The Sundered Coast', 'smell' => 'briny'],
            'content' => "## Overview\nBrine and tar.",
            'summary' => 'A briny port town.',
            'reply' => 'Drafted a full location.',
        ]));

        $this->actingAs($gm)->postJson(route('documents.draft', $location), ['prompt' => 'Provide a full dummy location'])
            ->assertOk()
            ->assertJsonPath('fields.type', 'City')
            ->assertJsonPath('fields.region', 'The Sundered Coast')
            ->assertJsonMissingPath('fields.smell')
            ->assertJsonPath('summary', 'A briny port town.')
            ->assertJsonPath('reply', 'Drafted a full location.')
            ->assertJsonPath('content', "## Overview\nBrine and tar.");
    }

    public function test_draft_rejects_an_invalid_select_option(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $location = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        GlobalAttribute::create([
            'key' => 'climate', 'label' => 'Climate', 'type' => 'select', 'options' => ['Temperate', 'Arid'],
            'kinds' => ['location'], 'visible' => true, 'sort_order' => 0,
        ]);

        $this->fakeAi(json_encode(['fields' => ['climate' => 'Tropical'], 'content' => '', 'summary' => '', 'reply' => 'ok']));

        $this->actingAs($gm)->postJson(route('documents.draft', $location), ['prompt' => 'fill it'])
            ->assertOk()
            ->assertJsonMissingPath('fields.climate');
    }

    public function test_draft_normalises_a_case_insensitive_select_match_to_the_canonical_option(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $location = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        GlobalAttribute::create([
            'key' => 'climate', 'label' => 'Climate', 'type' => 'select', 'options' => ['Temperate', 'Arid'],
            'kinds' => ['location'], 'visible' => true, 'sort_order' => 0,
        ]);

        $this->fakeAi(json_encode(['fields' => ['climate' => 'temperate'], 'content' => '', 'summary' => '', 'reply' => 'ok']));

        $this->actingAs($gm)->postJson(route('documents.draft', $location), ['prompt' => 'fill it'])
            ->assertOk()
            ->assertJsonPath('fields.climate', 'Temperate');
    }

    public function test_draft_only_accepts_a_reference_id_that_exists_in_the_campaign(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $location = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);
        $npc = $campaign->documents()->create(['title' => 'Lady Merrow', 'slug' => 'merrow', 'kind' => 'npc']);

        GlobalAttribute::create([
            'key' => 'ruler_ref', 'label' => 'Ruled by', 'type' => 'reference', 'ref_kinds' => ['npc'],
            'kinds' => ['location'], 'visible' => true, 'sort_order' => 0,
        ]);

        // A real npc id is accepted; a made-up id is rejected.
        $this->fakeAi(json_encode(['fields' => ['ruler_ref' => $npc->id], 'content' => '', 'summary' => '', 'reply' => 'ok']));
        $this->actingAs($gm)->postJson(route('documents.draft', $location), ['prompt' => 'fill it'])
            ->assertOk()
            ->assertJsonPath('fields.ruler_ref', $npc->id);

        $this->fakeAi(json_encode(['fields' => ['ruler_ref' => 999999], 'content' => '', 'summary' => '', 'reply' => 'ok']));
        $this->actingAs($gm)->postJson(route('documents.draft', $location), ['prompt' => 'fill it'])
            ->assertOk()
            ->assertJsonMissingPath('fields.ruler_ref');
    }

    public function test_draft_can_ask_a_clarifying_question_without_changing_anything(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $location = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        // Ambiguous request: Claude asks a question and returns nothing to apply.
        $this->fakeAi(json_encode([
            'fields' => [],
            'content' => '',
            'summary' => '',
            'reply' => 'Which era is this port set in — age of sail, or something earlier?',
        ]));

        $this->actingAs($gm)->postJson(route('documents.draft', $location), ['prompt' => 'flesh it out a bit'])
            ->assertOk()
            ->assertJsonPath('reply', 'Which era is this port set in — age of sail, or something earlier?')
            ->assertJsonPath('content', '')
            ->assertJsonCount(0, 'fields');
    }

    public function test_draft_returns_422_when_the_ai_reply_is_not_json(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $location = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        $this->fakeAi('Sorry, I could not help with that.');

        $this->actingAs($gm)->postJson(route('documents.draft', $location), ['prompt' => 'fill it'])
            ->assertStatus(422);
    }

    public function test_draft_requires_a_prompt(): void
    {
        $gm = User::factory()->create();
        $campaign = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);
        $location = $campaign->documents()->create(['title' => 'Docks', 'slug' => 'docks', 'kind' => 'location']);

        // A missing prompt is rejected before Claude is ever called.
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('chat')->never();
        $this->app->instance(AnthropicClient::class, $ai);

        $this->actingAs($gm)->post(route('documents.draft', $location), ['prompt' => ''])
            ->assertSessionHasErrors('prompt');
    }
}
