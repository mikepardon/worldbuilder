<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Support\CreditWeights;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_the_register_page_remembers_a_chosen_paid_plan(): void
    {
        $this->get(route('register', ['plan' => 'basic']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/Register')
                ->where('intendedPlan.key', 'basic')
                ->where('intendedPlan.name', 'Basic'))
            ->assertSessionHas('intended_plan', 'basic');
    }

    public function test_the_register_page_ignores_a_free_or_unknown_plan(): void
    {
        $this->get(route('register', ['plan' => 'free']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('intendedPlan', null))
            ->assertSessionMissing('intended_plan');
    }

    public function test_registering_after_choosing_a_paid_plan_offers_to_continue_to_it(): void
    {
        $this->withSession(['intended_plan' => 'basic'])
            ->post('/register', [
                'name' => 'Paid User',
                'email' => 'paid@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertRedirect(route('billing.start', ['plan' => 'basic']));

        $this->assertAuthenticated();
    }

    public function test_registering_without_a_chosen_plan_lands_on_the_dashboard(): void
    {
        $this->post('/register', [
            'name' => 'Free User',
            'email' => 'free@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_new_account_receives_the_welcome_credit_bonus(): void
    {
        $this->post('/register', [
            'name' => 'Test User',
            'email' => 'welcome@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'welcome@example.com')->firstOrFail();

        $this->assertSame(CreditWeights::SIGNUP_BONUS_CREDITS, $user->ai_credit_balance);
    }
}
