<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\AnthropicClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class BloodlineAiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeAi(string $reply): void
    {
        $ai = Mockery::mock(AnthropicClient::class);
        $ai->shouldReceive('configured')->andReturnTrue();
        $ai->shouldReceive('chat')->andReturn($reply);
        $this->app->instance(AnthropicClient::class, $ai);
    }

    public function test_muse_generates_a_bloodline_and_drops_dangling_references(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public', 'ai_generation_limit' => 5, 'ai_generations_used' => 0,
        ]);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'House X', 'kind' => 'bloodline', 'slug' => 'house-x', 'content' => '',
        ]);

        $this->fakeAi(json_encode(['members' => [
            ['id' => 'a', 'name' => 'Founder', 'subtitle' => 'The First', 'parents' => [], 'partners' => ['b']],
            ['id' => 'b', 'name' => 'Consort', 'parents' => []],
            ['id' => 'c', 'name' => 'Heir', 'parents' => ['a', 'ghost', 'c'], 'partners' => []],
            ['id' => 'd', 'name' => '', 'parents' => []], // no name → dropped
        ]]));

        $this->actingAs($gm)->postJson(route('documents.ai.bloodline', $document), ['prompt' => 'a merchant dynasty'])
            ->assertOk()
            ->assertJsonCount(3, 'members')
            ->assertJsonPath('members.0.name', 'Founder')
            ->assertJsonPath('members.0.partners', ['b'])
            // "ghost" is undefined and "c" is a self-reference — both dropped.
            ->assertJsonPath('members.2.parents', ['a']);
    }

    public function test_muse_rejects_a_bloodline_prompt_from_a_non_owner(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $document = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'House X', 'kind' => 'bloodline', 'slug' => 'house-x', 'content' => '',
        ]);
        $this->fakeAi('{"members":[]}');

        $this->actingAs(User::factory()->create())
            ->postJson(route('documents.ai.bloodline', $document), ['prompt' => 'x'])
            ->assertForbidden();
    }
}
