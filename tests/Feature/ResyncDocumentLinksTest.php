<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Models\World;
use App\Support\Connections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ResyncDocumentLinksTest extends TestCase
{
    use RefreshDatabase;

    private function doc(World $world, string $title, string $content = ''): Document
    {
        return $world->documents()->create([
            'user_id' => $world->user_id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'kind' => 'article',
            'content' => $content,
            'is_private' => false,
        ]);
    }

    public function test_it_backfills_wiki_links_that_were_never_synced(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'Elysium', 'visibility' => 'public']);
        // Created directly (as a seeder/import would), so no link exists yet.
        $hermes = $this->doc($world, 'Hermes');
        $evri = $this->doc($world, 'Evri', 'A guild that alludes to [[Hermes]].');

        $this->assertDatabaseCount('document_links', 0);

        $this->artisan('documents:resync-links', ['world' => $world->id])
            ->expectsOutputToContain('Re-synced wiki-links for 2 document(s).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $evri->id,
            'to_document_id' => $hermes->id,
            'source' => 'wikilink',
        ]);

        $graph = Connections::graph($world);
        $this->assertCount(1, $graph['edges']);
        $this->assertSame($evri->id, $graph['edges'][0]['from']);
        $this->assertSame($hermes->id, $graph['edges'][0]['to']);
    }

    public function test_it_only_touches_the_named_world(): void
    {
        $gm = User::factory()->create();
        $elysium = $gm->worlds()->create(['name' => 'Elysium', 'visibility' => 'public']);
        $other = $gm->worlds()->create(['name' => 'Other', 'visibility' => 'public']);
        $this->doc($other, 'Hermes');
        $this->doc($other, 'Evri', 'Alludes to [[Hermes]].');

        $this->artisan('documents:resync-links', ['world' => $elysium->slug])->assertExitCode(0);

        // The other world's links were left alone (not synced by this scoped run).
        $this->assertDatabaseCount('document_links', 0);
    }

    public function test_it_fails_cleanly_for_an_unknown_world(): void
    {
        $this->artisan('documents:resync-links', ['world' => 'no-such-world'])
            ->assertExitCode(1);
    }
}
