<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
                ->where('plans.0.monthly_credits', 0)
                ->where('plans.1.name', 'Basic')
                ->where('plans.1.price_display', '£5')
                ->where('plans.1.custom_domain', true)
                ->where('plans.1.monthly_credits', 300)
                ->where('plans.2.name', 'Pro')
                ->where('plans.2.price_display', '£15')
                ->where('plans.2.monthly_credits', 1000)
            );
    }

    /**
     * @return list<array{string, string}>
     */
    public static function marketingPages(): array
    {
        return [
            'features overview' => ['marketing.features', 'Marketing/Features'],
            'worldbuilding' => ['marketing.features.worldbuilding', 'Marketing/Features/Worldbuilding'],
            'virtual tabletop' => ['marketing.features.vtt', 'Marketing/Features/VirtualTabletop'],
            'compendium' => ['marketing.features.compendium', 'Marketing/Features/Compendium'],
            'publishing' => ['marketing.features.publishing', 'Marketing/Features/Publishing'],
            'ai' => ['marketing.features.ai', 'Marketing/Features/Ai'],
            'use cases' => ['marketing.use-cases', 'Marketing/UseCases'],
            'compare' => ['marketing.compare', 'Marketing/Compare'],
            'faq' => ['marketing.faq', 'Marketing/Faq'],
        ];
    }

    #[DataProvider('marketingPages')]
    public function test_a_marketing_page_renders_for_a_guest(string $routeName, string $component): void
    {
        $this->get(route($routeName))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component($component));
    }

    public function test_the_examples_page_lists_published_public_worlds(): void
    {
        $user = User::factory()->create();
        $user->worlds()->create(['name' => 'Glieda', 'visibility' => 'public', 'description' => 'A demo world.']);
        $user->worlds()->create(['name' => 'Hidden Realm', 'visibility' => 'private']);

        $this->get(route('marketing.examples'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Marketing/Examples')
                ->has('worlds', 1)
                ->where('worlds.0.name', 'Glieda'));
    }
}
