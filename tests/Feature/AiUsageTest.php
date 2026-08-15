<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiModelPrice;
use App\Models\AiSetting;
use App\Models\AiUsageEvent;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AiUsageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        return $admin;
    }

    private function fakeAiWithUsage(int $inputTokens, int $outputTokens): void
    {
        config(['services.anthropic.key' => 'test-key', 'services.anthropic.model' => 'claude-sonnet-4-6']);
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model' => 'claude-sonnet-4-6',
            'content' => [['type' => 'text', 'text' => json_encode(['items' => [['title' => 'Aria', 'detail' => 'A smuggler.']]])]],
            'usage' => ['input_tokens' => $inputTokens, 'output_tokens' => $outputTokens],
        ], 200)]);
    }

    public function test_a_metered_call_records_a_usage_event_with_tokens_and_cost(): void
    {
        $this->fakeAiWithUsage(1000, 500);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($gm)->postJson(route('generators.run', $world), ['kind' => 'npc', 'count' => 1])->assertOk();

        $event = AiUsageEvent::query()->sole();
        $this->assertSame('generator', $event->feature);
        $this->assertSame('npc', $event->detail);
        $this->assertSame($world->id, $event->world_id);
        $this->assertSame($gm->id, $event->user_id);
        $this->assertSame(1000, $event->input_tokens);
        $this->assertSame(500, $event->output_tokens);
        // Sonnet seed price 300/1500 cents per 1M → (1000×300 + 500×1500) × 10 = 10,500,000 nanodollars.
        $this->assertSame(10_500_000, $event->cost_nanos);
        $this->assertSame('claude-sonnet-4-6', $event->model);
    }

    public function test_the_admin_page_aggregates_usage_overall_and_by_world(): void
    {
        $admin = $this->admin();
        $world = $admin->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        AiUsageEvent::create([
            'user_id' => $admin->id, 'world_id' => $world->id, 'feature' => 'generator',
            'detail' => 'npc', 'model' => 'claude-sonnet-4-6',
            'input_tokens' => 1000, 'output_tokens' => 500, 'cost_nanos' => 10_500_000,
        ]);
        AiUsageEvent::create([
            'user_id' => $admin->id, 'world_id' => $world->id, 'feature' => 'assistant_ask',
            'model' => 'claude-sonnet-4-6',
            'input_tokens' => 2000, 'output_tokens' => 1000, 'cost_nanos' => 21_000_000,
        ]);

        $this->actingAs($admin)->get(route('admin.ai-usage.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Admin/AiUsage')
            ->where('totals.calls', 2)
            ->where('totals.input_tokens', 3000)
            ->where('totals.cost', '$0.03') // 31,500,000 nanodollars → $0.0315 → $0.03
            ->where('byWorld.0.name', 'Saltmere')
            ->where('group', 'type')
            ->has('breakdown', 2)
            ->has('prices'));
    }

    /** Seeds two recap analyses (the expensive type) and one cheaper NPC generation. */
    private function seedTypedUsage(User $admin): World
    {
        $world = $admin->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        foreach ([1, 2] as $ignored) {
            AiUsageEvent::create([
                'user_id' => $admin->id, 'world_id' => $world->id, 'feature' => 'session_recap',
                'detail' => 'analyze', 'model' => 'claude-sonnet-4-6',
                'input_tokens' => 40000, 'output_tokens' => 10000, 'cost_nanos' => 30_000_000,
            ]);
        }
        AiUsageEvent::create([
            'user_id' => $admin->id, 'world_id' => $world->id, 'feature' => 'generator',
            'detail' => 'npc', 'model' => 'claude-sonnet-4-6',
            'input_tokens' => 1000, 'output_tokens' => 500, 'cost_nanos' => 10_500_000,
        ]);

        return $world;
    }

    public function test_the_breakdown_groups_by_content_type_by_default_with_a_per_call_average(): void
    {
        $admin = $this->admin();
        $this->seedTypedUsage($admin);

        $this->actingAs($admin)->get(route('admin.ai-usage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('group', 'type')
            ->has('breakdown', 2)
            // Highest cost first: Recap totals 60,000,000 nanos over 2 calls → $0.03/call.
            ->where('breakdown.0.label', 'Recap')
            ->where('breakdown.0.calls', 2)
            ->where('breakdown.0.avg_cost', '$0.0300')
            ->where('breakdown.1.label', 'NPC')
            ->where('breakdown.1.avg_cost', '$0.0105'));
    }

    public function test_the_breakdown_can_be_grouped_by_prompt_style(): void
    {
        $admin = $this->admin();
        $this->seedTypedUsage($admin);

        $this->actingAs($admin)->get(route('admin.ai-usage.index', ['group' => 'feature']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('group', 'feature')
                ->has('breakdown', 2)
                ->where('breakdown.0.label', 'Session recap')
                ->where('breakdown.1.label', 'Content generators'));
    }

    public function test_the_breakdown_can_be_grouped_by_model(): void
    {
        $admin = $this->admin();
        $this->seedTypedUsage($admin);

        $this->actingAs($admin)->get(route('admin.ai-usage.index', ['group' => 'model']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('group', 'model')
                ->has('breakdown', 1)
                ->where('breakdown.0.label', 'claude-sonnet-4-6'));
    }

    public function test_the_credit_weighting_review_schedules_credits_and_shows_the_blended_margin(): void
    {
        $admin = $this->admin();
        $this->seedTypedUsage($admin); // 2 recaps + 1 NPC → 2×30 + 1×1 = 61 credits.

        $this->actingAs($admin)->get(route('admin.ai-usage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('creditCents', 10)
            ->where('creditReview.credit_value', '$0.10')
            ->where('creditReview.schedule.0.type', 'Recap')
            ->where('creditReview.schedule.0.credits', 30)
            ->where('creditReview.usage.credits', 61)
            // 61 credits × $0.10 = $6.10 revenue against $0.0705 cost → 86.5× blended.
            ->where('creditReview.usage.revenue', '$6.10')
            ->where('creditReview.usage.markup', '86.5×'));
    }

    public function test_the_credit_review_price_responds_to_the_selected_credit_value(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.ai-usage.index', ['credit' => 5]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('creditCents', 5)
                ->where('creditReview.credit_value', '$0.05')
                // Recap: 30 credits × $0.05 = $1.50.
                ->where('creditReview.schedule.0.price', '$1.50'));
    }

    public function test_the_recap_pricing_review_bills_by_audio_hour(): void
    {
        $admin = $this->admin();
        $world = $admin->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $session = $world->campaigns()->firstOrFail()->sessions()->create(['title' => 'S']);
        $session->recap()->create([
            'user_id' => $admin->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done', 'duration_seconds' => 9000, // 2.5 hours
        ]);

        $this->actingAs($admin)->get(route('admin.ai-usage.index'))->assertInertia(fn (Assert $page) => $page
            ->where('recapRate', 12)
            ->where('recapReview.total_hours', '2.5')
            ->where('recapReview.examples.0.credits', 12)  // 1 hour @ 12/hr
            ->where('recapReview.examples.1.credits', 30)); // 2.5 hours @ 12/hr
    }

    public function test_the_recap_rate_can_be_reviewed_at_a_lower_per_hour_value(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.ai-usage.index', ['recapRate' => 8]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('recapRate', 8)
                ->where('recapReview.examples.1.credits', 20)); // 2.5 hours @ 8/hr = 20 credits
    }

    public function test_an_admin_can_update_model_pricing(): void
    {
        $admin = $this->admin();
        $price = AiModelPrice::query()->where('model', 'claude-sonnet-4-6')->sole();

        $this->actingAs($admin)->put(route('admin.ai-usage.pricing.update'), [
            'prices' => [['id' => $price->id, 'input_price' => 400, 'output_price' => 1600]],
        ])->assertRedirect();

        $this->assertDatabaseHas('ai_model_prices', ['id' => $price->id, 'input_price' => 400, 'output_price' => 1600]);
    }

    public function test_a_non_admin_cannot_view_ai_usage(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('admin.ai-usage.index'))->assertForbidden();
    }

    public function test_an_admin_can_set_the_default_model_and_assistant_name(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.ai-usage.settings.update'), [
            'default_model' => 'claude-opus-4-8',
            'assistant_name' => 'Sage',
        ])->assertRedirect();

        $this->assertDatabaseHas('ai_settings', ['default_model' => 'claude-opus-4-8', 'assistant_name' => 'Sage']);
    }

    public function test_the_default_model_must_be_one_with_a_price(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put(route('admin.ai-usage.settings.update'), [
            'default_model' => 'gpt-4', 'assistant_name' => 'Muse',
        ])->assertSessionHasErrors('default_model');
    }

    public function test_an_admin_can_override_a_worlds_model(): void
    {
        $admin = $this->admin();
        $world = $admin->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        $this->actingAs($admin)->put(route('admin.worlds.ai-model', $world), ['ai_model' => 'claude-haiku-4-5'])->assertRedirect();
        $this->assertSame('claude-haiku-4-5', $world->fresh()->ai_model);

        // Clearing it falls back to the global default.
        $this->actingAs($admin)->put(route('admin.worlds.ai-model', $world), ['ai_model' => null])->assertRedirect();
        $this->assertNull($world->fresh()->ai_model);
    }

    public function test_a_worlds_model_override_is_sent_to_the_ai(): void
    {
        $this->fakeAiWithUsage(10, 10);
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public', 'ai_model' => 'claude-opus-4-8']);

        $this->actingAs($gm)->postJson(route('generators.run', $world), ['kind' => 'npc', 'count' => 1])->assertOk();

        // The request to Claude carries the world's model, not the global default.
        Http::assertSent(fn ($request) => ($request->data()['model'] ?? null) === 'claude-opus-4-8');
    }

    public function test_the_assistant_name_is_shared_to_the_frontend_but_the_model_is_not(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        AiSetting::current()->update(['assistant_name' => 'Muse', 'default_model' => 'claude-sonnet-4-6']);

        $this->actingAs($gm)->get(route('generators.index', $world))->assertInertia(fn (Assert $page) => $page
            ->where('ai.name', 'Muse')
            ->missing('ai.model'));
    }
}
