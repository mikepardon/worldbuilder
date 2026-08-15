<?php

namespace Tests\Unit;

use App\Data\DocumentSummaryData;
use App\Models\User;
use App\Models\World;
use App\Queries\DocumentQuery;
use App\Support\Viewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DocumentQueryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function worldWithEntries(): array
    {
        $owner = User::factory()->create();
        $campaign = $owner->worlds()->create(['name' => 'Saltmere', 'visibility' => 'public']);
        $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Docks', 'kind' => 'location', 'content' => '# The Docks', 'is_private' => false]);
        $campaign->documents()->create(['user_id' => $owner->id, 'title' => 'The Cult', 'kind' => 'faction', 'content' => '# The Cult', 'is_private' => true]);

        return [$owner, $campaign];
    }

    public function test_the_gm_sees_both_public_and_private_entries(): void
    {
        [$owner, $campaign] = $this->worldWithEntries();

        $visible = (new DocumentQuery)->visibleTo($campaign, Viewer::for($campaign, $owner));

        $this->assertCount(2, $visible);
        $this->assertEqualsCanonicalizing(['The Docks', 'The Cult'], $visible->pluck('title')->all());
    }

    public function test_a_player_sees_only_public_entries(): void
    {
        [, $campaign] = $this->worldWithEntries();
        $player = User::factory()->create();

        $visible = (new DocumentQuery)->visibleTo($campaign, Viewer::for($campaign, $player));

        $this->assertCount(1, $visible);
        $this->assertSame('The Docks', $visible->first()->title);
    }

    public function test_the_owner_previewing_as_a_player_sees_only_public_entries(): void
    {
        [$owner, $campaign] = $this->worldWithEntries();

        $visible = (new DocumentQuery)->visibleTo($campaign, Viewer::for($campaign, $owner, asPlayer: true));

        $this->assertCount(1, $visible);
        $this->assertSame('The Docks', $visible->first()->title);
    }

    public function test_a_guest_sees_only_public_entries(): void
    {
        [, $campaign] = $this->worldWithEntries();

        $visible = (new DocumentQuery)->visibleTo($campaign, Viewer::for($campaign, null));

        $this->assertCount(1, $visible);
        $this->assertSame('The Docks', $visible->first()->title);
    }

    public function test_search_is_viewer_scoped(): void
    {
        [$owner, $campaign] = $this->worldWithEntries(); // 'The Cult' is private
        $query = new DocumentQuery;

        $this->assertCount(1, $query->search($campaign, Viewer::for($campaign, $owner), 'cult'));
        $this->assertCount(0, $query->search($campaign, Viewer::for($campaign, User::factory()->create()), 'cult'));
    }

    public function test_search_with_a_blank_term_returns_nothing(): void
    {
        [$owner, $campaign] = $this->worldWithEntries();

        $this->assertCount(0, (new DocumentQuery)->search($campaign, Viewer::for($campaign, $owner), '   '));
    }

    public function test_summaries_return_dtos_with_visibility_applied(): void
    {
        [, $campaign] = $this->worldWithEntries();
        $player = User::factory()->create();

        $summaries = (new DocumentQuery)->summariesFor($campaign, Viewer::for($campaign, $player));

        $this->assertCount(1, $summaries);
        $this->assertInstanceOf(DocumentSummaryData::class, $summaries->first());
        $this->assertFalse($summaries->first()->isPrivate);
        $this->assertSame('The Docks', $summaries->first()->toReaderCard()['title']);
    }
}
