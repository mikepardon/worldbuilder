<?php

declare(strict_types=1);

namespace App\Services\Transcription;

/**
 * Placeholder transcriber for Phase 1: returns a fixed transcript rather than calling a real speech-to-
 * text provider. The upload → structure → import pipeline is fully wired against the {@see Transcriber}
 * interface, so swapping in a Whisper/Deepgram implementation is a one-line binding change with no other
 * code touched. Tests bind their own transcript through the container.
 */
class FakeTranscriber implements Transcriber
{
    #[\Override]
    public function transcribe(string $disk, string $path): string
    {
        return <<<'TRANSCRIPT'
        The party arrived at the drowned bell tower as the tide pulled back, revealing the muddy causeway.
        Maren the tide-priestess warned them the bell had not rung in a hundred years, and that whatever
        silenced it still waited below. Bram pried open the rusted grate while Sella kept watch. Inside,
        they found the Lantern of Low Water, a lamp that burns only underwater, and a ledger naming the
        Ashen Concord as the ones who scuttled the town. They agreed to return at the next low tide.
        TRANSCRIPT;
    }
}
