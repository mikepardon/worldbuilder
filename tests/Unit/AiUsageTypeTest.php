<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AiUsageType;
use Tests\TestCase;

class AiUsageTypeTest extends TestCase
{
    public function test_a_recap_feature_is_labelled_recap_regardless_of_detail(): void
    {
        $this->assertSame('Recap', AiUsageType::label('session_recap', 'analyze'));
    }

    public function test_a_draft_takes_its_content_type_from_the_document_kind(): void
    {
        $this->assertSame('Location', AiUsageType::label('assistant_draft', 'location'));
        $this->assertSame('NPC', AiUsageType::label('assistant_draft', 'npc'));
        $this->assertSame('Lore', AiUsageType::label('assistant_draft', 'lore'));
    }

    public function test_a_compendium_draft_takes_its_content_type_from_the_item_type(): void
    {
        $this->assertSame('Monster', AiUsageType::label('compendium_draft', 'monster'));
        $this->assertSame('Magic item', AiUsageType::label('compendium_admin', 'magicitem'));
    }

    public function test_a_generator_takes_its_content_type_from_its_kind(): void
    {
        $this->assertSame('Faction', AiUsageType::label('generator', 'faction'));
    }

    public function test_an_unknown_kind_is_humanised(): void
    {
        $this->assertSame('Wondrous Thing', AiUsageType::label('generator', 'wondrous_thing'));
    }

    public function test_a_legacy_feature_without_a_detail_is_humanised(): void
    {
        $this->assertSame('Session Ingest', AiUsageType::label('session_ingest', null));
    }
}
