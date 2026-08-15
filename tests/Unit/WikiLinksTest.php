<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Models\World;
use App\Support\WikiLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WikiLinksTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_extracts_distinct_targets_ignoring_case_and_aliases(): void
    {
        $titles = WikiLinks::titles('Meet [[The Baron|the baron]] at [[Saltmere]], again [[saltmere]].');

        $this->assertSame(['The Baron', 'Saltmere'], $titles);
    }

    public function test_it_returns_no_titles_when_there_are_no_links(): void
    {
        $this->assertSame([], WikiLinks::titles('Just some prose with [single] brackets.'));
        $this->assertSame([], WikiLinks::titles(null));
    }

    /** @return array{0: User, 1: World} */
    private function world(): array
    {
        $owner = User::factory()->create();
        $campaign = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);

        return [$owner, $campaign];
    }

    public function test_sync_links_to_a_matching_entry_in_the_same_world(): void
    {
        [$owner, $campaign] = $this->world();
        $one = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Harbour', 'kind' => 'location', 'content' => 'Home of [[The Docks]].']);
        $two = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Docks', 'kind' => 'location', 'content' => '# The Docks']);

        WikiLinks::sync($one);

        $this->assertDatabaseHas('document_links', [
            'from_document_id' => $one->id, 'to_document_id' => $two->id, 'source' => 'wikilink',
        ]);
    }

    public function test_sync_matches_titles_case_insensitively(): void
    {
        [$owner, $campaign] = $this->world();
        $one = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'A', 'kind' => 'article', 'content' => 'Visit [[the docks]].']);
        $two = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Docks', 'kind' => 'location', 'content' => 'x']);

        WikiLinks::sync($one);

        $this->assertDatabaseHas('document_links', ['from_document_id' => $one->id, 'to_document_id' => $two->id, 'source' => 'wikilink']);
    }

    public function test_sync_replaces_stale_wikilinks_but_leaves_manual_links_intact(): void
    {
        [$owner, $campaign] = $this->world();
        $one = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'A', 'kind' => 'article', 'content' => 'See [[The Docks]].']);
        $docks = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Docks', 'kind' => 'location', 'content' => 'x']);
        $keep = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'Keep', 'kind' => 'location', 'content' => 'x']);

        // A hand-made connection that must survive re-syncs.
        $one->outgoingLinks()->create(['world_id' => $campaign->id, 'to_document_id' => $keep->id, 'label' => 'guards', 'source' => 'manual']);

        WikiLinks::sync($one);
        $this->assertDatabaseHas('document_links', ['from_document_id' => $one->id, 'to_document_id' => $docks->id, 'source' => 'wikilink']);

        // Remove the mention and re-sync: the wikilink goes, the manual link stays.
        $one->update(['content' => 'Nothing links out now.']);
        WikiLinks::sync($one);

        $this->assertDatabaseMissing('document_links', ['from_document_id' => $one->id, 'to_document_id' => $docks->id, 'source' => 'wikilink']);
        $this->assertDatabaseHas('document_links', ['from_document_id' => $one->id, 'to_document_id' => $keep->id, 'source' => 'manual']);
    }

    public function test_sync_ignores_titles_with_no_matching_entry(): void
    {
        [$owner, $campaign] = $this->world();
        $one = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'A', 'kind' => 'article', 'content' => 'Visit [[Nowhere At All]].']);

        WikiLinks::sync($one);

        $this->assertDatabaseCount('document_links', 0);
    }

    public function test_sync_never_links_an_entry_to_itself(): void
    {
        [$owner, $campaign] = $this->world();
        $one = $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Docks', 'kind' => 'location', 'content' => 'I am [[The Docks]].']);

        WikiLinks::sync($one);

        $this->assertDatabaseCount('document_links', 0);
    }
}
