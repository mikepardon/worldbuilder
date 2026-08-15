<?php

namespace Tests\Feature;

use App\Models\BillingSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminBillingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true]);
    }

    public function test_non_admins_cannot_reach_the_billing_area(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.billing.index'))
            ->assertForbidden();
    }

    public function test_the_billing_area_shows_plans_with_subscriber_counts(): void
    {
        User::factory()->create(['plan' => 'basic']);
        User::factory()->create(['plan' => 'basic']);

        $this->actingAs($this->admin())
            ->get(route('admin.billing.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Billing')
                ->where('settings.mode', 'sandbox')
                ->where('plans.0.key', 'free')
                ->where('plans.1.key', 'basic')
                ->where('plans.1.price_display', '£5')
                ->where('plans.1.subscribers', 2));
    }

    public function test_saving_keys_persists_the_mode_and_encrypts_the_secret(): void
    {
        $this->actingAs($this->admin())
            ->put(route('admin.billing.update'), [
                'mode' => 'live',
                'live_publishable_key' => 'pk_live_visible',
                'live_secret_key' => 'sk_live_topsecret',
                'live_price_basic' => 'price_basic_live',
            ])
            ->assertRedirect();

        $settings = BillingSetting::current();
        $this->assertSame('live', $settings->mode);
        $this->assertSame('pk_live_visible', $settings->live_publishable_key);
        $this->assertSame('sk_live_topsecret', $settings->live_secret_key);
        $this->assertSame('price_basic_live', $settings->live_price_basic);

        // The secret must be encrypted at rest — the raw column value is not the plaintext.
        $raw = DB::table('billing_settings')->value('live_secret_key');
        $this->assertNotSame('sk_live_topsecret', $raw);
        $this->assertNotNull($raw);
    }

    public function test_a_blank_secret_keeps_the_stored_key(): void
    {
        $settings = BillingSetting::current();
        $settings->update(['live_secret_key' => 'sk_live_existing', 'live_publishable_key' => 'pk_live_old']);

        $this->actingAs($this->admin())
            ->put(route('admin.billing.update'), [
                'mode' => 'live',
                'live_publishable_key' => 'pk_live_new',
                'live_secret_key' => '',
            ])
            ->assertRedirect();

        $fresh = BillingSetting::current();
        $this->assertSame('pk_live_new', $fresh->live_publishable_key);
        $this->assertSame('sk_live_existing', $fresh->live_secret_key);
    }

    public function test_secret_keys_are_never_sent_to_the_frontend(): void
    {
        $settings = BillingSetting::current();
        $settings->update([
            'test_secret_key' => 'sk_test_hidden',
            'test_webhook_secret' => 'whsec_hidden',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.billing.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.test.secret_set', true)
                ->where('settings.test.webhook_set', true)
                ->missing('settings.test.secret_key')
                ->missing('settings.test.webhook_secret'));

        // Belt check: the plaintext secret does not appear anywhere in the rendered payload.
        $this->actingAs($this->admin())
            ->get(route('admin.billing.index'))
            ->assertDontSee('sk_test_hidden');
    }
}
