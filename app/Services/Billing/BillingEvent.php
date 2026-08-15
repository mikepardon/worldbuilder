<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * A normalised, provider-agnostic billing webhook event. The gateway verifies and translates the raw
 * Stripe event into this shape so the webhook controller never touches the Stripe SDK directly.
 */
final readonly class BillingEvent
{
    public const CHECKOUT_COMPLETED = 'checkout.session.completed';

    public const SUBSCRIPTION_UPDATED = 'customer.subscription.updated';

    public const SUBSCRIPTION_DELETED = 'customer.subscription.deleted';

    public function __construct(
        public string $type,
        public ?int $userId = null,
        public ?string $plan = null,
        public ?string $customerId = null,
        public ?string $subscriptionId = null,
        // A one-off top-up purchase grants this many AI credits.
        public ?int $credits = null,
        // Subscription lifecycle status (active, trialing, canceled, unpaid, …).
        public ?string $status = null,
        // The Checkout Session id — the idempotency key so a purchase is fulfilled only once.
        public ?string $checkoutId = null,
    ) {}
}
