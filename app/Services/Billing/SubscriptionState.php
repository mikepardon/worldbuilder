<?php

declare(strict_types=1);

namespace App\Services\Billing;

/** A snapshot of the user's current Stripe subscription, used to reconcile the plan and show pending changes. */
final readonly class SubscriptionState
{
    public function __construct(
        public string $plan,                 // the plan currently in effect (resolved from the price)
        public string $subscriptionId,
        public bool $cancelAtPeriodEnd,      // true when a downgrade to Free is scheduled
        public ?string $periodEndDate = null, // human date the current period ends
    ) {}
}
