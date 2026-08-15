<?php

declare(strict_types=1);

namespace App\Services\Transcription;

/**
 * A {@see Transcriber} that can also run asynchronously: hand it the audio and a callback URL, and the
 * provider delivers the finished transcript to that URL later rather than making us hold a long HTTP call
 * open. The webhook that receives the delivery uses {@see transcriptFromCallback()} to read the transcript
 * back out of the provider's payload.
 */
interface CallbackTranscriber extends Transcriber
{
    /**
     * Kick off async transcription of the audio at $path on $disk, asking the provider to POST the result
     * to $callbackUrl when done. Returns the provider's request id for correlation.
     */
    public function submit(string $disk, string $path, string $callbackUrl): string;

    /**
     * Pull the plain-text transcript out of the payload the provider POSTed to the callback URL.
     *
     * @param  array<string, mixed>  $payload
     */
    public function transcriptFromCallback(array $payload): string;

    /**
     * The audio's duration in whole seconds, read from the provider's callback payload (0 if unknown).
     *
     * @param  array<string, mixed>  $payload
     */
    public function durationSecondsFromCallback(array $payload): int;
}
