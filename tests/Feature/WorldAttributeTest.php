<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\DocFields;
use App\Support\Facts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorldAttributeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_world_attribute_adds_a_field_for_its_kinds(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.attributes.store', $world->id), [
            'label' => 'Climate', 'type' => 'text', 'kinds' => ['location'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $keys = array_column(DocFields::for('location', $world), 'key');
        $this->assertContains('climate', $keys);
        // Not added to a kind it doesn't apply to.
        $this->assertNotContains('climate', array_column(DocFields::for('npc', $world), 'key'));
    }

    public function test_a_world_attribute_overrides_a_default_of_the_same_key(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.attributes.store', $world->id), [
            'label' => 'Kind of place', 'key' => 'type', 'type' => 'text', 'kinds' => ['location'],
        ])->assertRedirect();

        $type = collect(DocFields::for('location', $world))->firstWhere('key', 'type');
        $this->assertSame('Kind of place', $type['label']);
    }

    public function test_a_hidden_world_attribute_removes_a_default(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.attributes.store', $world->id), [
            'label' => 'Region', 'key' => 'region', 'type' => 'text', 'kinds' => ['location'], 'visible' => false,
        ])->assertRedirect();

        $keys = array_column(DocFields::for('location', $world), 'key');
        $this->assertNotContains('region', $keys);
        // The platform default is untouched for other worlds.
        $this->assertContains('region', array_column(DocFields::for('location'), 'key'));
    }

    public function test_duplicate_keys_within_a_world_are_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->customFields()->create(['key' => 'climate', 'label' => 'Climate', 'kinds' => ['location']]);

        $this->actingAs($gm)->post(route('worlds.attributes.store', $world->id), [
            'label' => 'Weather', 'key' => 'climate', 'type' => 'text', 'kinds' => ['location'],
        ])->assertSessionHasErrors('key');
    }

    public function test_a_field_can_be_marked_to_hold_multiple_values(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.attributes.store', $world->id), [
            'label' => 'Staff', 'type' => 'reference', 'kinds' => ['location'],
            'ref_kinds' => ['npc'], 'multiple' => true,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $staff = collect(DocFields::for('location', $world))->firstWhere('key', 'staff');
        $this->assertTrue($staff['multiple']);
    }

    public function test_a_multiple_reference_field_resolves_every_target_as_a_fact(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->customFields()->create([
            'key' => 'staff', 'label' => 'Staff', 'type' => 'reference',
            'kinds' => ['location'], 'ref_kinds' => ['npc'], 'multiple' => true,
        ]);

        $alice = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Alice', 'kind' => 'npc', 'slug' => 'alice', 'is_private' => false]);
        $bob = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Bob', 'kind' => 'npc', 'slug' => 'bob', 'is_private' => false]);
        $tavern = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Tavern', 'kind' => 'location', 'slug' => 'tavern',
            'is_private' => false, 'data' => ['staff' => [$alice->id, $bob->id]],
        ]);

        $facts = Facts::for($tavern->fresh(), $world->documents()->get());
        $staff = collect($facts)->firstWhere('label', 'Staff');

        $this->assertNotNull($staff);
        $this->assertCount(2, $staff['items']);
        $this->assertSame(['Alice', 'Bob'], array_column($staff['items'], 'value'));
        $this->assertSame('Alice, Bob', $staff['value']);
    }

    public function test_a_world_can_reorder_the_fields_shown_for_a_kind(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // Defaults for location are type, region, population, ruler — flip the first two.
        $this->actingAs($gm)->put(route('worlds.attributes.reorder', $world->id), [
            'kind' => 'location',
            'order' => ['region', 'type', 'population', 'ruler'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $keys = array_column(DocFields::for('location', $world->fresh()), 'key');
        $this->assertSame(['region', 'type', 'population', 'ruler'], $keys);
    }

    public function test_reordering_leaves_unlisted_fields_after_the_ordered_ones(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // Only name two of the four location fields; the rest keep their position afterwards.
        $this->actingAs($gm)->put(route('worlds.attributes.reorder', $world->id), [
            'kind' => 'location',
            'order' => ['ruler', 'population'],
        ])->assertRedirect();

        $keys = array_column(DocFields::for('location', $world->fresh()), 'key');
        $this->assertSame(['ruler', 'population', 'type', 'region'], $keys);
    }

    public function test_a_stranger_cannot_reorder_world_fields(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->put(route('worlds.attributes.reorder', $world->id), [
            'kind' => 'location', 'order' => ['region', 'type'],
        ])->assertForbidden();
    }

    public function test_a_stranger_cannot_manage_world_attributes(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->post(route('worlds.attributes.store', $world->id), [
            'label' => 'Climate', 'type' => 'text', 'kinds' => ['location'],
        ])->assertForbidden();
    }
}
