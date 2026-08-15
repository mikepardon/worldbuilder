<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\CreditWeights;
use App\Support\Plans;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view. A `plan` query (set from the pricing page's paid tiers) is
     * remembered in the session so the chosen plan can be offered again once the account exists.
     */
    public function create(Request $request): Response
    {
        $intendedPlan = $this->paidPlan((string) $request->query('plan', ''));
        if ($intendedPlan !== null) {
            $request->session()->put('intended_plan', $intendedPlan);
        }

        return Inertia::render('Auth/Register', [
            'intendedPlan' => $intendedPlan !== null
                ? ['key' => $intendedPlan, 'name' => (string) Plans::for($intendedPlan)['name']]
                : null,
        ]);
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            // Welcome credits so a new GM can try the AI features before subscribing or topping up.
            'ai_credit_balance' => CreditWeights::SIGNUP_BONUS_CREDITS,
        ]);

        event(new Registered($user));

        Auth::login($user);

        // If they arrived from a paid plan on the pricing page, offer to continue to it.
        $intendedPlan = $this->paidPlan((string) $request->session()->pull('intended_plan', ''));
        if ($intendedPlan !== null) {
            return redirect()->route('billing.start', ['plan' => $intendedPlan]);
        }

        return redirect(route('dashboard', absolute: false));
    }

    /** Normalise a plan key to a real, paid plan (basic/pro) or null — reject-by-default. */
    private function paidPlan(string $plan): ?string
    {
        return Plans::isPlan($plan) && (int) Plans::for($plan)['price'] > 0 ? $plan : null;
    }
}
