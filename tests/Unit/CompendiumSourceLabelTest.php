<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Compendium;
use PHPUnit\Framework\TestCase;

class CompendiumSourceLabelTest extends TestCase
{
    public function test_it_maps_known_origins_to_friendly_names(): void
    {
        $this->assertSame('D&D Beyond', Compendium::sourceLabel('ddb', 'custom'));
        $this->assertSame('CritterDB', Compendium::sourceLabel('critterdb', 'custom'));
        $this->assertSame('Open5e', Compendium::sourceLabel('open5e', 'imported'));
        $this->assertSame('D&D 5e API', Compendium::sourceLabel('dnd5eapi', 'imported'));
    }

    public function test_it_falls_back_by_provider_when_there_is_no_known_origin(): void
    {
        $this->assertSame('Library', Compendium::sourceLabel(null, 'imported'));
        $this->assertSame('Homebrew', Compendium::sourceLabel(null, 'custom'));
        $this->assertSame('Homebrew', Compendium::sourceLabel('mystery', 'custom'));
    }
}
