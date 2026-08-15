<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;
use App\Support\Billing;
use App\Support\TopUps;
use Carbon\CarbonImmutable;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

/** Stripe-backed billing gateway. Reads keys from the admin-managed settings via App\Support\Billing. */
class StripeBillingGateway implements BillingGateway
{
    /** @var array<string, int> Plan tiers, low to high, for deciding upgrade vs downgrade. */
    private const RANK = ['free' => 0, 'basic' => 1, 'pro' => 2];

    public function changePlan(User $user, string $targetPlan, string $successUrl, string $cancelUrl): PlanChange
    {
        $client = $this->client();
        $subscription = $this->currentSubscription($user, $client);

        // No active subscription yet — start one through hosted Checkout (or nothing to do for Free).
        if ($subscription === null) {
            return $targetPlan === 'free'
                ? new PlanChange(outcome: PlanChange::UPGRADED, plan: 'free')
                : new PlanChange(outcome: PlanChange::CHECKOUT, checkoutUrl: $this->checkoutUrl($user, $targetPlan, $successUrl, $cancelUrl));
        }

        $item = $subscription->items->data[0];
        $currentPlan = Billing::planForPrice($item->price->id ?? null) ?? ($user->plan ?? 'free');
        $periodEndDate = $this->formatDate($subscription->current_period_end ?? null);

        // Downgrade to Free — cancel at period end so they keep the paid tier until then.
        if ($targetPlan === 'free') {
            $client->subscriptions->update($subscription->id, [
                'cancel_at_period_end' => true,
                'metadata' => ['user_id' => (string) $user->id, 'plan' => $currentPlan],
            ]);

            return new PlanChange(outcome: PlanChange::SCHEDULED, scheduledPlan: 'free', effectiveDate: $periodEndDate);
        }

        // Upgrade — swap the price now and invoice the prorated difference immediately.
        if (($this->rank($targetPlan)) > $this->rank($currentPlan)) {
            $client->subscriptions->update($subscription->id, [
                'items' => [['id' => $item->id, 'price' => Billing::priceId($targetPlan)]],
                'proration_behavior' => 'always_invoice',
                'cancel_at_period_end' => false,
                'metadata' => ['user_id' => (string) $user->id, 'plan' => $targetPlan],
            ]);

            return new PlanChange(outcome: PlanChange::UPGRADED, plan: $targetPlan);
        }

        // Downgrade to a lower paid tier — schedule the switch for the end of the current period.
        $schedule = $client->subscriptionSchedules->create(['from_subscription' => $subscription->id]);
        $client->subscriptionSchedules->update($schedule->id, [
            'end_behavior' => 'release',
            'phases' => [
                [
                    'items' => [['price' => $item->price->id, 'quantity' => 1]],
                    'start_date' => $subscription->current_period_start,
                    'end_date' => $subscription->current_period_end,
                ],
                [
                    'items' => [['price' => Billing::priceId($targetPlan), 'quantity' => 1]],
                    'metadata' => ['user_id' => (string) $user->id, 'plan' => $targetPlan],
                ],
            ],
        ]);

        return new PlanChange(outcome: PlanChange::SCHEDULED, scheduledPlan: $targetPlan, effectiveDate: $periodEndDate);
    }

