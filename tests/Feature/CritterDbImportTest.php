<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\World;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CritterDbImportTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: World} */
    private function gmWithCampaign(): array
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'World', 'visibility' => 'public']);

        return [$gm, $world];
    }

    /** @return array<string, mixed> */
    private function goblin(): array
    {
        return [
            'name' => 'Goblin',
            'stats' => [
                'size' => 'Small', 'race' => 'Humanoid', 'alignment' => 'Neutral Evil',
                'armorClass' => 15, 'armorType' => 'Leather Armor',
                'numHitDie' => 2, 'hitDieSize' => 6, 'speed' => '30 ft.',
                'abilityScores' => ['strength' => 8, 'dexterity' => 14, 'constitution' => 10, 'intelligence' => 10, 'wisdom' => 8, 'charisma' => 8],
                'challengeRating' => 0.25,
                'actions' => [['name' => 'Scimitar', 'description' => 'Melee: +4 to hit, 1d6+2 slashing.']],
            ],
        ];
    }

    public function test_pasted_json_import_creates_an_editable_gm_only_monster(): void
    {
        [$gm, $world] = $this->gmWithCampaign();

        $this->actingAs($gm)
            ->post(route('compendium.critterdb', $world), [
                'mode' => 'paste',
                'json' => json_encode([$this->goblin()]),
            ])
            ->assertRedirect(route('compendium.index', $world));

        $this->assertSame(1, $world->compendiumItems()->count());

        $item = $world->compendiumItems()->first();
        $this->assertSame('Goblin', $item->name);
        $this->assertSame('monster', $item->item_type);
        $this->assertSame('custom', $item->provider);   // editable, not locked
        $this->assertFalse($item->isLocked());
        $this->assertTrue($item->is_private);           // GM-only until revealed
        $this->assertSame('15 (Leather Armor)', $item->fields['block']['ac']);
        $this->assertSame('7 (2d6)', $item->fields['block']['hp']);
        $this->assertSame('1/4', $item->fields['block']['cr']);
        $this->assertStringContainsString('Scimitar', $item->document);
    }

    public function test_url_import_fetches_the_published_bestiary_and_stops_after_a_short_page(): void
    {
        [$gm, $world] = $this->gmWithCampaign();

        Http::fake([
            'critterdb.com/*' => Http::response([$this->goblin()], 200),
        ]);

        $this->actingAs($gm)
            ->post(route('compendium.critterdb', $world), [
                'mode' => 'url',
                'url' => 'https://critterdb.com/#/publishedbestiary/view/6512686e94b584b853ef1586',
            ])
            ->assertRedirect(route('compendium.index', $world));

        $this->assertSame('Goblin', $world->compendiumItems()->first()->name);

        // One creature (< a full page of 25) means pagination stops after the first request.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_contains(
            $request->url(),
            'publishedbestiaries/6512686e94b584b853ef1586/creatures/1',
        ));
    }

    public function test_invalid_pasted_json_is_rejected_with_a_field_error(): void
    {
        [$gm, $world] = $this->gmWithCampaign();

        $this->actingAs($gm)
            ->from(route('compendium.index', $world))
            ->post(route('compendium.critterdb', $world), ['mode' => 'paste', 'json' => 'not json at all'])
            ->assertRedirect(route('compendium.index', $world))
            ->assertSessionHasErrors('json');

        $this->assertSame(0, $world->compendiumItems()->count());
    }

    public function test_an_unreachable_bestiary_reports_a_url_error_and_imports_nothing(): void
    {
        [$gm, $world] = $this->gmWithCampaign();

        Http::fake(['critterdb.com/*' => Http::response('', 404)]);

        $this->actingAs($gm)
            ->from(route('compendium.index', $world))
            ->post(route('compendium.critterdb', $world), [
                'mode' => 'url',
                'url' => 'https://critterdb.com/#/publishedbestiary/view/6512686e94b584b853ef1586',
            ])
            ->assertSessionHasErrors('url');

        $this->assertSame(0, $world->compendiumItems()->count());
    }

    public function test_a_non_owner_cannot_import_into_someone_elses_world(): void
    {
        [, $world] = $this->gmWithCampaign();
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->post(route('compendium.critterdb', $world), ['mode' => 'paste', 'json' => json_encode([$this->goblin()])])
            ->assertForbidden();

        $this->assertSame(0, $world->compendiumItems()->count());
    }
}
