<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecretsMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_gets_a_list_of_private_entries_with_links(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'Town', 'slug' => 'town', 'kind' => 'location', 'content' => 'x', 'is_private' => false,
        ]);
        $world->documents()->create([
            'title' => 'The Vault', 'slug' => 'the-vault', 'kind' => 'location', 'content' => 'x', 'is_private' => true,
        ]);

        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewer.secretCount', 1)
                ->where('viewer.secrets.0.title', 'The Vault')
                ->where('viewer.secrets.0.href', "/w/{$world->slug}/location/the-vault"));
    }

    public function test_a_public_entry_with_hidden_passages_is_listed_with_a_count(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'The Keep', 'slug' => 'the-keep', 'kind' => 'location', 'is_private' => false,
            'content' => 'Public intro. {{secret}}A trapdoor.{{/}} More. {{secret}}A ghost.{{/}}',
        ]);

        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewer.secretCount', 1)
                ->where('viewer.secrets.0.title', 'The Keep')
                ->where('viewer.secrets.0.passages', 2)
                ->where('viewer.secrets.0.private', false)
                ->where('viewer.secrets.0.href', "/w/{$world->slug}/location/the-keep"));
    }

    public function test_an_unclosed_secret_token_is_not_listed(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'Broken', 'slug' => 'broken', 'kind' => 'location', 'is_private' => false,
            'content' => 'Intro {{secret}} never closed',
        ]);

        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('viewer.secretCount', 0));
    }

    public function test_the_public_gets_no_secrets(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'title' => 'The Vault', 'slug' => 'the-vault', 'kind' => 'location', 'content' => 'x', 'is_private' => true,
        ]);

        $this->get(route('public.world', $world))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('viewer.secretCount', 0)
                ->where('viewer.secrets', []));
    }
}