    public function topUpCheckoutUrl(User $user, string $bundleKey, string $successUrl, string $cancelUrl): string
    {
        $bundle = TopUps::find($bundleKey);
        if ($bundle === null) {
            throw new RuntimeException("Unknown top-up bundle: {$bundleKey}.");
        }

        $client = $this->client();
        $customerId = $this->customerId($user, $client);

        // An ad-hoc one-off charge — no pre-created Stripe price needed.
        $session = $client->checkout->sessions->create([
            'mode' => 'payment',
            'customer' => $customerId,
            'line_items' => [[
                'price_data' => [
                    'currency' => 'gbp',
                    'unit_amount' => $bundle['price'],
                    'product_data' => ['name' => $bundle['name']],
                ],
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['user_id' => (string) $user->id, 'credits' => (string) $bundle['credits']],
        ]);

        return (string) $session->url;
    }

    public function billingPortalUrl(User $user, string $returnUrl): string
    {
        if (blank($user->stripe_customer_id)) {
            throw new RuntimeException('This user has no Stripe customer yet.');
        }

        $session = $this->client()->billingPortal->sessions->create([
            'customer' => (string) $user->stripe_customer_id,
            'return_url' => $returnUrl,
        ]);

        return (string) $session->url;
    }

    public function cancelScheduledChange(User $user): void
    {
        $client = $this->client();
        $subscription = $this->currentSubscription($user, $client);
        if ($subscription === null) {
            return;
        }

        // Undo a scheduled paid → paid downgrade by releasing the schedule (the subscription continues
        // unchanged on its current price).
        if (filled($subscription->schedule ?? null)) {
            try {
                $client->subscriptionSchedules->release((string) $subscription->schedule);
            } catch (InvalidRequestException) {
                // Already released or gone — nothing to do.
            }
        }

        // Undo a scheduled cancellation (a downgrade to Free).
        if ($subscription->cancel_at_period_end ?? false) {
            $client->subscriptions->update($subscription->id, ['cancel_at_period_end' => false]);
        }
    }

    public function subscriptionState(User $user): ?SubscriptionState
    {
        $client = $this->client();
        $subscription = $this->currentSubscription($user, $client);
        if ($subscription === null) {
            return null;
        }

        $item = $subscription->items->data[0];
        $plan = Billing::planForPrice($item->price->id ?? null)
            ?? $this->stringOrNull($subscription->metadata->plan ?? null)
            ?? ($user->plan ?? 'free');

        return new SubscriptionState(
            plan: $plan,
            subscriptionId: (string) $subscription->id,
            cancelAtPeriodEnd: (bool) ($subscription->cancel_at_period_end ?? false),
            periodEndDate: $this->formatDate($subscription->current_period_end ?? null),
        );
    }

    public function webhookEvent(string $payload, ?string $signature): ?BillingEvent
    {
        $secret = Billing::webhookSecret();
        if (blank($secret) || $signature === null) {
            return null;
        }

        try {
            $event = Webhook::constructEvent($payload, $signature, $secret);
        } catch (UnexpectedValueException|SignatureVerificationException) {
            return null;
        }

        $object = $event->data->object;

        return match ($event->type) {
            BillingEvent::CHECKOUT_COMPLETED => new BillingEvent(
                type: $event->type,
                userId: $this->intOrNull($object->metadata->user_id ?? null),
                plan: $this->stringOrNull($object->metadata->plan ?? null),
                customerId: $this->stringOrNull($object->customer ?? null),
                subscriptionId: $this->stringOrNull($object->subscription ?? null),
                credits: $this->intOrNull($object->metadata->credits ?? null),
                checkoutId: $this->stringOrNull($object->id ?? null),
            ),
            BillingEvent::SUBSCRIPTION_UPDATED => new BillingEvent(
                type: $event->type,
                userId: $this->intOrNull($object->metadata->user_id ?? null),
                // Prefer the price → plan mapping; metadata is a fallback.
                plan: Billing::planForPrice($object->items->data[0]->price->id ?? null)
                    ?? $this->stringOrNull($object->metadata->plan ?? null),
                customerId: $this->stringOrNull($object->customer ?? null),
                subscriptionId: $this->stringOrNull($object->id ?? null),
                status: $this->stringOrNull($object->status ?? null),
            ),
            BillingEvent::SUBSCRIPTION_DELETED => new BillingEvent(
                type: $event->type,
                customerId: $this->stringOrNull($object->customer ?? null),
                subscriptionId: $this->stringOrNull($object->id ?? null),
            ),
            BillingEvent::SUBSCRIPTION_RENEWED => new BillingEvent(
                type: $event->type,
                // The plan is derived from the invoiced subscription's price; fulfilment grants its credits.
                plan: Billing::planForPrice($object->lines->data[0]->price->id ?? null),
                customerId: $this->stringOrNull($object->customer ?? null),
                subscriptionId: $this->stringOrNull($object->subscription ?? null),
                // The invoice id is the idempotency key so a cycle's credits are granted exactly once.
                checkoutId: $this->stringOrNull($object->id ?? null),
            ),
            default => new BillingEvent(type: $event->type),
        };
    }

    public function checkoutSession(string $sessionId): ?BillingEvent
    {
        $session = $this->client()->checkout->sessions->retrieve($sessionId);

        // Only fulfil a genuinely completed, paid session (subscriptions and one-off payments both
        // report 'paid' here once settled).
        if (($session->status ?? null) !== 'complete' || ($session->payment_status ?? null) !== 'paid') {
            return null;
        }

        return new BillingEvent(
            type: BillingEvent::CHECKOUT_COMPLETED,
            userId: $this->intOrNull($session->metadata->user_id ?? null),
            plan: $this->stringOrNull($session->metadata->plan ?? null),
            customerId: $this->stringOrNull($session->customer ?? null),
            subscriptionId: $this->stringOrNull($session->subscription ?? null),
            credits: $this->intOrNull($session->metadata->credits ?? null),
            checkoutId: $this->stringOrNull($session->id ?? null),
        );
    }

    /** The user's current active/trialing Stripe subscription object, or null. */
    private function currentSubscription(User $user, StripeClient $client): mixed
    {
        if (blank($user->stripe_customer_id)) {
            return null;
        }

        $subscriptions = $client->subscriptions->all([
            'customer' => (string) $user->stripe_customer_id,
            'limit' => 10,
        ]);

        foreach ($subscriptions->data as $subscription) {
            if (in_array($subscription->status ?? null, ['active', 'trialing'], true)) {
                return $subscription;
            }
        }

        return null;
    }

    private function checkoutUrl(User $user, string $planKey, string $successUrl, string $cancelUrl): string
    {
        $priceId = Billing::priceId($planKey);
        if (blank($priceId)) {
            throw new RuntimeException("No Stripe price ID is configured for the {$planKey} plan.");
        }

        $client = $this->client();
        $customerId = $this->customerId($user, $client);

        $session = $client->checkout->sessions->create([
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [['price' => $priceId, 'quantity' => 1]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => ['user_id' => (string) $user->id, 'plan' => $planKey],
            'subscription_data' => ['metadata' => ['user_id' => (string) $user->id, 'plan' => $planKey]],
        ]);

        return (string) $session->url;
    }

    private function client(): StripeClient
    {
        $secret = Billing::secretKey();
        if (blank($secret)) {
            throw new RuntimeException('Stripe is not configured — no secret key for the active mode.');
        }

        return new StripeClient($secret);
    }

    /** Reuse the user's Stripe customer, creating (and remembering) one on first checkout. */
    private function customerId(User $user, StripeClient $client): string
    {
        if (filled($user->stripe_customer_id)) {
            return (string) $user->stripe_customer_id;
        }

        $customer = $client->customers->create([
            'email' => $user->email,
            'name' => $user->name,
            'metadata' => ['user_id' => (string) $user->id],
        ]);

        $user->stripe_customer_id = $customer->id;
        $user->save();

        return $customer->id;
    }

    private function rank(string $plan): int
    {
        return self::RANK[$plan] ?? 0;
    }

    private function formatDate(?int $timestamp): ?string
    {
        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp($timestamp)->format('Y-m-d');
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function intOrNull(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
