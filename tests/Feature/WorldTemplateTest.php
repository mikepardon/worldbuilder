<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorldTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_gm_can_create_a_template(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Dungeon', 'kind' => 'location',
            'layout' => ['facts' => 'top', 'width' => 'wide'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertDatabaseHas('world_templates', ['world_id' => $world->id, 'name' => 'Dungeon', 'kind' => 'location']);
    }

    public function test_an_entrys_template_layout_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Dungeon', 'kind' => 'location', 'layout' => ['facts' => 'top', 'width' => 'wide'],
        ]);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Crypt', 'kind' => 'location', 'slug' => 'crypt',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'crypt']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('layout.facts', 'top')
                ->where('layout.width', 'wide'));
    }

    public function test_a_template_can_restrict_and_order_quick_facts(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Sparse', 'kind' => 'location',
            // "population" first, then "type"; "ruler" left out; a bogus key is dropped.
            'layout' => ['facts' => 'sidebar', 'width' => 'normal', 'banner' => 'hide', 'fields' => ['population', 'type', 'not_a_field']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = $world->templates()->firstOrFail();
        $this->assertSame(['population', 'type'], $template->layout['fields']);
        $this->assertSame('hide', $template->layout['banner']);
    }

    public function test_a_templates_field_choice_filters_and_orders_the_readers_facts(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Sparse', 'kind' => 'location',
            'layout' => ['facts' => 'sidebar', 'width' => 'normal', 'banner' => 'auto', 'fields' => ['population', 'type']],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false,
            'template_id' => $template->id,
            'data' => ['type' => 'Fortress', 'region' => 'The North', 'population' => '400', 'ruler' => 'Lord Vane'],
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'keep']))
            ->assertInertia(fn (Assert $page) => $page
                // Only the two chosen fields, in the chosen order (population before type).
                ->where('facts', fn ($facts) => collect($facts)->pluck('label')->all() === ['Population', 'Type']));
    }

    public function test_a_template_can_force_the_banner_off(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'No banner', 'kind' => 'location',
            'layout' => ['facts' => 'sidebar', 'width' => 'normal', 'banner' => 'hide', 'fields' => []],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Cave', 'kind' => 'location', 'slug' => 'cave', 'is_private' => false,
            'template_id' => $template->id,
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'cave']))
            ->assertInertia(fn (Assert $page) => $page->where('layout.banner', 'hide'));
    }

    public function test_the_reader_falls_back_to_the_default_layout(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Plain', 'kind' => 'location', 'slug' => 'plain', 'is_private' => false,
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.article', [$world, $type, 'plain']))
            ->assertInertia(fn (Assert $page) => $page->where('layout.facts', 'sidebar'));
    }

    public function test_a_stranger_cannot_manage_templates(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('worlds.templates.store', $world->id), [
            'name' => 'X', 'kind' => 'location', 'layout' => ['facts' => 'top', 'width' => 'wide'],
        ])->assertForbidden();
    }
}
