<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AiJson;
use PHPUnit\Framework\TestCase;

class AiJsonTest extends TestCase
{
    public function test_it_decodes_an_object_wrapped_in_prose(): void
    {
        $decoded = AiJson::object('Sure! ```json {"recap_full": "The party fled."} ``` Hope that helps.');

        $this->assertSame(['recap_full' => 'The party fled.'], $decoded);
    }

    public function test_it_salvages_a_reply_cut_off_mid_object(): void
    {
        // What the model sends when it runs into its max_tokens cap part-way through the analysis.
        $truncated = '{"recap_full": "The party fled.", "moments": [{"type": "story", "description": "A door opened."},'
            .' {"type": "combat", "descrip';

        $decoded = AiJson::object($truncated);

        $this->assertSame('The party fled.', $decoded['recap_full']);
        // The whole moment survives; the one that was still being written keeps only its finished fields
        // (RecapAnalyzer drops it, since a moment with no description is discarded).
        $this->assertSame([
            ['type' => 'story', 'description' => 'A door opened.'],
            ['type' => 'combat'],
        ], $decoded['moments']);
    }

    public function test_it_salvages_a_cut_that_lands_inside_a_string(): void
    {
        // The cut falls mid-sentence, and that sentence contains braces and an escaped quote — neither may
        // be mistaken for structure when trimming back to the last whole field.
        $truncated = '{"recap_short": "They won.", "recap_full": "The \"Baron\" said {this} and then';

        $this->assertSame(['recap_short' => 'They won.'], AiJson::object($truncated));
    }

    public function test_it_returns_null_when_nothing_complete_arrived(): void
    {
        $this->assertNull(AiJson::object('{"recap_full": "The party fl'));
        $this->assertNull(AiJson::object('I could not analyse that transcript.'));
    }
}
