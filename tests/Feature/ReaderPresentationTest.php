<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReaderPresentationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_reading_font_and_home_layout_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['reader_font' => 'sans', 'reader_home_layout' => 'minimal'],
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.font', 'sans')
                ->where('campaign.homeLayout', 'minimal'));
    }

    public function test_the_reader_defaults_to_serif_and_the_full_home(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.font', 'serif')
                ->where('campaign.homeLayout', 'full'));
    }

    public function test_the_date_format_and_session_numbering_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['reader_date_format' => 'long', 'reader_number_sessions' => true],
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.dateFormat', 'long')
                ->where('campaign.numberSessions', true));
    }

    public function test_the_reader_defaults_to_medium_dates_without_session_numbers(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('campaign.dateFormat', 'medium')
                ->where('campaign.numberSessions', false));
    }

    public function test_new_entries_start_private_when_the_spoiler_default_is_on(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create([
            'name' => 'W', 'visibility' => 'public',
            'settings' => ['default_entry_private' => true],
        ]);

        $this->actingAs($gm)->post(route('documents.store', $world), [
            'title' => 'Hidden Cove', 'kind' => 'location',
        ])->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'world_id' => $world->id, 'title' => 'Hidden Cove', 'is_private' => true,
        ]);
    }

    public function test_new_entries_are_public_by_default(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('documents.store', $world), [
            'title' => 'Open Market', 'kind' => 'location',
        ])->assertRedirect();

        $this->assertDatabaseHas('documents', [
            'world_id' => $world->id, 'title' => 'Open Market', 'is_private' => false,
        ]);
    }
}
