<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\CreditWeights;
use Tests\TestCase;

class CreditWeightsTest extends TestCase
{
    public function test_a_recap_is_the_heaviest_content_type(): void
    {
        $this->assertSame(30, CreditWeights::for('Recap'));
    }

    public function test_a_monster_costs_more_than_a_light_entry(): void
    {
        $this->assertSame(2, CreditWeights::for('Monster'));
        $this->assertSame(1, CreditWeights::for('NPC'));
    }

    public function test_an_unlisted_type_falls_back_to_the_default_weight(): void
    {
        $this->assertSame(1, CreditWeights::for('Something Unmapped'));
    }

    public function test_a_feature_and_detail_resolve_to_the_content_type_weight(): void
    {
        // assistant_draft carries the entry kind in its detail.
        $this->assertSame(2, CreditWeights::forFeature('assistant_draft', 'monster'));
        $this->assertSame(1, CreditWeights::forFeature('assistant_draft', 'npc'));

        // Direct features are a content type in themselves.
        $this->assertSame(1, CreditWeights::forFeature('assistant_ask'));

        // A detail-less or unmapped feature falls back to the default weight.
        $this->assertSame(1, CreditWeights::forFeature('roll_table', 'roll_table'));
    }

    public function test_recap_credits_scale_with_audio_length_rounded_up(): void
    {
        $this->assertSame(60, CreditWeights::recapCredits(3600, 60)); // exactly 1 hour
        $this->assertSame(152, CreditWeights::recapCredits(9114, 60)); // 2.53h → 151.9 → 152
        $this->assertSame(100, CreditWeights::recapCredits(9000, 40)); // 2.5h at 40/hour
        $this->assertSame(0, CreditWeights::recapCredits(0, 60));
    }

    public function test_recap_cost_uses_the_configured_per_hour_rate(): void
    {
        // A 2.5-hour session at the live 12/hour rate → 30 credits (£3.00 at 10c/credit).
        $this->assertSame(30, CreditWeights::recapCreditCost(9000));
    }

    public function test_the_ui_cost_map_exposes_kind_and_action_costs(): void
    {
        $map = CreditWeights::uiCostMap();

        $this->assertSame(2, $map['kinds']['monster']);
        $this->assertSame(1, $map['kinds']['npc']);
        $this->assertSame(1, $map['actions']['assistant_chat']);
        $this->assertSame(CreditWeights::RECAP_CREDITS_PER_HOUR, $map['actions']['recap_per_hour']);
    }
}
