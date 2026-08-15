<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_cannot_see_the_billing_page(): void
    {
        $this->get(route('billing.index'))->assertRedirect(route('login'));
    }

    public function test_it_shows_the_users_current_plan_and_usage(): void
    {
        $user = User::factory()->create([
            'plan' => 'basic',
            'ai_credit_balance' => 12,
            'daily_ai_used' => 4,
            'daily_ai_reset_on' => now()->toDateString(),
        ]);

        $this->actingAs($user)->get(route('billing.index'))->assertInertia(fn (Assert $page) => $page
            ->component('Billing/Index')
            ->where('currentPlan', 'basic')
            ->where('usage.daily_allowance', 5)   // every plan now grants 5 free credits/day
            ->where('usage.ai_used_today', 4)
            ->where('usage.credit_balance', 12)
            ->where('usage.world_limit', 3)
            ->where('billingEnabled', false)
            ->where('plans.1.key', 'basic')
            ->where('plans.1.is_current', true)
            ->where('plans.0.is_current', false));
    }

    public function test_choosing_a_plan_reports_that_payments_are_not_enabled_yet(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'pro'])
            ->assertRedirect()
            ->assertSessionHas('error');

        // No free upgrade sneaks through while payments are off.
        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_selecting_the_current_plan_is_rejected(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'free'])
            ->assertSessionHas('error');
    }

    public function test_an_unknown_plan_is_rejected(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'diamond'])
            ->assertSessionHasErrors('plan');
    }
}
