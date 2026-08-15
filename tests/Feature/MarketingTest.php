<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_how_it_works_page_renders_for_a_guest(): void
    {
        $this->get(route('marketing.how'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Marketing/HowItWorks'));
    }

    public function test_the_pricing_page_lists_every_plan_with_a_formatted_price(): void
    {
        $this->get(route('marketing.pricing'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Pricing')
                ->has('plans', 3)
                ->where('plans.0.name', 'Free')
                ->where('plans.0.price_display', 'Free')
                ->where('plans.0.is_free', true)
                ->where('plans.1.name', 'Basic')
                ->where('plans.1.price_display', '£5')
                ->where('plans.1.custom_domain', true)
                ->where('plans.2.name', 'Pro')
                ->where('plans.2.price_display', '£15')
            );
    }
}
