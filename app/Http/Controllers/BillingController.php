<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Billing\BillingFulfilment;
use App\Services\Billing\BillingGateway;
use App\Services\Billing\PlanChange;
use App\Support\Billing;
use App\Support\Plans;
use App\Support\TopUps;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/** A player's own billing home: the plan they're on, what they've used, and how to change it. */
class BillingController extends Controller
{
    public function index(Request $request, BillingGateway $gateway, BillingFulfilment $fulfilment): Response
    {
        $user = $request->user();

        if (Billing::configured()) {
            // Self-heal the plan from Stripe's live subscription state on every visit, so a purchase
            // (or a lapse) is reflected even if the webhook never arrived. Cheap and always correct.
            try {
                $state = $gateway->subscriptionState($user);
                if ($state !== null) {
                    if (filled($state->plan) && ($state->plan !== ($user->plan ?? 'free') || $user->stripe_subscription_id !== $state->subscriptionId)) {
                        $user->plan = $state->plan;
                        $user->stripe_subscription_id = $state->subscriptionId;
                        $user->save();
                    }
                } elseif (filled($user->stripe_customer_id) && ($user->plan ?? 'free') !== 'free') {
                    // They had a subscription but no longer do — it was cancelled or lapsed.
                    $user->plan = 'free';
                    $user->stripe_subscription_id = null;
                    $user->save();
                }
            } catch (Throwable $e) {
                report($e);
            }

            // Confirm a just-completed one-off purchase (credit top-up) by its session id — top-ups are
            // not subscriptions. The fulfilment guard stops it double-applying against the webhook.
            $sessionId = $request->query('session_id');
            if ($request->query('checkout') === 'success' && is_string($sessionId)) {
                try {
                    $event = $gateway->checkoutSession($sessionId);
                    if ($event !== null && $event->userId === $user->id) {
                        $fulfilment->apply($event);
                        $user->refresh();
                    }
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        // Once a scheduled downgrade's date has passed, the change has landed — stop advertising it.
        if ($user->pending_plan_at !== null && $user->pending_plan_at->isPast()) {
            $user->pending_plan = null;
            $user->pending_plan_at = null;
            $user->save();
        }

        $current = $user->plan ?? 'free';
        $pending = $this->pendingChange($user, $current);

        return Inertia::render('Billing/Index', [
            'currentPlan' => $current,
            'usage' => [
                'ai_used_today' => $user->aiUsedToday(),
                'daily_allowance' => $user->dailyAiAllowance(),
                'credit_balance' => (int) $user->ai_credit_balance,
                'credits_remaining' => $user->aiCreditsRemaining(),
                'worlds_used' => $user->worlds()->count(),
                'world_limit' => $user->worldLimit(),
                'storage_used_display' => $this->bytesDisplay($user->storageUsedBytes()),
                'storage_limit_display' => $this->bytesDisplay($user->storageLimitBytes()),
            ],
            'plans' => collect(Plans::all())->map(fn (array $plan): array => [
                'key' => (string) $plan['key'],
                'name' => (string) $plan['name'],
                'price_display' => $this->priceDisplay((int) $plan['price']),
                'is_free' => (int) $plan['price'] === 0,
                'daily_credits' => (int) $plan['daily_credits'],
                'worlds' => (int) $plan['worlds'],
                'storage_display' => $this->storageDisplay((float) $plan['storage_gb']),
                'custom_domain' => (bool) $plan['custom_domain'],
                'blurb' => (string) $plan['blurb'],
                'is_current' => $plan['key'] === $current,
            ])->values()->all(),
            'topUps' => collect(TopUps::all())->map(fn (array $bundle): array => [
                'key' => $bundle['key'],
                'credits' => $bundle['credits'],
                'price_display' => $this->priceDisplay((int) $bundle['price']),
            ])->values()->all(),
            // Whether online payments are live yet (Stripe configured in admin). Drives the CTAs.
            'billingEnabled' => Billing::configured(),
            // Whether the user already has a Stripe customer (drives the "Manage billing" button).
            'hasBilling' => filled($user->stripe_customer_id),
            // Set when returning from Stripe Checkout, so the page can confirm or note a cancel.
            'checkoutStatus' => in_array($request->query('checkout'), ['success', 'cancel'], true)
                ? $request->query('checkout')
                : null,
            // A scheduled downgrade that takes effect at the end of the paid period, if any.
            'pending' => $pending,
        ]);
    }

    /**
     * Change plan. An upgrade is applied immediately with a prorated charge; a downgrade is scheduled
     * for the end of the paid period (so nothing already paid for is lost); a first subscription is
     * started via Stripe Checkout.
     */
    public function checkout(Request $request, BillingGateway $gateway): HttpResponse
    {
        $data = $request->validate([
            'plan' => ['required', 'in:free,basic,pro'],
        ]);

        $user = $request->user();
        $plan = $data['plan'];

        if ($plan === ($user->plan ?? 'free')) {
            return back()->with('error', "You're already on that plan.");
        }

        if (! Billing::configured()) {
            return back()->with('error', "Online payments aren't switched on yet — plan changes are coming soon.");
        }

        if ($plan !== 'free' && blank(Billing::priceId($plan))) {
            return back()->with('error', "That plan can't be purchased yet — its Stripe price ID isn't set.");
        }

        $change = $gateway->changePlan(
            $user,
            $plan,
            route('billing.index').'?checkout=success&session_id={CHECKOUT_SESSION_ID}',
            route('billing.index').'?checkout=cancel',
        );

        return match ($change->outcome) {
            PlanChange::CHECKOUT => Inertia::location((string) $change->checkoutUrl),
            PlanChange::UPGRADED => $this->confirmUpgrade($user, $change),
            default => $this->confirmScheduled($user, $user->plan ?? 'free', $change),
        };
    }

    /** Apply an immediate upgrade and clear any previously scheduled downgrade. */
    private function confirmUpgrade(User $user, PlanChange $change): HttpResponse
    {
        $user->plan = (string) $change->plan;
        $user->pending_plan = null;
        $user->pending_plan_at = null;
        $user->save();

        $name = $this->planName((string) $change->plan);

        return back()->with('success', "You're now on the {$name} plan — we've charged the prorated difference for the rest of this billing period.");
    }

    /** Record a scheduled downgrade for display; the plan itself changes at the period end. */
    private function confirmScheduled(User $user, string $current, PlanChange $change): HttpResponse
    {
        $user->pending_plan = (string) $change->scheduledPlan;
        $user->pending_plan_at = $change->effectiveDate !== null ? CarbonImmutable::parse($change->effectiveDate) : null;
        $user->save();

        $currentName = $this->planName($current);
        $targetName = $this->planName((string) $change->scheduledPlan);
        $when = $change->effectiveDate !== null ? CarbonImmutable::parse($change->effectiveDate)->format('j M Y') : 'the end of your billing period';

        return back()->with('success', "You'll keep {$currentName} until {$when}, then move to {$targetName}. Nothing you've already paid for is lost.");
    }

    /** Undo a scheduled downgrade so the user stays on their current plan. */
    public function cancelDowngrade(Request $request, BillingGateway $gateway): HttpResponse
    {
        $user = $request->user();

        if (blank($user->pending_plan)) {
            return back()->with('error', 'You have no scheduled change to cancel.');
        }

        if (Billing::configured()) {
            try {
                $gateway->cancelScheduledChange($user);
            } catch (Throwable $e) {
                report($e);

                return back()->with('error', "Couldn't cancel the scheduled change — please try again.");
            }
        }

        $user->pending_plan = null;
        $user->pending_plan_at = null;
        $user->save();

        return back()->with('success', "Scheduled change cancelled — you'll stay on your current plan.");
    }

    /** Buy a one-off AI-credit top-up bundle. */
    public function topUp(Request $request, BillingGateway $gateway): HttpResponse
    {
        $data = $request->validate([
            'bundle' => ['required', 'string', 'in:'.collect(TopUps::all())->pluck('key')->implode(',')],
        ]);

        if (! Billing::configured()) {
            return back()->with('error', "Online payments aren't switched on yet — top-ups are coming soon.");
        }

        $url = $gateway->topUpCheckoutUrl(
            $request->user(),
            $data['bundle'],
            route('billing.index').'?checkout=success&session_id={CHECKOUT_SESSION_ID}',
            route('billing.index').'?checkout=cancel',
        );

        return Inertia::location($url);
    }

    /** Send the user to the Stripe Billing Portal to manage their subscription and card. */
    public function portal(Request $request, BillingGateway $gateway): HttpResponse
    {
        $user = $request->user();

        if (! Billing::configured()) {
            return back()->with('error', "Online payments aren't switched on yet.");
        }

        if (blank($user->stripe_customer_id)) {
            return back()->with('error', "You don't have a billing account yet — subscribe to a plan first.");
        }

        return Inertia::location($gateway->billingPortalUrl($user, route('billing.index')));
    }

    /**
     * A scheduled downgrade to surface on the page, or null.
     *
     * @return array{plan: string, plan_name: string, date: string}|null
     */
    private function pendingChange(User $user, string $current): ?array
    {
        if (blank($user->pending_plan) || $user->pending_plan_at === null || $user->pending_plan === $current) {
            return null;
        }

        return [
            'plan' => (string) $user->pending_plan,
            'plan_name' => $this->planName((string) $user->pending_plan),
            'date' => $user->pending_plan_at->format('j M Y'),
        ];
    }

    private function planName(string $plan): string
    {
        return (string) Plans::for($plan)['name'];
    }

    private function priceDisplay(int $pence): string
    {
        return $pence === 0
            ? 'Free'
            : Money::ofMinor($pence, 'GBP')->formatToLocale('en_GB', allowWholeNumber: true);
    }

    private function storageDisplay(float $gigabytes): string
    {
        return $gigabytes < 1.0
            ? (int) round($gigabytes * 1000).' MB'
            : (int) $gigabytes.' GB';
    }

    /** Human-readable size for a byte count (MB under 1 GB, else GB to one decimal). */
    private function bytesDisplay(int $bytes): string
    {
        $gigabytes = $bytes / 1024 ** 3;
        if ($gigabytes >= 1.0) {
            return rtrim(rtrim(number_format($gigabytes, 1), '0'), '.').' GB';
        }

        return (int) round($bytes / 1024 ** 2).' MB';
    }
}
