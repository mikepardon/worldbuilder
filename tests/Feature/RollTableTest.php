<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RollTableTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(array $rows, string $reply = 'Here you go.'): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => json_encode(['reply' => $reply, 'rows' => $rows])]],
        ], 200)]);
    }

    public function test_the_tables_page_renders_for_the_gm(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $world->rollTables()->create(['name' => 'Loot', 'die' => 20, 'rows' => []]);

        $this->actingAs($gm)->get(route('tables.index', $world))->assertInertia(fn (Assert $page) => $page
            ->component('Worlds/Tables')
            ->where('tables.0.name', 'Loot')
            ->has('creditsRemaining'));
    }

    public function test_a_gm_can_create_a_table(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('tables.store', $world->id), [
            'name' => 'Wilderness encounters',
            'die' => 12,
            'is_private' => true,
            'rows' => [
                ['min' => 1, 'max' => 6, 'result' => 'Nothing', 'detail' => 'A quiet road.'],
                ['min' => 7, 'max' => 12, 'result' => 'Bandits', 'detail' => null],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('roll_tables', [
            'world_id' => $world->id,
            'name' => 'Wilderness encounters',
            'die' => 12,
            'is_private' => true,
        ]);

        $table = $world->rollTables()->firstOrFail();
        $this->assertSame('Bandits', $table->rows[1]['result']);
        $this->assertSame(7, $table->rows[1]['min']);
    }

    public function test_a_row_can_link_to_another_table_in_the_same_world(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $treasure = $world->rollTables()->create(['name' => 'Treasure', 'die' => 6, 'rows' => []]);

        $this->actingAs($gm)->post(route('tables.store', $world->id), [
            'name' => 'Encounters',
            'die' => 6,
            'rows' => [
                ['min' => 1, 'max' => 6, 'result' => 'Dragon hoard', 'link' => $treasure->id],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $encounters = $world->rollTables()->where('name', 'Encounters')->firstOrFail();
        $this->assertSame($treasure->id, $encounters->rows[0]['link']);
    }

    public function test_a_row_cannot_link_to_a_table_in_another_world(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $otherWorld = $gm->worlds()->create(['name' => 'Other', 'visibility' => 'public']);
        $foreign = $otherWorld->rollTables()->create(['name' => 'Foreign', 'die' => 6, 'rows' => []]);

        $this->actingAs($gm)->post(route('tables.store', $world->id), [
            'name' => 'Encounters',
            'die' => 6,
            'rows' => [
                ['min' => 1, 'max' => 6, 'result' => 'X', 'link' => $foreign->id],
            ],
        ])->assertSessionHasErrors('rows.0.link');
    }

    public function test_a_gm_can_update_a_table(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $table = $world->rollTables()->create(['name' => 'Old', 'die' => 20, 'rows' => []]);

        $this->actingAs($gm)->put(route('tables.update', $table->id), [
            'name' => 'New',
            'die' => 6,
            'rows' => [['min' => 1, 'max' => 6, 'result' => 'Something']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('roll_tables', ['id' => $table->id, 'name' => 'New', 'die' => 6]);
    }

    public function test_a_gm_can_delete_a_table(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $table = $world->rollTables()->create(['name' => 'Doomed', 'die' => 20, 'rows' => []]);

        $this->actingAs($gm)->delete(route('tables.destroy', $table->id))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('roll_tables', ['id' => $table->id]);
    }

    public function test_a_stranger_cannot_manage_tables(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('tables.store', $world->id), [
            'name' => 'X', 'die' => 20, 'rows' => [],
        ])->assertForbidden();
    }

    public function test_ai_generates_rows_clamped_to_the_die(): void
    {
        $this->fakeAi([
            ['min' => 1, 'max' => 3, 'result' => 'Rain', 'detail' => 'A grey drizzle.'],
            ['min' => 4, 'max' => 99, 'result' => 'Storm', 'detail' => 'Lightning splits the sky.'],
            ['min' => 5, 'max' => 6, 'result' => '', 'detail' => 'dropped — no result'],
        ]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public', 'setting' => 'stormy coast']);

        $response = $this->actingAs($gm)->postJson(route('tables.generate', $world->id), [
            'prompt' => 'coastal weather',
            'die' => 6,
        ])->assertOk();

        $response->assertJson([
            'rows' => [
                ['min' => 1, 'max' => 3, 'result' => 'Rain'],
                ['min' => 4, 'max' => 6, 'result' => 'Storm'],
            ],
            'creditsRemaining' => 4,
        ]);
        $response->assertJsonCount(2, 'rows');
    }

    public function test_ai_replies_conversationally_and_carries_history(): void
    {
        $this->fakeAi([['min' => 1, 'max' => 6, 'result' => 'A rumour']], 'Built you six harbour rumours.');
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)->postJson(route('tables.generate', $world->id), [
            'prompt' => 'make them spookier',
            'die' => 6,
            'history' => [
                ['role' => 'user', 'content' => 'harbour rumours'],
                ['role' => 'assistant', 'content' => 'Done.'],
            ],
            'existing' => [['min' => 1, 'max' => 6, 'result' => 'A dull rumour']],
        ])->assertOk()->assertJson(['reply' => 'Built you six harbour rumours.']);

        Http::assertSent(fn ($request) => count($request->data()['messages']) === 3);
    }

    public function test_ai_rows_are_salvaged_from_a_truncated_reply(): void
    {
        // Mimic a d100 reply cut off by the token cap: the outer object never closes, but three
        // complete row objects made it through and should still be recovered.
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        $truncated = '{"reply": "Here is your wild magic table.", "rows": ['
            .'{"min": 1, "max": 1, "result": "Cast fireball", "detail": "Zeus laughs."},'
            .'{"min": 2, "max": 2, "result": "Turn to stone", "detail": "Medusa winks."},'
            .'{"min": 3, "max": 3, "result": "Grow wings", "detail": "Icarus warns you."},'
            .'{"min": 4, "max": 4, "result": "Golden touch"';
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => $truncated]],
        ], 200)]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $response = $this->actingAs($gm)->postJson(route('tables.generate', $world->id), [
            'prompt' => 'greek wild magic', 'die' => 100,
        ])->assertOk();

        $response->assertJsonCount(3, 'rows');
        $response->assertJson(['rows' => [
            ['min' => 1, 'max' => 1, 'result' => 'Cast fireball'],
            ['min' => 2, 'max' => 2, 'result' => 'Turn to stone'],
            ['min' => 3, 'max' => 3, 'result' => 'Grow wings'],
        ]]);
    }

    public function test_ai_range_batch_clamps_rows_to_its_slice_and_avoids_used_results(): void
    {
        // The model is told to cover 34-67, but returns rows drifting outside; they clamp into the slice.
        $this->fakeAi([
            ['min' => 34, 'max' => 40, 'result' => 'Zeus intervenes'],
            ['min' => 41, 'max' => 200, 'result' => 'Hades laughs'],
        ], '');
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public', 'setting' => 'greek myth']);

        $response = $this->actingAs($gm)->postJson(route('tables.generate', $world->id), [
            'prompt' => 'wild magic',
            'die' => 100,
            'range' => ['min' => 34, 'max' => 67],
            'avoid' => ['Poseidon floods the room'],
        ])->assertOk();

        $response->assertJson(['rows' => [
            ['min' => 34, 'max' => 40, 'result' => 'Zeus intervenes'],
            ['min' => 41, 'max' => 67, 'result' => 'Hades laughs'],
        ]]);

        Http::assertSent(function ($request) {
            $system = $request->data()['system'] ?? '';

            return str_contains($system, '34 to 67')
                && str_contains($system, 'Poseidon floods the room');
        });
    }

    public function test_ai_prose_reply_without_json_is_reported_gracefully(): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'content' => [['type' => 'text', 'text' => 'Sorry, I could not build that.']],
        ], 200)]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->postJson(route('tables.generate', $world->id), [
            'prompt' => 'x', 'die' => 6,
        ])->assertStatus(422);
    }

    public function test_ai_generation_grounds_the_prompt_in_the_world_setting(): void
    {
        $this->fakeAi([['min' => 1, 'max' => 6, 'result' => 'A thing']]);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'Saltmere', 'visibility' => 'public', 'setting' => 'grimdark nautical fantasy',
        ]);

        $this->actingAs($gm)->postJson(route('tables.generate', $world->id), [
            'prompt' => 'harbour rumours', 'die' => 6,
        ])->assertOk();

        Http::assertSent(fn ($request) => str_contains($request->data()['system'] ?? '', 'grimdark nautical fantasy'));
    }
}
