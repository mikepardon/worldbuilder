<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\ProcessedCheckout;
use App\Models\User;
use App\Support\Plans;

/**
 * Applies normalised billing events to users. Shared by the webhook and the on-return confirmation,
 * so a purchase is fulfilled the moment either path sees it — and, thanks to the per-session guard,
 * only once no matter how many paths see it.
 */
class BillingFulfilment
{
    public function apply(BillingEvent $event): void
    {
        match ($event->type) {
            BillingEvent::CHECKOUT_COMPLETED => $this->applyCheckout($event),
            BillingEvent::SUBSCRIPTION_UPDATED => $this->applyUpdate($event),
            BillingEvent::SUBSCRIPTION_DELETED => $this->applyCancellation($event),
            BillingEvent::SUBSCRIPTION_RENEWED => $this->applyRenewal($event),
            default => null,
        };
    }

    /**
     * A subscription invoice was paid (first payment or a renewal): grant the plan's monthly credit
     * allotment to the top-up balance. Guarded by the invoice id so a cycle is never granted twice, even
     * if Stripe re-delivers the webhook. Daily free credits are still spent before this balance.
     */
    private function applyRenewal(BillingEvent $event): void
    {
        $user = $this->userByStripeIds($event);
        if ($user === null) {
            return;
        }

        // Claim this invoice; a re-delivered webhook finds it already recorded and stops here.
        if (filled($event->checkoutId)) {
            $claim = ProcessedCheckout::firstOrCreate(['checkout_id' => $event->checkoutId]);
            if (! $claim->wasRecentlyCreated) {
                return;
            }
        }

        $plan = filled($event->plan) ? $event->plan : (is_string($user->plan) ? $user->plan : 'free');
        $monthlyCredits = (int) (Plans::for($plan)['monthly_credits'] ?? 0);
        if ($monthlyCredits <= 0) {
            return;
        }

        $user->ai_credit_balance = (int) $user->ai_credit_balance + $monthlyCredits;
        $user->save();
    }

    /**
     * A completed checkout: a subscription (set the plan + record Stripe IDs) or a one-off top-up
     * (credit the purchased AI credits). Guarded by the session id so it can never be granted twice.
     */
    private function applyCheckout(BillingEvent $event): void
    {
        $user = $this->resolveUser($event);
        if ($user === null) {
            return;
        }

        // Claim this checkout; if it was already recorded, another path fulfilled it — stop here.
        if (filled($event->checkoutId)) {
            $claim = ProcessedCheckout::firstOrCreate(['checkout_id' => $event->checkoutId]);
            if (! $claim->wasRecentlyCreated) {
                return;
            }
        }

        if (filled($event->plan)) {
            $user->plan = $event->plan;
            if (filled($event->customerId)) {
                $user->stripe_customer_id = $event->customerId;
            }
            if (filled($event->subscriptionId)) {
                $user->stripe_subscription_id = $event->subscriptionId;
            }
            $user->save();

            return;
        }

        if ($event->credits !== null && $event->credits > 0) {
            if (filled($event->customerId)) {
                $user->stripe_customer_id = $event->customerId;
            }
            $user->ai_credit_balance = (int) $user->ai_credit_balance + $event->credits;
            $user->save();
        }
    }

    /** A subscription changed (e.g. via the billing portal): reflect the plan, or drop to Free if lapsed. */
    private function applyUpdate(BillingEvent $event): void
    {
        $user = $this->resolveUser($event);
        if ($user === null) {
            return;
        }

        if (in_array($event->status, ['canceled', 'unpaid', 'incomplete_expired'], true)) {
            $user->plan = 'free';
            $user->stripe_subscription_id = null;
            $user->save();

            return;
        }

        if (in_array($event->status, ['active', 'trialing'], true) && filled($event->plan)) {
            $user->plan = $event->plan;
            if (filled($event->subscriptionId)) {
                $user->stripe_subscription_id = $event->subscriptionId;
            }
            $user->save();
        }
    }

    /** A subscription ended at Stripe (cancelled, unpaid): drop the user back to Free. */
    private function applyCancellation(BillingEvent $event): void
    {
        $user = $this->userByStripeIds($event);
        if ($user === null) {
            return;
        }

        $user->plan = 'free';
        $user->stripe_subscription_id = null;
        $user->save();
    }

    private function resolveUser(BillingEvent $event): ?User
    {
        if ($event->userId !== null) {
            $user = User::find($event->userId);
            if ($user !== null) {
                return $user;
            }
        }

        return $this->userByStripeIds($event);
    }

    private function userByStripeIds(BillingEvent $event): ?User
    {
        if ($event->customerId !== null) {
            $user = User::where('stripe_customer_id', $event->customerId)->first();
            if ($user !== null) {
                return $user;
            }
        }

        return $event->subscriptionId !== null
            ? User::where('stripe_subscription_id', $event->subscriptionId)->first()
            : null;
    }
}
