<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\EmailSubscriber;
use Illuminate\Http\Request;

class UnsubscribeController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired unsubscribe link.');
        }

        $email = $request->string('email')->toString();

        $subscriber = EmailSubscriber::where('email', $email)->first();

        if ($subscriber !== null && $subscriber->subscribed) {
            $subscriber->update([
                'subscribed' => false,
                'unsubscribed_at' => now(),
            ]);
        }

        return redirect('/')->with('info', "You've been unsubscribed from {$email}.");
    }
}
