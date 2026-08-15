<?php

declare(strict_types=1);

namespace App\Services\Transcription;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Transcribes a stored recording with Deepgram's pre-recorded API. Rather than stream a multi-gigabyte
 * file through PHP, we hand Deepgram a short-lived presigned URL to the object in S3 and let it fetch and
 * transcribe directly. Speaker diarisation and smart formatting are on so the transcript reads well and
 * the downstream analysis can attribute lines.
 *
 * Runs either synchronously ({@see transcribe()}, blocking until the transcript comes back) or, when a
 * public callback URL is available, asynchronously ({@see submit()} + {@see transcriptFromCallback()}):
 * Deepgram returns immediately and POSTs the finished transcript to the callback URL.
 */
class DeepgramTranscriber implements CallbackTranscriber
{
    private const ENDPOINT = 'https://api.deepgram.com/v1/listen';

    #[\Override]
    public function transcribe(string $disk, string $path): string
    {
        $response = Http::withHeaders($this->authHeaders())
            ->timeout(1800)
            ->post($this->endpoint(), ['url' => $this->audioUrl($disk, $path)]);

        if (! $response->successful()) {
            throw new RuntimeException('Transcription failed ('.$response->status().').');
        }

        return $this->transcriptFromCallback($response->json() ?? []);
    }

    #[\Override]
    public function submit(string $disk, string $path, string $callbackUrl): string
    {
        $response = Http::withHeaders($this->authHeaders())
            ->timeout(60)
            ->post($this->endpoint(['callback' => $callbackUrl]), ['url' => $this->audioUrl($disk, $path)]);

        if (! $response->successful()) {
            throw new RuntimeException('Could not queue transcription with Deepgram ('.$response->status().').');
        }

        $requestId = (string) ($response->json('request_id') ?? '');
        if ($requestId === '') {
            throw new RuntimeException('Deepgram accepted the job but returned no request id.');
        }

        return $requestId;
    }

    #[\Override]
    public function transcriptFromCallback(array $payload): string
    {
        $alternative = data_get($payload, 'results.channels.0.alternatives.0', []);

        // The paragraph-formatted transcript reads far better than the flat one; fall back if absent.
        $transcript = (string) (data_get($alternative, 'paragraphs.transcript') ?? data_get($alternative, 'transcript', ''));

        if (trim($transcript) === '') {
            throw new RuntimeException('Transcription came back empty — the audio may be silent or unreadable.');
        }

        return $transcript;
    }

    #[\Override]
    public function durationSecondsFromCallback(array $payload): int
    {
        return (int) round((float) data_get($payload, 'metadata.duration', 0));
    }

    /** @return array<string, string> */
    private function authHeaders(): array
    {
        $key = config('services.deepgram.key');
        if (blank($key)) {
            throw new RuntimeException('Deepgram is not configured on this server yet.');
        }

        return ['Authorization' => "Token {$key}"];
    }

    /** A presigned URL Deepgram can fetch the audio from directly; long-lived enough for a big file. */
    private function audioUrl(string $disk, string $path): string
    {
        return Storage::disk($disk)->temporaryUrl($path, now()->addHours(3));
    }

    /**
     * @param  array<string, string>  $extra
     */
    private function endpoint(array $extra = []): string
    {
        return self::ENDPOINT.'?'.http_build_query([
            'model' => config('services.deepgram.model', 'nova-2'),
            'smart_format' => 'true',
            'punctuate' => 'true',
            'paragraphs' => 'true',
            'diarize' => 'true',
            ...$extra,
        ]);
    }
}
