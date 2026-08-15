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
