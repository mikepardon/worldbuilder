<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\User;

/**
 * The boundary to the payment provider (Stripe). Everything the app needs from billing goes through
 * here so the rest of the code — and the tests — never depend on the Stripe SDK directly.
 */
interface BillingGateway
{
    /**
     * Move the user to a plan, applying the right billing-cycle behaviour: start a subscription via
     * hosted checkout when they have none, upgrade immediately with a prorated charge, or schedule a
     * downgrade for the end of the paid period.
     */
    public function changePlan(User $user, string $targetPlan, string $successUrl, string $cancelUrl): PlanChange;

    /** A hosted-checkout URL for a one-off AI-credit top-up bundle. */
    public function topUpCheckoutUrl(User $user, string $bundleKey, string $successUrl, string $cancelUrl): string;

    /** A Stripe Billing Portal URL so the user can manage/cancel their subscription and card. */
    public function billingPortalUrl(User $user, string $returnUrl): string;

    /** Undo a scheduled downgrade (release a schedule and/or clear a pending cancellation). */
    public function cancelScheduledChange(User $user): void;

    /** The user's current subscription snapshot, so the plan can self-heal and pending changes show; null if none. */
    public function subscriptionState(User $user): ?SubscriptionState;

    /** Verify and normalise a raw webhook request; null when the signature is invalid. */
    public function webhookEvent(string $payload, ?string $signature): ?BillingEvent;

    /** Retrieve a completed Checkout Session as an event so a purchase can be confirmed on return; null if not paid. */
    public function checkoutSession(string $sessionId): ?BillingEvent;
}
