<?php

namespace Tests\Feature;

use App\Models\BillingSetting;
use App\Models\User;
use App\Services\Billing\BillingEvent;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\PlanChange;
use App\Services\Billing\SubscriptionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeBillingTest extends TestCase
{
    use RefreshDatabase;

    private function configureStripe(): void
    {
        BillingSetting::current()->update([
            'mode' => 'sandbox',
            'test_publishable_key' => 'pk_test_x',
            'test_secret_key' => 'sk_test_x',
            'test_price_basic' => 'price_basic_test',
            'test_price_pro' => 'price_pro_test',
        ]);
    }

    public function test_starting_a_first_subscription_hands_off_to_stripe_checkout(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'free']);

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('changePlan')->once()->andReturn(
                new PlanChange(outcome: PlanChange::CHECKOUT, checkoutUrl: 'https://checkout.stripe.test/session_123'),
            );
        });

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'pro'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.test/session_123');
    }

    public function test_an_upgrade_is_applied_immediately(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'basic']);

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('changePlan')->once()->andReturn(
                new PlanChange(outcome: PlanChange::UPGRADED, plan: 'pro'),
            );
        });

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'pro'])
            ->assertSessionHas('success');

        $this->assertSame('pro', $user->fresh()->plan);
    }

    public function test_downgrading_to_free_is_scheduled_for_the_period_end(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'pro']);

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('changePlan')->once()->andReturn(
                new PlanChange(outcome: PlanChange::SCHEDULED, scheduledPlan: 'free', effectiveDate: '2030-01-01'),
            );
        });

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'free'])
            ->assertSessionHas('success');

        $fresh = $user->fresh();
        // Plan is unchanged now — they keep Pro until the period ends.
        $this->assertSame('pro', $fresh->plan);
        $this->assertSame('free', $fresh->pending_plan);
        $this->assertSame('2030-01-01', $fresh->pending_plan_at->toDateString());
    }

    public function test_downgrading_to_a_lower_paid_tier_is_scheduled(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'pro']);

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('changePlan')->once()->andReturn(
                new PlanChange(outcome: PlanChange::SCHEDULED, scheduledPlan: 'basic', effectiveDate: '2030-01-01'),
            );
        });

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'basic'])
            ->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertSame('pro', $fresh->plan);
        $this->assertSame('basic', $fresh->pending_plan);
    }

    public function test_selecting_the_current_plan_is_rejected(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'free']);

        $this->actingAs($user)
            ->post(route('billing.checkout'), ['plan' => 'free'])
            ->assertSessionHas('error');
    }

    public function test_the_billing_page_self_heals_the_plan_from_an_active_subscription(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'free']);
        $user->stripe_customer_id = 'cus_sync';
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('subscriptionState')->once()->andReturn(
                new SubscriptionState(plan: 'pro', subscriptionId: 'sub_sync', cancelAtPeriodEnd: false, periodEndDate: '2030-01-01'),
            );
        });

        $this->actingAs($user)
            ->get(route('billing.index'))
            ->assertInertia(fn ($page) => $page->where('currentPlan', 'pro'));

        $this->assertSame('pro', $user->fresh()->plan);
    }

    public function test_the_billing_page_drops_a_lapsed_subscriber_to_free(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'pro']);
        $user->stripe_customer_id = 'cus_gone';
        $user->stripe_subscription_id = 'sub_gone';
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('subscriptionState')->once()->andReturnNull();
        });

        $this->actingAs($user)
            ->get(route('billing.index'))
            ->assertInertia(fn ($page) => $page->where('currentPlan', 'free'));

        $this->assertSame('free', $user->fresh()->plan);
    }

    public function test_a_completed_checkout_webhook_puts_the_user_on_the_paid_plan(): void
    {
        $user = User::factory()->create(['plan' => 'free']);

        $this->mock(BillingGateway::class, function ($mock) use ($user) {
            $mock->shouldReceive('webhookEvent')->once()->andReturn(new BillingEvent(
                type: BillingEvent::CHECKOUT_COMPLETED,
                userId: $user->id,
                plan: 'pro',
                customerId: 'cus_1',
                subscriptionId: 'sub_1',
                checkoutId: 'cs_hook',
            ));
        });

        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'sig'])->assertNoContent();

        $fresh = $user->fresh();
        $this->assertSame('pro', $fresh->plan);
        $this->assertSame('cus_1', $fresh->stripe_customer_id);
        $this->assertSame('sub_1', $fresh->stripe_subscription_id);
    }

    public function test_a_paid_invoice_grants_the_plans_monthly_credits(): void
    {
        $user = User::factory()->create(['plan' => 'basic', 'ai_credit_balance' => 10]);
        $user->stripe_customer_id = 'cus_r';
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('webhookEvent')->once()->andReturn(new BillingEvent(
                type: BillingEvent::SUBSCRIPTION_RENEWED,
                plan: 'basic',
                customerId: 'cus_r',
                subscriptionId: 'sub_r',
                checkoutId: 'in_month1',
            ));
        });

        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'sig'])->assertNoContent();

        // Basic grants 300 credits per cycle on top of the existing balance.
        $this->assertSame(310, $user->fresh()->ai_credit_balance);
    }

    public function test_monthly_credits_are_granted_only_once_per_invoice(): void
    {
        $user = User::factory()->create(['plan' => 'pro', 'ai_credit_balance' => 0]);
        $user->stripe_customer_id = 'cus_p2';
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('webhookEvent')->andReturn(new BillingEvent(
                type: BillingEvent::SUBSCRIPTION_RENEWED,
                plan: 'pro',
                customerId: 'cus_p2',
                subscriptionId: 'sub_p2',
                checkoutId: 'in_dup',
            ));
        });

        // A re-delivered invoice webhook must not grant Pro's 1000 credits twice.
        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'sig'])->assertNoContent();
        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'sig'])->assertNoContent();

        $this->assertSame(1000, $user->fresh()->ai_credit_balance);
    }

    public function test_a_subscription_deleted_webhook_drops_the_user_to_free(): void
    {
        $user = User::factory()->create(['plan' => 'pro']);
        $user->stripe_customer_id = 'cus_9';
        $user->stripe_subscription_id = 'sub_9';
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('webhookEvent')->once()->andReturn(new BillingEvent(
                type: BillingEvent::SUBSCRIPTION_DELETED,
                customerId: 'cus_9',
                subscriptionId: 'sub_9',
            ));
        });

        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'sig'])->assertNoContent();

        $fresh = $user->fresh();
        $this->assertSame('free', $fresh->plan);
        $this->assertNull($fresh->stripe_subscription_id);
    }

    public function test_a_webhook_with_an_invalid_signature_is_rejected(): void
    {
        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('webhookEvent')->once()->andReturnNull();
        });

        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'bad'])->assertStatus(400);
    }

    public function test_buying_a_top_up_hands_off_to_stripe(): void
    {
        $this->configureStripe();
        $user = User::factory()->create();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('topUpCheckoutUrl')->once()->andReturn('https://checkout.stripe.test/topup_1');
        });

        $this->actingAs($user)
            ->post(route('billing.topup'), ['bundle' => 'small'], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://checkout.stripe.test/topup_1');
    }

    public function test_an_unknown_top_up_bundle_is_rejected(): void
    {
        $this->configureStripe();
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('billing.topup'), ['bundle' => 'enormous'])
            ->assertSessionHasErrors('bundle');
    }

    public function test_a_top_up_is_confirmed_on_return_and_credits_are_added(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['ai_credit_balance' => 2]);

        $this->mock(BillingGateway::class, function ($mock) use ($user) {
            $mock->shouldReceive('subscriptionState')->andReturnNull();
            $mock->shouldReceive('checkoutSession')->once()->andReturn(new BillingEvent(
                type: BillingEvent::CHECKOUT_COMPLETED,
                userId: $user->id,
                customerId: 'cus_top',
                credits: 50,
                checkoutId: 'cs_topup',
            ));
        });

        $this->actingAs($user)
            ->get(route('billing.index', ['checkout' => 'success', 'session_id' => 'cs_topup']))
            ->assertOk();

        $this->assertSame(52, $user->fresh()->ai_credit_balance);
    }

    public function test_a_purchase_is_fulfilled_only_once_across_repeated_webhooks(): void
    {
        $user = User::factory()->create(['ai_credit_balance' => 0]);

        $this->mock(BillingGateway::class, function ($mock) use ($user) {
            $mock->shouldReceive('webhookEvent')->andReturn(new BillingEvent(
                type: BillingEvent::CHECKOUT_COMPLETED,
                userId: $user->id,
                customerId: 'cus_d',
                credits: 50,
                checkoutId: 'cs_dup',
            ));
        });

        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'sig'])->assertNoContent();
        $this->post(route('stripe.webhook'), [], ['Stripe-Signature' => 'sig'])->assertNoContent();

        $this->assertSame(50, $user->fresh()->ai_credit_balance);
    }

    public function test_managing_billing_requires_an_existing_stripe_customer(): void
    {
        $this->configureStripe();
        $user = User::factory()->create();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldNotReceive('billingPortalUrl');
        });

        $this->actingAs($user)
            ->post(route('billing.portal'))
            ->assertSessionHas('error');
    }

    public function test_the_billing_portal_redirects_a_stripe_customer(): void
    {
        $this->configureStripe();
        $user = User::factory()->create();
        $user->stripe_customer_id = 'cus_1';
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('billingPortalUrl')->once()->andReturn('https://billing.stripe.test/portal_1');
        });

        $this->actingAs($user)
            ->post(route('billing.portal'), [], ['X-Inertia' => 'true'])
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', 'https://billing.stripe.test/portal_1');
    }

    public function test_a_scheduled_downgrade_can_be_cancelled(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'pro']);
        $user->stripe_customer_id = 'cus_c';
        $user->pending_plan = 'basic';
        $user->pending_plan_at = now()->addDays(10);
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('cancelScheduledChange')->once();
        });

        $this->actingAs($user)
            ->post(route('billing.cancel-downgrade'))
            ->assertSessionHas('success');

        $fresh = $user->fresh();
        $this->assertNull($fresh->pending_plan);
        $this->assertNull($fresh->pending_plan_at);
    }

    public function test_cancelling_with_no_scheduled_change_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldNotReceive('cancelScheduledChange');
        });

        $this->actingAs($user)
            ->post(route('billing.cancel-downgrade'))
            ->assertSessionHas('error');
    }

    public function test_a_scheduled_downgrade_is_shown_on_the_billing_page(): void
    {
        $this->configureStripe();
        $user = User::factory()->create(['plan' => 'pro']);
        $user->stripe_customer_id = 'cus_p';
        $user->pending_plan = 'basic';
        $user->pending_plan_at = now()->addDays(10);
        $user->save();

        $this->mock(BillingGateway::class, function ($mock) {
            $mock->shouldReceive('subscriptionState')->andReturn(
                new SubscriptionState(plan: 'pro', subscriptionId: 'sub_p', cancelAtPeriodEnd: false, periodEndDate: '2030-01-01'),
            );
        });

        $this->actingAs($user)
            ->get(route('billing.index'))
            ->assertInertia(fn ($page) => $page
                ->where('currentPlan', 'pro')
                ->where('pending.plan', 'basic')
                ->where('pending.plan_name', 'Basic'));
    }
}
