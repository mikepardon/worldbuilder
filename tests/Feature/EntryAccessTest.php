<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EntryAccessTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function ownerWithWorld(): array
    {
        $owner = User::factory()->create();
        $world = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        return [$owner, $world];
    }

    private function article(World $world, User $owner, bool $private = false): Document
    {
        return $world->documents()->create([
            'user_id' => $owner->id,
            'title' => 'The Deepmarket',
            'slug' => 'the-deepmarket-'.Str::lower(Str::random(4)),
            'kind' => 'article',
            'content' => 'A sunken bazaar beneath the tide.',
            'is_private' => $private,
        ]);
    }

    private function url(World $world, Document $doc): string
    {
        return route('public.article', [$world, 'article', $doc->slug]);
    }

    public function test_a_password_gates_the_entry_for_the_public(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner);
        $doc->update(['access_password' => 'saltrunner']); // hashed by the cast

        $this->get($this->url($world, $doc))->assertInertia(fn (Assert $page) => $page
            ->component('Public/Article')
            ->where('locked', true)
            ->missing('entry.content'));
    }

    public function test_the_correct_password_unlocks_the_entry_for_the_session(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner);
        $doc->update(['access_password' => 'saltrunner']);

        // Wrong password is rejected and nothing unlocks.
        $this->post(route('public.article.unlock', [$world, 'article', $doc->slug]), ['password' => 'nope'])
            ->assertSessionHasErrors('password');
        $this->get($this->url($world, $doc))->assertInertia(fn (Assert $page) => $page->where('locked', true));

        // Correct password unlocks it for this session.
        $this->post(route('public.article.unlock', [$world, 'article', $doc->slug]), ['password' => 'saltrunner'])
            ->assertRedirect();
        $this->get($this->url($world, $doc))->assertInertia(fn (Assert $page) => $page
            ->has('entry.content')
            ->missing('locked'));
    }

    public function test_the_owner_bypasses_the_password_gate(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner);
        $doc->update(['access_password' => 'saltrunner']);

        $this->actingAs($owner)->get($this->url($world, $doc))->assertInertia(fn (Assert $page) => $page
            ->has('entry.content')
            ->missing('locked'));
    }

    public function test_removing_the_password_reopens_the_entry(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner);
        $doc->update(['access_password' => 'saltrunner']);
        $doc->update(['access_password' => null]);

        $this->get($this->url($world, $doc))->assertInertia(fn (Assert $page) => $page
            ->has('entry.content')
            ->missing('locked'));
    }

    public function test_a_share_link_grants_access_to_a_private_entry(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner, private: true);
        $doc->update(['share_token' => 'tok'.Str::random(20)]);
        $token = $doc->fresh()->share_token;

        // Without the key, a private entry is hidden.
        $this->get($this->url($world, $doc))->assertNotFound();

        // With the key, it opens for anyone.
        $this->get($this->url($world, $doc).'?key='.$token)->assertInertia(fn (Assert $page) => $page
            ->has('entry.content')
            ->missing('locked'));
    }

    public function test_a_revoked_share_link_stops_working(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner, private: true);
        $doc->update(['share_token' => 'tok'.Str::random(20)]);
        $token = $doc->fresh()->share_token;

        $doc->update(['share_token' => null]);

        $this->get($this->url($world, $doc).'?key='.$token)->assertNotFound();
    }

    public function test_the_owner_can_set_a_password_and_create_a_share_link(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner);

        $this->actingAs($owner)->put(route('documents.access', $doc), ['password' => 'saltrunner'])->assertRedirect();
        $this->assertNotNull($doc->fresh()->access_password);

        $this->actingAs($owner)->post(route('documents.share', $doc))->assertRedirect();
        $this->assertNotNull($doc->fresh()->share_token);
    }

    public function test_a_stranger_cannot_set_access_controls(): void
    {
        [$owner, $world] = $this->ownerWithWorld();
        $doc = $this->article($world, $owner);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->put(route('documents.access', $doc), ['password' => 'x'])->assertForbidden();
        $this->actingAs($stranger)->post(route('documents.share', $doc))->assertForbidden();
    }
}
