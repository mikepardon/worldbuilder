<?php

namespace App\Services;

use App\Support\AiModels;
use App\Support\AiUsage;
use App\Support\AiUsageContext;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Thin client over the Anthropic Messages API. */
class AnthropicClient
{
    protected const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    /**
     * The longest a synchronous web request may wait on the model. Cloudflare returns a 504 once the origin
     * takes ~100s, so a web call must fail cleanly well before then rather than hang into a gateway timeout.
     * Kept conservative to leave headroom for request overhead (auth, DB, response) under that ceiling.
     * Queue workers (CLI) are exempt — long batch generation there never passes through Cloudflare.
     */
    protected const WEB_MAX_TIMEOUT = 75;

    public function configured(): bool
    {
        return ! empty(config('services.anthropic.key'));
    }

    /** Single-turn helper. */
    public function message(string $system, string $user, int $maxTokens = 1200, ?AiUsageContext $usage = null, int $timeout = 60): string
    {
        return $this->chat($system, [['role' => 'user', 'content' => $user]], $maxTokens, $usage, $timeout);
    }

    /**
     * Multi-turn chat: a system prompt + an ordered [{role,content}] message list. Returns text. When a
     * usage context is given, the call is logged (with its token cost) as an AiUsageEvent.
     *
     * $timeout is the HTTP timeout in seconds. The default suits interactive web calls; long batch work on
     * a queue (a big transcript, many tokens) should raise it — a non-streaming reply arrives all at once,
     * so too short a timeout aborts with "0 bytes received" while the model is still generating. Keep it
     * below the dispatching job's own timeout so the client fails cleanly rather than the worker killing it.
     */
    public function chat(string $system, array $messages, int $maxTokens = 1500, ?AiUsageContext $usage = null, int $timeout = 60): string
    {
        $key = config('services.anthropic.key');
        if (blank($key)) {
            throw new RuntimeException("AI isn't set up on this server yet.");
        }

        // A generation (e.g. several stat blocks at once) can legitimately run for up to the HTTP timeout.
        // The web SAPI's default max_execution_time is 30s, so lift it past the timeout — otherwise PHP
        // kills the process mid-request as a fatal error before the client's own timeout can surface as a
        // catchable exception. Only do this under a web SAPI: in a queue worker (CLI) max_execution_time is
        // already unlimited, and setting it here would cap the long-lived worker process and crash it.
        //
        // On the web the timeout is also capped below Cloudflare's ~100s origin limit, so a slow generation
        // aborts as a catchable timeout (surfaced as a friendly error below) instead of hanging into a 504.
        if (PHP_SAPI !== 'cli') {
            $timeout = min($timeout, self::WEB_MAX_TIMEOUT);
            set_time_limit($timeout + 30);
        }

        // The model is an admin setting, resolved per world (or the global default) — not from env.
        $model = AiModels::forWorld($usage?->worldId);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout($timeout)->post(self::ENDPOINT, [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => array_values($messages),
            ]);
        } catch (ConnectionException $exception) {
            // The request timed out (or the connection dropped) before the model replied. Surface a clean,
            // retryable message rather than letting the request hang into a Cloudflare 504.
            throw new RuntimeException('The AI took too long to respond — please try again.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException('The AI service returned an error ('.$response->status().').');
        }

        if ($usage !== null) {
            // Usage logging must never break the AI feature itself, so record failures are reported and
            // swallowed rather than propagated to the caller.
            try {
                AiUsage::record(
                    $usage,
                    (string) ($response->json('model') ?? $model),
                    (int) $response->json('usage.input_tokens', 0),
                    (int) $response->json('usage.output_tokens', 0),
                );
            } catch (Throwable $error) {
                report($error);
            }
        }

        return collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");
    }
}
