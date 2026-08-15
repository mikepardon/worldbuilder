<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Billing\BillingFulfilment;
use App\Services\Billing\BillingGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Receives Stripe webhooks. The gateway verifies the signature; fulfilment applies the plan change. */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, BillingGateway $gateway, BillingFulfilment $fulfilment): Response
    {
        $event = $gateway->webhookEvent($request->getContent(), $request->header('Stripe-Signature'));

        if ($event === null) {
            abort(400, 'Invalid webhook signature.');
        }

        $fulfilment->apply($event);

        return response()->noContent();
    }
}
