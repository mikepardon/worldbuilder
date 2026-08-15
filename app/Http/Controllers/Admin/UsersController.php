<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UsersController extends Controller
{
    public function index()
    {
        return Inertia::render('Admin/Users', [
            'users' => User::withCount('worlds')->latest()->get()
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'email' => $u->email,
                    'is_admin' => (bool) $u->is_admin,
                    'worlds_count' => $u->worlds_count,
                    'created_at' => $u->created_at?->toFormattedDateString(),
                    'plan' => is_string($u->plan) ? $u->plan : 'free',
                    'daily_allowance' => $u->dailyAiAllowance(),
                    'used_today' => $u->aiUsedToday(),
                    'credit_balance' => (int) $u->ai_credit_balance,
                    'credits_remaining' => $u->aiCreditsRemaining(),
                ]),
        ]);
    }

    /**
     * Add AI credits to a user's top-up balance. This is a persistent pool spent only after the plan's
     * daily allowance is used, so it doesn't reset each day.
     */
    public function credits(Request $request, User $user)
    {
        $data = $request->validate([
            'credits' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);

        // Set from the validated value (not raw request data), then save — mass assignment is avoided.
        $user->ai_credit_balance = (int) $user->ai_credit_balance + $data['credits'];
        $user->save();

        return back()->with('success', "Added {$data['credits']} credits to {$user->email}.");
    }

    /** Promote or demote a user. Admins cannot remove their own access. */
    public function role(Request $request, User $user)
    {
        $data = $request->validate([
            'is_admin' => ['required', 'boolean'],
        ]);

        if ($user->id === $request->user()->id && ! $data['is_admin']) {
            return back()->with('error', 'You can’t remove your own admin access.');
        }

        $user->is_admin = $data['is_admin'];
        $user->save();

        return back()->with('success', $data['is_admin']
            ? "{$user->email} is now an admin."
            : "{$user->email} is no longer an admin.");
    }
}
