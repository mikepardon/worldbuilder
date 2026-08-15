<?php

declare(strict_types=1);

namespace App\Services\Billing;

/** The outcome of a requested plan change, so the controller knows how to respond to the user. */
final readonly class PlanChange
{
    /** Needs hosted Checkout (starting a first subscription). */
    public const CHECKOUT = 'checkout';

    /** Applied immediately (an upgrade, prorated difference charged now). */
    public const UPGRADED = 'upgraded';

    /** Takes effect at the end of the current billing period (a downgrade). */
    public const SCHEDULED = 'scheduled';

    public function __construct(
        public string $outcome,
        public ?string $checkoutUrl = null,
        public ?string $plan = null,          // the plan now in effect (UPGRADED)
        public ?string $scheduledPlan = null, // the plan taking effect later (SCHEDULED)
        public ?string $effectiveDate = null, // human date a scheduled change lands
    ) {}
}
