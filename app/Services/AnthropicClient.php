<?php

declare(strict_types=1);

namespace App\Services;

use App\Ai\Agents\AssistantAgent;
use App\Support\AiModels;
use App\Support\AiUsage;
use App\Support\AiUsageContext;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\ProviderConnectionException;
use RuntimeException;
use Throwable;

/**
 * Thin assistant over the Anthropic Messages API, now backed by the laravel/ai SDK (via {@see AssistantAgent}).
 * Keeps a single, stable entry point for the app's metered chat calls so token usage is logged in one place;
 * callers pass a system prompt, an ordered message list and a budget, and get plain text back.
 */
class AnthropicClient
{
    /**
     * The longest a synchronous web request may wait on the model. Cloudflare returns a 504 once the origin
     * takes ~100s, so a web call must fail cleanly well before then rather than hang into a gateway timeout.
     * Kept conservative to leave headroom for request overhead (auth, DB, response) under that ceiling.
     * Queue workers (CLI) are exempt — long batch generation there never passes through Cloudflare.
     */
    protected const WEB_MAX_TIMEOUT = 75;

    public function configured(): bool
    {
        return filled(config('services.anthropic.key'));
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
     * a queue (a big transcript, many tokens) should raise it. Keep it below the dispatching job's own
     * timeout so the client fails cleanly rather than the worker killing it.
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    public function chat(string $system, array $messages, int $maxTokens = 1500, ?AiUsageContext $usage = null, int $timeout = 60): string
    {
        if (! $this->configured()) {
            throw new RuntimeException("AI isn't set up on this server yet.");
        }

        // A generation (e.g. several stat blocks at once) can legitimately run for up to the HTTP timeout.
        // The web SAPI's default max_execution_time is 30s, so lift it past the timeout — otherwise PHP
        // kills the process mid-request as a fatal error before the client's own timeout can surface as a
        // catchable exception. Only under a web SAPI: a queue worker (CLI) already has unlimited time, and
        // capping it there would crash the long-lived worker. The timeout is also held below Cloudflare's
        // ~100s origin limit so a slow generation aborts as a catchable timeout instead of a 504.
        if (PHP_SAPI !== 'cli') {
            $timeout = min($timeout, self::WEB_MAX_TIMEOUT);
            set_time_limit($timeout + 30);
        }

        // The model is an admin setting, resolved per world (or the global default) — not from env.
        $model = AiModels::forWorld($usage?->worldId);

        // The trailing message is the current user turn (the prompt); anything before it is prior history.
        $history = $messages;
        $current = array_pop($history);
        $prompt = is_array($current) ? (string) ($current['content'] ?? '') : (string) $current;

        try {
            $response = (new AssistantAgent($system, array_values($history), $maxTokens))->prompt(
                $prompt,
                provider: 'anthropic',
                model: $model,
                timeout: $timeout,
            );
        } catch (ProviderConnectionException $exception) {
            // Timed out or the connection dropped before the model replied — surface a clean, retryable
            // message rather than letting the request hang into a Cloudflare 504.
            throw new RuntimeException('The AI took too long to respond — please try again.', previous: $exception);
        } catch (AiException $exception) {
            // Any other provider-side failure (overloaded, rate-limited, malformed response) — keep it
            // user-facing and retryable rather than leaking the SDK's internals.
            throw new RuntimeException('The AI service is temporarily unavailable — please try again.', previous: $exception);
        }

        if ($usage !== null) {
            // Usage logging must never break the AI feature itself, so record failures are reported and
            // swallowed rather than propagated to the caller. Price against the model the response reports.
            try {
                AiUsage::record(
                    $usage,
                    $response->meta->model ?: $model,
                    $response->usage->promptTokens,
                    $response->usage->completionTokens,
                );
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $response->text;
    }
}
