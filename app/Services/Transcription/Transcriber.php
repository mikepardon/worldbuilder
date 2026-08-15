<?php

declare(strict_types=1);

namespace App\Services\Transcription;

/**
 * Speech-to-text for a stored audio file. The implementation (Whisper, Deepgram, …) is swapped behind
 * this interface, so the pipeline and its tests never depend on a specific provider.
 */
interface Transcriber
{
    /** Transcribe the audio at $path on the given filesystem $disk and return the plain-text transcript. */
    public function transcribe(string $disk, string $path): string;
}
