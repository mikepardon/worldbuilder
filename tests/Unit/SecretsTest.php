<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Secrets;
use PHPUnit\Framework\TestCase;

class SecretsTest extends TestCase
{
    public function test_strip_removes_a_well_formed_secret_and_keeps_surrounding_text(): void
    {
        $stripped = Secrets::strip('Before. {{secret}}the vault code is 1234{{/}} After.');

        $this->assertStringNotContainsString('1234', $stripped);
        $this->assertStringContainsString('Before.', $stripped);
        $this->assertStringContainsString('After.', $stripped);
    }

    public function test_strip_preserves_public_text_between_two_separate_secrets(): void
    {
        $stripped = Secrets::strip('{{secret}}alpha{{/}} PUBLIC {{secret}}omega{{/}}');

        $this->assertStringContainsString('PUBLIC', $stripped);
        $this->assertStringNotContainsString('alpha', $stripped);
        $this->assertStringNotContainsString('omega', $stripped);
    }

    public function test_strip_removes_an_unclosed_secret_through_to_the_end(): void
    {
        // A missing {{/}} (a GM typo) must fail safe — everything after the opener is hidden.
        $stripped = Secrets::strip('Visible intro. {{secret}}the dragon sleeps here, code 4242');

        $this->assertStringContainsString('Visible intro.', $stripped);
        $this->assertStringNotContainsString('dragon', $stripped);
        $this->assertStringNotContainsString('4242', $stripped);
    }

    public function test_strip_removes_nested_secrets_entirely(): void
    {
        $stripped = Secrets::strip('{{secret}}outer {{secret}}inner{{/}} still secret{{/}} shown');

        $this->assertStringNotContainsString('outer', $stripped);
        $this->assertStringNotContainsString('inner', $stripped);
        $this->assertStringNotContainsString('still secret', $stripped);
        $this->assertStringContainsString('shown', $stripped);
    }

    public function test_strip_leaves_no_stray_secret_tokens_behind(): void
    {
        $stripped = Secrets::strip('A {{secret}}x{{/}} B {{/}} C');

        $this->assertStringNotContainsString('{{secret}}', $stripped);
        $this->assertStringNotContainsString('{{/}}', $stripped);
        $this->assertStringContainsString('A', $stripped);
        $this->assertStringContainsString('C', $stripped);
    }

    public function test_strip_hides_adversarial_content_inside_a_secret(): void
    {
        $nasty = "<script>alert('x')</script> 𝕤𝕖𝕔𝕣𝕖𝕥 ' OR '1'='1 -- secret-code-99";
        $stripped = Secrets::strip("Public lead. {{secret}}{$nasty}{{/}} Public tail.");

        $this->assertStringNotContainsString('secret-code-99', $stripped);
        $this->assertStringNotContainsString('<script>', $stripped);
        $this->assertStringContainsString('Public lead.', $stripped);
        $this->assertStringContainsString('Public tail.', $stripped);
    }

    public function test_strip_preserves_multibyte_text_outside_secrets(): void
    {
        $stripped = Secrets::strip('Café ☕ 龍 {{secret}}hidden{{/}} déjà vu');

        $this->assertStringContainsString('Café ☕ 龍', $stripped);
        $this->assertStringContainsString('déjà vu', $stripped);
        $this->assertStringNotContainsString('hidden', $stripped);
    }

    public function test_count_reports_well_formed_secret_blocks(): void
    {
        $this->assertSame(2, Secrets::count('{{secret}}a{{/}} mid {{secret}}b{{/}}'));
        $this->assertSame(0, Secrets::count('no secrets here'));
    }

    public function test_reveal_unwraps_only_the_indexed_block(): void
    {
        $revealed = Secrets::reveal('{{secret}}first{{/}} {{secret}}second{{/}}', 1);

        $this->assertStringContainsString('second', $revealed);
        $this->assertStringNotContainsString('{{secret}}second{{/}}', $revealed);
        $this->assertStringContainsString('{{secret}}first{{/}}', $revealed);
    }
}
