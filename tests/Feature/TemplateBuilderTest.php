<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\DocumentLink;
use App\Models\User;
use App\Models\WorldBlock;
use App\Support\Sections;
use App\Support\TemplateBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TemplateBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_index_lists_templates_normalised_to_blocks(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        // A legacy template (no blocks) should arrive already normalised into blocks.
        $world->templates()->create([
            'name' => 'Old', 'kind' => 'location',
            'layout' => ['facts' => 'top', 'width' => 'wide', 'banner' => 'hide', 'fields' => ['type']],
        ]);

        $this->actingAs($gm)->get(route('worlds.templates.index', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/Templates')
                ->where('templates.0.blocks', fn ($blocks) => collect($blocks)->pluck('type')->all() === ['header', 'facts', 'content', 'related']));
    }

    public function test_the_create_page_carries_the_block_catalogue(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/TemplateBuilder')
                ->where('template', null)
                ->has('blockTypes.banner')
                ->has('blockTypes.facts.settings.fields')
                ->has('starterBlocks'));
    }

    public function test_the_builder_carries_starter_presets(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('presets', fn ($presets) => collect($presets)->pluck('key')->contains('gazetteer')
                    && collect($presets)->firstWhere('key', 'gazetteer')['blocks'][1]['type'] === 'columns'
                    && collect($presets)->firstWhere('key', 'gazetteer')['blocks'][1]['settings']['cols'][0][0]['type'] === 'facts'));

        // Home gets its own presets (e.g. the campaign hub), not the entry ones.
        $this->actingAs($gm)->get(route('worlds.templates.home', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('presets', fn ($presets) => collect($presets)->pluck('key')->contains('hub')
                    && ! collect($presets)->pluck('key')->contains('gazetteer')));
    }

    public function test_every_preset_is_made_of_valid_blocks_for_its_target(): void
    {
        foreach (['entry', 'home', 'archive'] as $target) {
            $presets = TemplateBlocks::presets($target);
            $this->assertNotEmpty($presets);
            foreach ($presets as $preset) {
                $this->assertNotEmpty($preset['blocks']);
                foreach ($preset['blocks'] as $block) {
                    $this->assertContains($block['type'], TemplateBlocks::typeKeys($target));
                }
            }
        }
    }

    public function test_the_edit_page_carries_the_template_as_blocks(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Dungeon', 'kind' => 'location',
            'layout' => ['blocks' => [['id' => 'h', 'type' => 'header', 'settings' => []]]],
        ]);

        $this->actingAs($gm)->get(route('worlds.templates.edit', $template->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/TemplateBuilder')
                ->where('template.id', $template->id)
                ->where('template.name', 'Dungeon')
                ->where('template.blocks.0.type', 'header'));
    }

    public function test_a_gm_can_save_a_template_as_blocks(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Feature', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'b1', 'type' => 'content', 'settings' => ['width' => 'wide'], 'css' => '.wb-body { color: red; }'],
                ['id' => 'b2', 'type' => 'header', 'settings' => ['summary' => false]],
                ['id' => 'b3', 'type' => 'facts', 'settings' => ['fields' => ['type', 'not_real'], 'columns' => 3]],
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = $world->templates()->firstOrFail();
        $this->assertSame(['content', 'header', 'facts'], collect($template->layout['blocks'])->pluck('type')->all());
        // Settings are merged over defaults; a bogus facts field is dropped.
        $this->assertFalse($template->layout['blocks'][1]['settings']['summary']);
        $this->assertSame(['type'], $template->layout['blocks'][2]['settings']['fields']);
        // Per-block CSS is stored.
        $this->assertSame('.wb-body { color: red; }', $template->layout['blocks'][0]['css']);
    }

    public function test_an_unknown_block_type_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Bad', 'kind' => 'location',
            'layout' => ['blocks' => [['type' => 'malware', 'settings' => []]]],
        ])->assertSessionHasErrors('layout.blocks.0.type');
    }

    public function test_a_content_block_width_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Wide', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'h', 'type' => 'header', 'settings' => []],
                ['id' => 'c', 'type' => 'content', 'settings' => ['width' => 'wide']],
            ]],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Hall', 'kind' => 'location', 'slug' => 'hall',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        // No banner and no facts block → banner hidden, facts off; content wide.
        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'hall']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('layout.width', 'wide')
                ->where('layout.banner', 'hide')
                ->where('layout.facts', 'off'));
    }

    public function test_block_settings_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Tuned', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'bn', 'type' => 'banner', 'settings' => ['height' => 'lg']],
                ['id' => 'h', 'type' => 'header', 'settings' => ['summary' => false, 'eyebrow' => true, 'readingTime' => true]],
                ['id' => 'r', 'type' => 'related', 'settings' => ['columns' => 1]],
            ]],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Tower', 'kind' => 'location', 'slug' => 'tower',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'tower']))
            ->assertInertia(fn (Assert $page) => $page
                // The block list reaches the reader with its per-block settings intact.
                ->where('blocks', fn ($blocks) => collect($blocks)->firstWhere('type', 'banner')['settings']['height'] === 'lg'
                    && collect($blocks)->firstWhere('type', 'header')['settings']['summary'] === false
                    && collect($blocks)->firstWhere('type', 'related')['settings']['columns'] === 1));
    }

    public function test_the_builder_can_preview_a_real_entry(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Sunspire Keep', 'kind' => 'location', 'slug' => 'sunspire',
            'is_private' => false, 'summary' => 'A cliff-top fortress.',
            'content' => str_repeat('word ', 400),
            'data' => ['type' => 'Fortress', 'population' => '1200'],
        ]);

        $this->actingAs($gm)->getJson(route('worlds.templates.preview', [$world->id, $entry->id]))
            ->assertOk()
            ->assertJson([
                'title' => 'Sunspire Keep',
                'eyebrow' => 'Location',
                'summary' => 'A cliff-top fortress.',
                'readingTime' => 2,
            ])
            ->assertJsonPath('facts', fn ($facts) => collect($facts)->firstWhere('key', 'type')['value'] === 'Fortress');
    }

    public function test_the_builder_page_lists_entries_by_kind(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create(['user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false]);

        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page->where('entriesByKind.location.0.title', 'Keep'));
    }

    public function test_a_preview_cannot_reach_another_worlds_entry(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $other = $gm->worlds()->create(['name' => 'Other', 'visibility' => 'public']);
        $foreign = $other->documents()->create(['user_id' => $gm->id, 'title' => 'X', 'kind' => 'location', 'slug' => 'x', 'is_private' => false]);

        $this->actingAs($gm)->getJson(route('worlds.templates.preview', [$world->id, $foreign->id]))
            ->assertNotFound();
    }

    public function test_custom_text_blocks_reach_the_reader_positioned_around_content(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Noted', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'intro', 'type' => 'text', 'settings' => ['markdown' => 'An intro note.']],
                ['id' => 'c', 'type' => 'content', 'settings' => []],
                ['id' => 'outro', 'type' => 'text', 'settings' => ['markdown' => 'An outro note.']],
            ]],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Vault', 'kind' => 'location', 'slug' => 'vault',
            'is_private' => false, 'template_id' => $template->id, 'content' => 'Body.',
        ]);

        // The blocks reach the reader with the two text blocks either side of the content block.
        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'vault']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks', fn ($blocks) => collect($blocks)->pluck('type')->all() === ['text', 'content', 'text']
                    && collect($blocks)->firstWhere('id', 'intro')['settings']['markdown'] === 'An intro note.'));
    }

    public function test_a_columns_block_holds_child_blocks_per_column(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Split', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'cols', 'type' => 'columns', 'settings' => ['count' => 2, 'cols' => [
                    // Column 1: quick facts. Column 2: content + a nested columns block that must be dropped.
                    [['id' => 'f', 'type' => 'facts', 'settings' => []]],
                    [
                        ['id' => 'c', 'type' => 'content', 'settings' => []],
                        ['id' => 'nested', 'type' => 'columns', 'settings' => ['count' => 2]],
                    ],
                ]]],
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $block = $world->templates()->firstOrFail()->layout['blocks'][0];
        $this->assertCount(2, $block['settings']['cols']);
        $this->assertSame('facts', $block['settings']['cols'][0][0]['type']);
        // The nested columns block is rejected; only the content block survives in column 2.
        $this->assertSame(['content'], collect($block['settings']['cols'][1])->pluck('type')->all());
    }

    public function test_columns_children_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Two up', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'cols', 'type' => 'columns', 'settings' => ['count' => 2, 'cols' => [
                    [['id' => 'f', 'type' => 'facts', 'settings' => []]],
                    [['id' => 'c', 'type' => 'content', 'settings' => []]],
                ]]],
            ]],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Hold', 'kind' => 'location', 'slug' => 'hold',
            'is_private' => false, 'template_id' => $template->id, 'content' => 'Body.',
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'hold']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'columns')
                ->where('blocks.0.settings.cols.0.0.type', 'facts')
                ->where('blocks.0.settings.cols.1.0.type', 'content'));
    }

    public function test_a_columns_count_clamps_its_column_arrays(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'c', 'type' => 'columns', 'settings' => ['count' => 3, 'cols' => [
                [['id' => 't', 'type' => 'text', 'settings' => ['markdown' => 'hi']]],
            ]]],
        ]);

        // count 3 → three column arrays even though only one was supplied.
        $this->assertCount(3, $blocks[0]['settings']['cols']);
        $this->assertSame('text', $blocks[0]['settings']['cols'][0][0]['type']);
        $this->assertSame([], $blocks[0]['settings']['cols'][2]);
    }

    public function test_a_reference_block_pulls_the_compendium_item_into_the_reader_embeds(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $goblin = $world->compendiumItems()->create([
            'user_id' => $gm->id, 'item_type' => 'monster', 'slug' => 'goblin', 'name' => 'Goblin',
            'fields' => ['block' => ['name' => 'Goblin']],
        ]);
        $template = $world->templates()->create([
            'name' => 'Statted', 'kind' => 'npc',
            'layout' => ['blocks' => [
                ['id' => 'r', 'type' => 'reference', 'settings' => ['refId' => $goblin->id]],
            ]],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Grix', 'kind' => 'npc', 'slug' => 'grix',
            'is_private' => false, 'template_id' => $template->id, 'content' => 'A goblin.',
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('npc'), 'grix']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'reference')
                ->where('blocks.0.settings.refId', $goblin->id)
                // The referenced item is resolved into the embeds the reader renders it from.
                ->where('embeds', fn ($embeds) => collect($embeds)->firstWhere('id', $goblin->id)['name'] === 'Goblin'));
    }

    public function test_a_reference_block_ref_id_is_coerced_to_an_int(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'r', 'type' => 'reference', 'settings' => ['refId' => '42']],
            ['id' => 'r2', 'type' => 'reference', 'settings' => ['refId' => 'nope']],
        ]);

        $this->assertSame(42, $blocks[0]['settings']['refId']);
        $this->assertNull($blocks[1]['settings']['refId']);
    }

    public function test_the_home_builder_uses_the_home_block_catalogue(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.home', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Worlds/TemplateBuilder')
                ->where('target', 'home')
                ->where('template', null)
                ->has('blockTypes.hero')
                ->has('blockTypes.sections')
                ->where('blockTypes', fn ($types) => ! isset($types['banner'])));
    }

    public function test_saving_a_home_template_is_a_singleton_and_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $payload = [
            'name' => 'Home page', 'target' => 'home',
            'layout' => ['blocks' => [
                ['id' => 'h', 'type' => 'hero', 'settings' => ['stats' => false]],
                ['id' => 's', 'type' => 'sections', 'settings' => ['columns' => 2]],
            ]],
        ];
        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();
        // Saving again upserts — still one home template.
        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), $payload)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, $world->templates()->where('target', 'home')->count());
        $home = $world->templates()->where('target', 'home')->firstOrFail();
        $this->assertSame('', $home->kind);

        // The reader home page renders from the template's blocks.
        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Campaign')
                ->where('blocks.0.type', 'hero')
                ->where('blocks.1.type', 'sections'));
    }

    public function test_a_home_template_rejects_an_entry_only_block(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Home', 'target' => 'home',
            'layout' => ['blocks' => [['id' => 'f', 'type' => 'facts', 'settings' => []]]],
        ])->assertSessionHasErrors('layout.blocks.0.type');
    }

    public function test_an_archive_template_is_one_per_section_and_reaches_the_section_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create(['user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false]);

        $payload = [
            'name' => 'Gallery', 'target' => 'archive', 'section' => 'locations',
            'layout' => ['blocks' => [
                ['id' => 'h', 'type' => 'heading', 'settings' => []],
                ['id' => 'g', 'type' => 'grid', 'settings' => ['columns' => 4, 'sort' => 'title']],
            ]],
        ];
        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), $payload)->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), $payload)->assertRedirect();

        // One-per-section (upserted).
        $this->assertSame(1, $world->templates()->where('target', 'archive')->where('section', 'locations')->count());

        // The section reader renders from the archive template's blocks.
        $this->get(route('public.section', [$world, 'locations']))
            ->assertInertia(fn (Assert $page) => $page
                ->component('Public/Section')
                ->where('blocks.0.type', 'heading')
                ->where('blocks.1.type', 'grid'));

        // A different section without a template still uses the default listing.
        $this->get(route('public.section', [$world, 'people']))
            ->assertInertia(fn (Assert $page) => $page->where('blocks', []));
    }

    public function test_the_archive_builder_preselects_a_chosen_section(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.create', ['world' => $world->id, 'target' => 'archive', 'section' => 'people']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('target', 'archive')
                ->where('initialSection', 'people'));

        // An unknown section falls back to null (the builder then uses the first section).
        $this->actingAs($gm)->get(route('worlds.templates.create', ['world' => $world->id, 'target' => 'archive', 'section' => 'nope']))
            ->assertInertia(fn (Assert $page) => $page->where('initialSection', null));
    }

    public function test_the_table_block_exposes_column_toggles_and_field_columns(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.create', ['world' => $world->id, 'target' => 'archive']))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockTypes.table.settings.showKind')
                ->has('blockTypes.table.settings.summary')
                ->has('blockTypes.table.settings.showUpdated')
                ->has('blockTypes.table.settings.showCreated')
                ->has('blockTypes.table.settings.fields')
                // The section's fields are offered for the column picker.
                ->where('fieldsBySection.locations', fn ($fields) => collect($fields)->pluck('key')->contains('type')));
    }

    public function test_a_table_field_column_resolves_the_field_value_on_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep',
            'is_private' => false, 'data' => ['type' => 'City', 'population' => '8000'],
        ]);
        $world->templates()->create([
            'name' => 'T', 'kind' => '', 'target' => 'archive', 'section' => 'locations',
            'layout' => ['blocks' => [['id' => 't', 'type' => 'table', 'settings' => ['fields' => ['type', 'population']]]]],
        ]);

        $this->get(route('public.section', [$world, 'locations']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.settings.fields', ['type', 'population'])
                ->has('fieldLabels.type')
                ->where('items.0.fields.type', 'City')
                ->where('items.0.fields.population', '8000'));
    }

    public function test_the_archive_builder_previews_against_real_section_entries(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Lady Merrow', 'kind' => 'npc', 'slug' => 'merrow',
            'is_private' => false, 'data' => ['role' => 'Harbourmaster'],
        ]);

        $this->actingAs($gm)->get(route('worlds.templates.create', ['world' => $world->id, 'target' => 'archive', 'section' => 'people']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sectionSampleItems.people.0.title', 'Lady Merrow')
                ->where('sectionSampleItems.people.0.fields.role', 'Harbourmaster'));
    }

    public function test_an_archive_template_requires_a_valid_section(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Bad', 'target' => 'archive', 'section' => 'not-a-section',
            'layout' => ['blocks' => [['id' => 'h', 'type' => 'heading', 'settings' => []]]],
        ])->assertSessionHasErrors('section');
    }

    public function test_the_new_content_blocks_are_available_and_sanitised(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // Every new content block is in the entry catalogue.
        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockTypes.callout')
                ->has('blockTypes.readaloud')
                ->has('blockTypes.secret')
                ->has('blockTypes.quote')
                ->has('blockTypes.button')
                ->has('blockTypes.spacer')
                ->has('blockTypes.toc')
                ->has('blockTypes.stats')
                ->has('blockTypes.image')
                ->has('blockTypes.accordion'));

        // Repeatable rows on stats/accordion are sanitised to their keyed shape.
        $blocks = TemplateBlocks::sanitise([
            ['id' => 's', 'type' => 'stats', 'settings' => ['columns' => 4, 'items' => [
                ['value' => '12k', 'label' => 'Population', 'junk' => 'x'],
                'not-an-object',
            ]]],
            ['id' => 'a', 'type' => 'accordion', 'settings' => ['panes' => [
                ['title' => 'History', 'markdown' => 'Long ago…'],
            ]]],
        ]);

        $this->assertSame(['label' => 'Population', 'value' => '12k'], $blocks[0]['settings']['items'][0]);
        $this->assertSame('', $blocks[0]['settings']['items'][1]['value']);
        $this->assertSame('History', $blocks[1]['settings']['panes'][0]['title']);
    }

    public function test_blocks_carry_a_palette_category_group(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blockTypes.callout.group', 'Content')
                ->where('blockTypes.columns.group', 'Layout')
                ->where('blockTypes.image.group', 'Media')
                ->where('blockTypes.facts.group', 'Data')
                ->where('blockTypes.banner.group', 'Structure'));
    }

    public function test_home_and_archive_pages_share_the_common_blocks(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // The common content/layout/media blocks appear on the home and archive catalogues too, while the
        // entry-only structural blocks (banner, facts) do not.
        foreach (['home', 'archive'] as $target) {
            $this->actingAs($gm)->get(route('worlds.templates.create', ['world' => $world->id, 'target' => $target]))
                ->assertInertia(fn (Assert $page) => $page
                    ->has('blockTypes.callout')
                    ->has('blockTypes.quote')
                    ->has('blockTypes.button')
                    ->has('blockTypes.image')
                    ->has('blockTypes.columns')
                    ->where('blockTypes', fn ($types) => ! isset($types['banner']) && ! isset($types['facts'])));
        }
    }

    public function test_a_home_template_can_use_a_common_block_and_it_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Home', 'target' => 'home',
            'layout' => ['blocks' => [
                ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'A world awaits.']],
                ['id' => 'c', 'type' => 'columns', 'settings' => ['count' => 2, 'cols' => [
                    [['id' => 'cta', 'type' => 'button', 'settings' => ['label' => 'Enter', 'url' => '/start']]],
                    [['id' => 't', 'type' => 'text', 'settings' => ['markdown' => 'Hello']]],
                ]]],
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'quote')
                ->where('blocks.0.settings.text', 'A world awaits.')
                ->where('blocks.1.type', 'columns')
                ->where('blocks.1.settings.cols.0.0.type', 'button')
                ->where('blocks.1.settings.cols.1.0.type', 'text'));
    }

    public function test_the_archive_listing_blocks_are_available_and_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create(['user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false]);

        // The archive catalogue offers the filter bar, table and A–Z index.
        $this->actingAs($gm)->get(route('worlds.templates.create', ['world' => $world->id, 'target' => 'archive']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('target', 'archive')
                ->has('blockTypes.filter')
                ->has('blockTypes.table')
                ->has('blockTypes.index'));

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Powered', 'target' => 'archive', 'section' => 'locations',
            'layout' => ['blocks' => [
                ['id' => 'f', 'type' => 'filter', 'settings' => ['placeholder' => 'Find a place…', 'sort' => true]],
                ['id' => 't', 'type' => 'table', 'settings' => ['summary' => false, 'sort' => 'title']],
                ['id' => 'i', 'type' => 'index', 'settings' => ['jumpbar' => true]],
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->get(route('public.section', [$world, 'locations']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'filter')
                ->where('blocks.0.settings.placeholder', 'Find a place…')
                ->where('blocks.1.type', 'table')
                ->where('blocks.1.settings.summary', false)
                ->where('blocks.2.type', 'index'));
    }

    public function test_the_home_engagement_blocks_are_available_and_carry_campaign_data(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'The Salt Accord', 'visibility' => 'public']);
        $session = $campaign->sessions()->create(['title' => 'Session 1']);
        $session->recap()->create([
            'user_id' => $gm->id, 'disk' => 's3', 'path' => 'recaps/1/a.wav',
            'detail_level' => 'comprehensive', 'status' => 'done',
            'recap_short' => 'The tide rolled in.',
        ]);

        // The home catalogue offers the search bar, campaign spotlight and session recaps.
        $this->actingAs($gm)->get(route('worlds.templates.home', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockTypes.search')
                ->has('blockTypes.spotlight')
                ->has('blockTypes.recaps'));

        $world->templates()->create([
            'name' => 'Home', 'kind' => '', 'target' => 'home',
            'layout' => ['blocks' => [
                ['id' => 's', 'type' => 'search', 'settings' => []],
                ['id' => 'c', 'type' => 'spotlight', 'settings' => ['columns' => 2]],
                ['id' => 'r', 'type' => 'recaps', 'settings' => ['count' => 3]],
            ]],
        ]);

        // The reader home page carries the campaigns and recaps those blocks render from.
        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.1.type', 'spotlight')
                ->where('blocks.2.type', 'recaps')
                ->where('campaigns', fn ($campaigns) => collect($campaigns)
                    ->firstWhere('name', 'The Salt Accord')['session_count'] === 1)
                ->where('recaps.0.title', 'Session 1')
                ->where('recaps.0.campaign', 'The Salt Accord')
                ->where('recaps.0.summary', 'The tide rolled in.'));
    }

    public function test_the_entries_block_carries_a_sortable_filterable_pool(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create(['user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false]);

        // The generalised block offers the kind, sort, column and visibility controls, with the kind
        // filter grouped by section.
        $this->actingAs($gm)->get(route('worlds.templates.home', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blockTypes.recent.label', 'Entries')
                ->has('blockTypes.recent.settings.kinds')
                ->has('blockTypes.recent.settings.sort')
                ->has('blockTypes.recent.settings.columns')
                ->has('blockTypes.recent.settings.showSummary')
                ->where('blockTypes.recent.settings.kinds.groups', fn ($groups) => collect($groups)
                    ->firstWhere('label', 'Locations')['kinds'][0]['value'] === 'location'));

        $world->templates()->create([
            'name' => 'Home', 'kind' => '', 'target' => 'home',
            'layout' => ['blocks' => [
                ['id' => 'e', 'type' => 'recent', 'settings' => ['kinds' => ['location', 'npc'], 'sort' => 'created', 'columns' => 3, 'showSummary' => true]],
            ]],
        ]);

        // The reader home page carries the pool the block filters/sorts, and every card has the fields the
        // client needs to sort by newest/recently-changed.
        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.settings.kinds', ['location', 'npc'])
                ->where('blocks.0.settings.sort', 'created')
                ->where('blocks.0.settings.columns', 3)
                ->where('blocks.0.settings.showSummary', true)
                ->where('entries', fn ($entries) => collect($entries)
                    ->firstWhere('title', 'Keep')['created_at'] !== null));
    }

    public function test_an_entries_block_sanitises_kinds_and_migrates_the_legacy_single_kind(): void
    {
        // Unknown kinds are dropped and duplicates collapsed.
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'e', 'type' => 'recent', 'settings' => ['kinds' => ['location', 'location', 'not-a-kind']]],
        ], true, 'home');
        $this->assertSame(['location'], $blocks[0]['settings']['kinds']);

        // A template saved against the earlier single-`kind` shape is migrated to the `kinds` list.
        $legacy = TemplateBlocks::sanitise([
            ['id' => 'e', 'type' => 'recent', 'settings' => ['kind' => 'npc']],
        ], true, 'home');
        $this->assertSame(['npc'], $legacy[0]['settings']['kinds']);
        $this->assertArrayNotHasKey('kind', $legacy[0]['settings']);
    }

    public function test_the_default_home_page_skips_the_campaign_lookups(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->campaigns()->create(['name' => 'Unused', 'visibility' => 'public']);

        // With no home template, the built-in layout renders and the campaign/recap payloads stay empty.
        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks', [])
                ->where('campaigns', [])
                ->where('recaps', []));
    }

    public function test_the_facets_block_is_available_and_the_reader_cards_carry_tags(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Saltmarsh', 'kind' => 'location', 'slug' => 'saltmarsh',
            'is_private' => false, 'tags' => ['coastal', 'ruined'],
        ]);

        // The archive catalogue offers the filter-chips block.
        $this->actingAs($gm)->get(route('worlds.templates.create', ['world' => $world->id, 'target' => 'archive']))
            ->assertInertia(fn (Assert $page) => $page->has('blockTypes.facets'));

        $world->templates()->create([
            'name' => 'Faceted', 'kind' => '', 'target' => 'archive', 'section' => 'locations',
            'layout' => ['blocks' => [
                ['id' => 'f', 'type' => 'facets', 'settings' => ['showTags' => true]],
                ['id' => 'g', 'type' => 'grid', 'settings' => []],
            ]],
        ]);

        // The section reader renders the block and every card carries its tags for client-side filtering.
        $this->get(route('public.section', [$world, 'locations']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'facets')
                ->where('items.0.tags', ['coastal', 'ruined']));
    }

    public function test_the_batch_two_blocks_are_available_and_sanitised(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockTypes.video')
                ->has('blockTypes.tabs')
                ->has('blockTypes.gallery')
                ->has('blockTypes.events')
                ->has('blockTypes.linked'));

        $blocks = TemplateBlocks::sanitise([
            ['id' => 'g', 'type' => 'gallery', 'settings' => ['columns' => 3, 'images' => [
                '  https://cdn.test/a.jpg  ',
                '',
                123,
            ]]],
            ['id' => 't', 'type' => 'events', 'settings' => ['events' => [
                ['when' => '1267', 'title' => 'The Sundering', 'detail' => 'It broke', 'junk' => 'x'],
                'nope',
            ]]],
            ['id' => 'l', 'type' => 'linked', 'settings' => ['ids' => [5, '5', 'x', 9]]],
        ]);

        $this->assertSame(['https://cdn.test/a.jpg', '123'], $blocks[0]['settings']['images']);
        $this->assertSame(
            ['when' => '1267', 'title' => 'The Sundering', 'detail' => 'It broke'],
            $blocks[1]['settings']['events'][0],
        );
        $this->assertSame([5, 9], $blocks[2]['settings']['ids']);
    }

    public function test_a_linked_entries_block_pulls_cards_into_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $target = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Neighbour', 'kind' => 'location', 'slug' => 'neighbour', 'is_private' => false,
        ]);
        $template = $world->templates()->create([
            'name' => 'Linked', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'l', 'type' => 'linked', 'settings' => ['ids' => [$target->id]]],
            ]],
        ]);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Home', 'kind' => 'location', 'slug' => 'home', 'is_private' => false,
            'template_id' => $template->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'home']))
            ->assertInertia(fn (Assert $page) => $page
                ->where("linkedCards.{$target->id}.title", 'Neighbour'));
    }

    public function test_a_map_block_resolves_the_map_and_its_pins_into_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $map = $world->maps()->create(['name' => 'Coast']);
        $map->pins()->create(['x' => 10, 'y' => 20, 'label' => 'Docks']);

        // The map picker is offered to the builder, and the entry catalogue lists the map block.
        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockTypes.map')
                ->where('mapOptions.0.name', 'Coast'));

        $template = $world->templates()->create([
            'name' => 'Mapped', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'm', 'type' => 'map', 'settings' => ['mapId' => $map->id]],
            ]],
        ]);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Home', 'kind' => 'location', 'slug' => 'home', 'is_private' => false,
            'template_id' => $template->id,
        ]);

        $this->actingAs($gm)->get(route('public.article', [$world, Sections::typeSlug('location'), 'home']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'map')
                ->where('blocks.0.settings.mapId', $map->id)
                ->where("maps.{$map->id}.name", 'Coast')
                ->where("maps.{$map->id}.pins.0.label", 'Docks'));
    }

    public function test_a_map_block_id_is_coerced_to_an_int(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'm', 'type' => 'map', 'settings' => ['mapId' => '7']],
            ['id' => 'n', 'type' => 'map', 'settings' => ['mapId' => 'nope']],
        ]);

        $this->assertSame(7, $blocks[0]['settings']['mapId']);
        $this->assertNull($blocks[1]['settings']['mapId']);
        $this->assertSame([7], TemplateBlocks::mapIds($blocks));
    }

    public function test_the_new_blocks_are_in_the_catalogue(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockTypes.connections')
                ->has('blockTypes.comparison')
                ->has('blockTypes.meter')
                ->has('blockTypes.faq')
                ->has('blockTypes.random'));

        // The self-contained ones reach home too; "next session" is home-only.
        $this->actingAs($gm)->get(route('worlds.templates.home', $world->id))
            ->assertInertia(fn (Assert $page) => $page
                ->has('blockTypes.nextsession')
                ->has('blockTypes.meter')
                ->has('blockTypes.faq')
                ->has('blockTypes.random')
                ->where('blockTypes', fn ($types) => ! isset($types['connections'])));
    }

    public function test_a_connections_block_lists_the_entrys_neighbours(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $keep = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false]);
        $ruler = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Lord Vane', 'kind' => 'npc', 'slug' => 'vane', 'is_private' => false]);
        DocumentLink::create([
            'world_id' => $world->id, 'from_document_id' => $keep->id, 'to_document_id' => $ruler->id,
            'relationship' => 'ruled_by', 'source' => 'manual',
        ]);

        $template = $world->templates()->create(['name' => 'T', 'kind' => 'location', 'layout' => ['blocks' => [
            ['id' => 'c', 'type' => 'connections', 'settings' => []],
        ]]]);
        $keep->update(['template_id' => $template->id]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'keep']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('connections', fn ($connections) => collect($connections)->contains('title', 'Lord Vane')));
    }

    public function test_a_comparison_block_resolves_the_compared_entries(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $aldby = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Aldby', 'kind' => 'location', 'slug' => 'aldby', 'is_private' => false]);
        $entry = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Home', 'kind' => 'location', 'slug' => 'home', 'is_private' => false]);

        $template = $world->templates()->create(['name' => 'T', 'kind' => 'location', 'layout' => ['blocks' => [
            ['id' => 'cmp', 'type' => 'comparison', 'settings' => ['ids' => [$aldby->id]]],
        ]]]);
        $entry->update(['template_id' => $template->id]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'home']))
            ->assertInertia(fn (Assert $page) => $page
                ->where("comparison.{$aldby->id}.title", 'Aldby')
                ->has("comparison.{$aldby->id}.facts"));
    }

    public function test_the_random_route_redirects_to_a_visible_entry_of_a_chosen_kind(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->documents()->create(['user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false]);

        $this->get(route('public.random', [$world, 'kinds' => 'location']))
            ->assertRedirect(url("/w/{$world->slug}/location/keep"));
    }

    public function test_the_next_session_block_carries_the_soonest_scheduled_game(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $campaign = $world->campaigns()->create(['name' => 'Salt', 'visibility' => 'public']);
        $campaign->scheduleEvents()->create(['title' => 'Session 4', 'starts_at' => now()->addDays(3)]);

        $world->templates()->create(['name' => 'Home', 'kind' => '', 'target' => 'home', 'layout' => ['blocks' => [
            ['id' => 'n', 'type' => 'nextsession', 'settings' => []],
        ]]]);

        $this->actingAs($gm)->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('nextSession.title', 'Session 4')
                ->where('nextSession.campaign', 'Salt'));
    }

    public function test_a_block_can_carry_a_conditional_visibility_rule(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Hi'], 'visibleIf' => ['field' => 'ruler', 'op' => 'eq', 'value' => 'Vane']],
            ['id' => 'b', 'type' => 'button', 'settings' => ['url' => '/x'], 'visibleIf' => ['field' => '', 'op' => 'set']],
            ['id' => 'c', 'type' => 'callout', 'settings' => []],
        ]);

        $this->assertSame(['field' => 'ruler', 'op' => 'eq', 'value' => 'Vane'], $blocks[0]['visibleIf']);
        $this->assertNull($blocks[1]['visibleIf']); // no field → no rule
        $this->assertNull($blocks[2]['visibleIf']); // no rule at all
    }

    public function test_an_unknown_visibility_operator_falls_back_to_set(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Hi'], 'visibleIf' => ['field' => 'ruler', 'op' => 'DROP TABLE', 'value' => 'x']],
        ]);

        $this->assertSame('set', $blocks[0]['visibleIf']['op']);
    }

    public function test_a_visibility_rule_saved_through_the_builder_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Gated', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Secret'], 'visibleIf' => ['field' => 'ruler', 'op' => 'set', 'value' => '']],
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = $world->templates()->firstOrFail();
        $this->assertSame(['field' => 'ruler', 'op' => 'set', 'value' => ''], $template->layout['blocks'][0]['visibleIf']);
    }

    public function test_a_block_can_be_limited_to_a_device(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Hi'], 'device' => 'mobile'],
            ['id' => 'b', 'type' => 'button', 'settings' => ['url' => '/x'], 'device' => 'nonsense'],
            ['id' => 'c', 'type' => 'callout', 'settings' => []],
        ]);

        $this->assertSame('mobile', $blocks[0]['device']);
        $this->assertSame('all', $blocks[1]['device']); // invalid → all
        $this->assertSame('all', $blocks[2]['device']); // default
    }

    public function test_the_device_setting_saves_through_the_builder(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Responsive', 'kind' => 'location',
            'layout' => ['blocks' => [
                ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Hi'], 'device' => 'desktop'],
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('desktop', $world->templates()->firstOrFail()->layout['blocks'][0]['device']);
    }

    public function test_a_repeater_carries_its_child_blocks_to_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $entry = $world->documents()->create(['user_id' => $gm->id, 'title' => 'Home', 'kind' => 'location', 'slug' => 'home', 'is_private' => false]);

        $template = $world->templates()->create(['name' => 'T', 'kind' => 'location', 'layout' => ['blocks' => [
            ['id' => 'r', 'type' => 'repeater', 'settings' => ['source' => 'connections', 'blocks' => [
                ['id' => 't', 'type' => 'text', 'settings' => ['markdown' => '- {{ item.title }}']],
            ]]],
        ]]]);
        $entry->update(['template_id' => $template->id]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'home']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'repeater')
                ->where('blocks.0.settings.source', 'connections')
                ->where('blocks.0.settings.blocks.0.type', 'text')
                ->where('blocks.0.settings.blocks.0.settings.markdown', '- {{ item.title }}'));
    }

    public function test_a_repeater_holds_child_blocks_but_rejects_nested_containers(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'r', 'type' => 'repeater', 'settings' => ['source' => 'related', 'blocks' => [
                ['id' => 't', 'type' => 'text', 'settings' => ['markdown' => 'Hi']],
                ['id' => 'c', 'type' => 'columns', 'settings' => ['count' => 2]],
                ['id' => 'r2', 'type' => 'repeater', 'settings' => []],
            ]]],
        ]);

        // The text child survives; a nested columns/repeater is dropped (containers don't nest).
        $this->assertSame(['text'], collect($blocks[0]['settings']['blocks'])->pluck('type')->all());
    }

    public function test_an_faq_block_sanitises_to_question_answer_rows(): void
    {
        $blocks = TemplateBlocks::sanitise([
            ['id' => 'f', 'type' => 'faq', 'settings' => ['items' => [
                ['question' => 'What?', 'answer' => 'This.', 'junk' => 'x'],
                'nope',
            ]]],
        ]);

        $this->assertSame(['question' => 'What?', 'answer' => 'This.'], $blocks[0]['settings']['items'][0]);
    }

    public function test_the_block_target_catalogue_is_common_blocks_only(): void
    {
        $keys = TemplateBlocks::typeKeys('block');

        $this->assertContains('text', $keys);
        $this->assertContains('callout', $keys);
        $this->assertNotContains('columns', $keys);   // no nested containers
        $this->assertNotContains('reusable', $keys);  // no nested reusables
        $this->assertNotContains('facts', $keys);     // entry-only structural block excluded
    }

    public function test_reusable_ids_are_collected_including_inside_columns(): void
    {
        $ids = TemplateBlocks::reusableIds([
            ['type' => 'reusable', 'settings' => ['refId' => 7]],
            ['type' => 'columns', 'settings' => ['cols' => [
                [['type' => 'reusable', 'settings' => ['refId' => 9]]],
            ]]],
        ]);

        $this->assertSame([7, 9], $ids);
    }

    public function test_a_reusable_block_is_created_referenced_and_resolves_on_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        // Create a reusable block set.
        $this->actingAs($gm)->post(route('worlds.blocks.store', $world->id), [
            'name' => 'Footer CTA',
            'layout' => ['blocks' => [
                ['id' => 'b', 'type' => 'button', 'settings' => ['label' => 'Join us', 'url' => '/join']],
            ]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $block = WorldBlock::where('world_id', $world->id)->firstOrFail();
        $this->assertSame('Footer CTA', $block->name);
        $this->assertSame(['button'], collect($block->layout['blocks'])->pluck('type')->all());

        // A container block isn't allowed in a reusable set (kept flat) — it's rejected outright.
        $this->actingAs($gm)->post(route('worlds.blocks.store', $world->id), [
            'name' => 'Bad', 'layout' => ['blocks' => [['type' => 'columns', 'settings' => ['count' => 2]]]],
        ])->assertSessionHasErrors('layout.blocks.0.type');

        // Reference it from an entry template, and confirm the reader resolves the set.
        $template = $world->templates()->create(['name' => 'T', 'kind' => 'location', 'layout' => ['blocks' => [
            ['id' => 'r', 'type' => 'reusable', 'settings' => ['refId' => $block->id]],
        ]]]);
        $entry = $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Home', 'kind' => 'location', 'slug' => 'home',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'home']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.0.type', 'reusable')
                ->where('blocks.0.settings.refId', $block->id)
                ->where("reusableBlocks.{$block->id}.0.type", 'button'));
    }

    public function test_a_reusable_block_resolves_on_the_home_page_too(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $block = WorldBlock::create([
            'world_id' => $world->id, 'name' => 'Footer',
            'layout' => ['blocks' => [['id' => 'b', 'type' => 'button', 'settings' => ['label' => 'Join', 'url' => '/join']]]],
        ]);
        $world->templates()->create([
            'name' => 'Home', 'kind' => '', 'target' => 'home',
            'layout' => ['blocks' => [
                ['id' => 'h', 'type' => 'hero', 'settings' => []],
                ['id' => 'r', 'type' => 'reusable', 'settings' => ['refId' => $block->id]],
            ]],
        ]);

        $this->get(route('public.world', $world))
            ->assertInertia(fn (Assert $page) => $page
                ->where('blocks.1.type', 'reusable')
                ->where("reusableBlocks.{$block->id}.0.type", 'button'));
    }

    public function test_a_reusable_block_exports_and_imports(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $block = WorldBlock::create([
            'world_id' => $world->id, 'name' => 'Footer',
            'layout' => ['blocks' => [['id' => 'b', 'type' => 'button', 'settings' => ['label' => 'Join', 'url' => '/join']]]],
        ]);

        // Export is a portable, tagged blob.
        $response = $this->actingAs($gm)->get(route('worlds.blocks.export', $block->id));
        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="footer.json"');
        $response->assertJson(['worldbuilder_block' => 1, 'name' => 'Footer']);

        // Importing it (sanitised) creates a second reusable block.
        $payload = json_encode([
            'worldbuilder_block' => 1, 'name' => 'Footer copy',
            'layout' => ['blocks' => [
                ['id' => 'b', 'type' => 'button', 'settings' => ['label' => 'Hi', 'url' => '/hi']],
                ['id' => 'm', 'type' => 'malware', 'settings' => []],
            ]],
        ]);
        $this->actingAs($gm)->post(route('worlds.blocks.import', $world->id), ['payload' => $payload])
            ->assertRedirect()->assertSessionHasNoErrors();

        $imported = WorldBlock::where('world_id', $world->id)->where('name', 'Footer copy')->firstOrFail();
        $this->assertSame(['button'], collect($imported->layout['blocks'])->pluck('type')->all());
    }

    public function test_the_builder_lists_reusable_blocks_for_the_palette(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        WorldBlock::create(['world_id' => $world->id, 'name' => 'Footer', 'layout' => ['blocks' => []]]);

        $this->actingAs($gm)->get(route('worlds.templates.create', $world->id))
            ->assertInertia(fn (Assert $page) => $page->where('reusableBlocks.0.name', 'Footer'));
    }

    public function test_a_default_template_styles_entries_of_its_kind_without_their_own(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->templates()->create([
            'name' => 'Every location', 'kind' => 'location', 'target' => 'entry', 'is_default' => true,
            'layout' => ['blocks' => [['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Default look']]]],
        ]);
        // A location with no template of its own picks up the kind default.
        $world->documents()->create(['user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep', 'is_private' => false]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'keep']))
            ->assertInertia(fn (Assert $page) => $page->where('blocks.0.settings.text', 'Default look'));
    }

    public function test_an_entrys_own_template_overrides_the_kind_default(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $world->templates()->create([
            'name' => 'Default', 'kind' => 'location', 'target' => 'entry', 'is_default' => true,
            'layout' => ['blocks' => [['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Default']]]],
        ]);
        $own = $world->templates()->create([
            'name' => 'Special', 'kind' => 'location', 'target' => 'entry',
            'layout' => ['blocks' => [['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Bespoke']]]],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep',
            'is_private' => false, 'template_id' => $own->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'keep']))
            ->assertInertia(fn (Assert $page) => $page->where('blocks.0.settings.text', 'Bespoke'));
    }

    public function test_setting_a_new_default_clears_the_previous_one_for_that_kind(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $first = $world->templates()->create([
            'name' => 'First', 'kind' => 'location', 'target' => 'entry', 'is_default' => true,
            'layout' => ['blocks' => [['id' => 'c', 'type' => 'content', 'settings' => []]]],
        ]);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Second', 'kind' => 'location', 'is_default' => true,
            'layout' => ['blocks' => [['id' => 'c', 'type' => 'content', 'settings' => []]]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        // Only one default per kind remains — the newest.
        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame('Second', $world->templates()->where('kind', 'location')->where('is_default', true)->sole()->name);
    }

    public function test_a_template_exports_as_a_portable_json_blob(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'Gazetteer', 'kind' => 'location', 'target' => 'entry',
            'layout' => ['blocks' => [['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Hi']]]],
        ]);

        $response = $this->actingAs($gm)->get(route('worlds.templates.export', $template->id));
        $response->assertOk();
        $response->assertHeader('content-disposition', 'attachment; filename="gazetteer.json"');
        $response->assertJson([
            'worldbuilder_template' => 1,
            'name' => 'Gazetteer',
            'kind' => 'location',
            'target' => 'entry',
        ]);
        $this->assertSame('quote', $response->json('layout.blocks.0.type'));
    }

    public function test_a_template_can_be_imported_from_json_and_is_sanitised(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $payload = json_encode([
            'worldbuilder_template' => 1,
            'name' => 'Imported', 'kind' => 'location', 'target' => 'entry',
            'layout' => ['blocks' => [
                ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Ported']],
                ['id' => 'm', 'type' => 'malware', 'settings' => []],
            ]],
        ]);

        $this->actingAs($gm)->post(route('worlds.templates.import', $world->id), ['payload' => $payload])
            ->assertRedirect()->assertSessionHasNoErrors();

        $template = $world->templates()->where('name', 'Imported')->firstOrFail();
        // The unknown "malware" block is dropped by sanitisation; the quote survives.
        $this->assertSame(['quote'], collect($template->layout['blocks'])->pluck('type')->all());
        $this->assertSame('location', $template->kind);
    }

    public function test_strip_references_clears_world_scoped_ids_including_nested(): void
    {
        $blocks = TemplateBlocks::stripReferences([
            ['type' => 'reference', 'settings' => ['refId' => 5]],
            ['type' => 'map', 'settings' => ['mapId' => 3]],
            ['type' => 'linked', 'settings' => ['ids' => [1, 2]]],
            ['type' => 'columns', 'settings' => ['cols' => [
                [['type' => 'reusable', 'settings' => ['refId' => 9]]],
            ]]],
        ]);

        $this->assertNull($blocks[0]['settings']['refId']);
        $this->assertNull($blocks[1]['settings']['mapId']);
        $this->assertSame([], $blocks[2]['settings']['ids']);
        $this->assertNull($blocks[3]['settings']['cols'][0][0]['settings']['refId']);
    }

    public function test_importing_into_another_world_strips_id_references_but_keeps_them_at_home(): void
    {
        $gm = User::factory()->create();
        $worldA = $gm->worlds()->create(['name' => 'A', 'visibility' => 'public']);
        $worldB = $gm->worlds()->create(['name' => 'B', 'visibility' => 'public']);

        $payload = json_encode([
            'worldbuilder_template' => 1, 'world' => $worldA->id, 'name' => 'Ported', 'kind' => 'location', 'target' => 'entry',
            'layout' => ['blocks' => [['id' => 'r', 'type' => 'reference', 'settings' => ['refId' => 42]]]],
        ]);

        // Into a different world → the foreign compendium id is stripped.
        $this->actingAs($gm)->post(route('worlds.templates.import', $worldB->id), ['payload' => $payload])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($worldB->templates()->where('name', 'Ported')->firstOrFail()->layout['blocks'][0]['settings']['refId']);

        // Back into its home world → the reference is preserved.
        $this->actingAs($gm)->post(route('worlds.templates.import', $worldA->id), ['payload' => $payload])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(42, $worldA->templates()->where('name', 'Ported')->firstOrFail()->layout['blocks'][0]['settings']['refId']);
    }

    public function test_importing_a_non_template_blob_is_rejected(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.import', $world->id), ['payload' => 'not json'])
            ->assertSessionHasErrors('payload');

        $this->assertSame(0, $world->templates()->count());
    }

    public function test_template_sidebar_blocks_and_hide_flag_reach_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Sidebar', 'kind' => 'location',
            'layout' => [
                'blocks' => [['id' => 'c', 'type' => 'content', 'settings' => []]],
                'sidebar' => [
                    ['id' => 'q', 'type' => 'quote', 'settings' => ['text' => 'Aside']],
                    ['id' => 'x', 'type' => 'columns', 'settings' => ['count' => 2]],
                ],
                'hideSidebar' => false,
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = $world->templates()->firstOrFail();
        // The sidebar keeps its flat common block; a container (columns) is dropped.
        $this->assertSame(['quote'], collect($template->layout['sidebar'])->pluck('type')->all());
        $this->assertFalse($template->layout['hideSidebar']);

        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'keep']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sidebar.0.type', 'quote')
                ->where('sidebar.0.settings.text', 'Aside')
                ->where('layout.hideSidebar', false));
    }

    public function test_the_sidebar_catalogue_offers_the_widgets_and_common_blocks(): void
    {
        $keys = TemplateBlocks::typeKeys('sidebar');

        $this->assertContains('avatar', $keys);
        $this->assertContains('facts', $keys);
        $this->assertContains('notes', $keys);
        $this->assertContains('callout', $keys);   // common blocks too
        $this->assertNotContains('columns', $keys); // no containers in the sidebar
    }

    public function test_the_sidebar_starter_is_portrait_facts_notes(): void
    {
        $this->assertSame(
            ['avatar', 'facts', 'notes'],
            collect(TemplateBlocks::sidebarStarter())->pluck('type')->all(),
        );
    }

    public function test_a_custom_sidebar_of_widgets_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'S', 'kind' => 'location', 'target' => 'entry',
            'layout' => [
                'blocks' => [['id' => 'c', 'type' => 'content', 'settings' => []]],
                'sidebar' => [
                    ['id' => 'a', 'type' => 'avatar', 'settings' => ['shape' => 'circle']],
                    ['id' => 'f', 'type' => 'facts', 'settings' => []],
                    ['id' => 'n', 'type' => 'notes', 'settings' => []],
                ],
            ],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'keep']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('sidebar.0.type', 'avatar')
                ->where('sidebar.1.type', 'facts')
                ->where('sidebar.2.type', 'notes'));
    }

    public function test_the_template_width_setting_persists_and_overrides_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);

        $this->actingAs($gm)->post(route('worlds.templates.store', $world->id), [
            'name' => 'Wide', 'kind' => 'location',
            'layout' => [
                // Content block says normal, but the template-level width wins.
                'blocks' => [['id' => 'c', 'type' => 'content', 'settings' => ['width' => 'normal']]],
                'width' => 'wide',
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $template = $world->templates()->firstOrFail();
        $this->assertSame('wide', $template->layout['width']);

        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'keep']))
            ->assertInertia(fn (Assert $page) => $page->where('layout.width', 'wide'));
    }

    public function test_a_hidden_sidebar_reaches_the_reader(): void
    {
        $gm = User::factory()->create();
        $world = $gm->worlds()->create(['name' => 'W', 'visibility' => 'public']);
        $template = $world->templates()->create([
            'name' => 'No rail', 'kind' => 'location', 'target' => 'entry',
            'layout' => ['blocks' => [['id' => 'c', 'type' => 'content', 'settings' => []]], 'hideSidebar' => true],
        ]);
        $world->documents()->create([
            'user_id' => $gm->id, 'title' => 'Keep', 'kind' => 'location', 'slug' => 'keep',
            'is_private' => false, 'template_id' => $template->id,
        ]);

        $this->get(route('public.article', [$world, Sections::typeSlug('location'), 'keep']))
            ->assertInertia(fn (Assert $page) => $page->where('layout.hideSidebar', true));
    }

    public function test_normalise_migrates_a_legacy_layout(): void
    {
        $blocks = TemplateBlocks::normalise(
            ['facts' => 'sidebar', 'width' => 'normal', 'banner' => 'show', 'fields' => ['population']],
            'location',
        );

        $this->assertSame(['banner', 'header', 'facts', 'content', 'related'], collect($blocks)->pluck('type')->all());
        $this->assertSame(['population'], collect($blocks)->firstWhere('type', 'facts')['settings']['fields']);
    }
}
