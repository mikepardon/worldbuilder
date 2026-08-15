<?php

namespace Tests\Unit;

use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionsCategoriesTest extends TestCase
{
    use RefreshDatabase; // categories() reads global_attributes via DocFields

    public function test_it_lists_every_kind_with_picker_metadata(): void
    {
        $categories = Sections::categories();

        $this->assertCount(count(Sections::KINDS), $categories);
        foreach ($categories as $category) {
            $this->assertArrayHasKey('kind', $category);
            $this->assertArrayHasKey('label', $category);
            $this->assertArrayHasKey('description', $category);
            $this->assertArrayHasKey('hasTemplate', $category);
            $this->assertNotSame('', $category['description']);
        }
    }

    public function test_it_flags_which_categories_open_with_a_field_template(): void
    {
        $byKind = collect(Sections::categories())->keyBy('kind');

        // Location ships with a built-in field template; a free-form article does not.
        $this->assertTrue($byKind['location']['hasTemplate']);
        $this->assertFalse($byKind['article']['hasTemplate']);
        $this->assertSame('Location', $byKind['location']['label']);
    }
}
