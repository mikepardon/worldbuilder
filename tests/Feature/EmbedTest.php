<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\Sections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmbedTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_public_entry_renders_an_embed_card(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'The Amber Temple', 'kind' => 'location',
            'slug' => 'the-amber-temple', 'summary' => 'A frozen vault.', 'is_private' => false,
        ]);

        $type = Sections::typeSlug('location');
        $response = $this->get(route('public.embed', [$world, $type, 'the-amber-temple']));

        $response->assertOk();
        $response->assertSee('The Amber Temple');
        $response->assertSee('Saltmere');
        $response->assertSee("/w/{$world->slug}/{$type}/the-amber-temple");
    }

    public function test_a_private_entry_embed_is_hidden_from_guests_but_shown_to_the_owner(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Hidden Vault', 'kind' => 'location',
            'slug' => 'hidden-vault', 'is_private' => true,
        ]);

        $type = Sections::typeSlug('location');
        $this->get(route('public.embed', [$world, $type, 'hidden-vault']))->assertNotFound();

        $this->actingAs($gm)->get(route('public.embed', [$world, $type, 'hidden-vault']))
            ->assertOk()
            ->assertSee('Hidden Vault');
    }
}
