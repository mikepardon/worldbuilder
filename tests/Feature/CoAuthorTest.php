<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoAuthorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function ownerWithWorld(): array
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        return [$owner, $world];
    }

    private function doc(World $world, User $author, string $title = 'An Entry'): Document
    {
        return $world->documents()->create([
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'kind' => 'article',
            'content' => '# '.$title,
            'is_private' => false,
        ]);
    }

    public function test_the_owner_can_invite_a_co_author_by_email(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create(['email' => 'editor@example.com']);

        $this->actingAs($owner)
            ->post(route('worlds.members.store', $world), ['email' => 'editor@example.com'])
            ->assertRedirect();

        $this->assertDatabaseHas('world_members', [
            'world_id' => $world->id,
            'user_id' => $editor->id,
            'role' => 'editor',
        ]);
    }

    public function test_inviting_an_unknown_email_adds_no_one(): void
    {
        [$owner, $world] = $this->ownerWithWorld();

        $this->actingAs($owner)
            ->post(route('worlds.members.store', $world), ['email' => 'nobody@example.com'])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('world_members', 0);
    }

    public function test_the_owner_cannot_be_added_as_a_co_author(): void
    {
        [$owner, $world] = $this->ownerWithWorld();

        $this->actingAs($owner)
            ->post(route('worlds.members.store', $world), ['email' => $owner->email])
            ->assertSessionHas('error');

        $this->assertDatabaseCount('world_members', 0);
    }

    public function test_a_co_author_can_edit_world_content(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create();
        $world->members()->create(['user_id' => $editor->id, 'role' => 'editor']);
        $entry = $this->doc($world, $owner, 'The Harbour');

        $this->actingAs($editor)
            ->put(route('documents.update', $entry), ['content' => 'Edited by a co-author.'])
            ->assertRedirect();

        $this->assertSame('Edited by a co-author.', $entry->fresh()->content);
    }

    public function test_a_co_author_can_create_an_entry(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create();
        $world->members()->create(['user_id' => $editor->id, 'role' => 'editor']);

        $this->actingAs($editor)
            ->post(route('documents.store', $world), ['title' => 'A New Place', 'kind' => 'location'])
            ->assertRedirect();

        $this->assertDatabaseHas('documents', ['world_id' => $world->id, 'title' => 'A New Place', 'kind' => 'location']);
    }

    public function test_a_stranger_cannot_edit_world_content(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $entry = $this->doc($world, $owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->put(route('documents.update', $entry), ['content' => 'Hijacked.'])
            ->assertForbidden();
    }

    public function test_a_co_author_cannot_manage_collaborators(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create();
        $world->members()->create(['user_id' => $editor->id, 'role' => 'editor']);

        $this->actingAs($editor)->get(route('worlds.members.index', $world))->assertForbidden();
        $this->actingAs($editor)
            ->post(route('worlds.members.store', $world), ['email' => 'x@example.com'])
            ->assertForbidden();
    }

    public function test_a_co_author_cannot_change_world_settings(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create();
        $world->members()->create(['user_id' => $editor->id, 'role' => 'editor']);

        $this->actingAs($editor)
            ->put(route('worlds.update', $world), ['visibility' => 'private'])
            ->assertForbidden();
    }

    public function test_the_owner_can_remove_a_co_author(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create();
        $member = $world->members()->create(['user_id' => $editor->id, 'role' => 'editor']);

        $this->actingAs($owner)
            ->delete(route('worlds.members.destroy', [$world, $member]))
            ->assertRedirect();

        $this->assertDatabaseMissing('world_members', ['id' => $member->id]);
    }

    public function test_a_co_authored_world_appears_on_the_dashboard(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $editor = User::factory()->create();
        $world->members()->create(['user_id' => $editor->id, 'role' => 'editor']);

        $this->actingAs($editor)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('worlds.0.name', 'Saltmere')
            ->where('worlds.0.can_manage', true));
    }
}
