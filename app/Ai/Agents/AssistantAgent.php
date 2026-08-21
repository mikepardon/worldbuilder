<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * A general-purpose {@see \Laravel\Ai} agent for one-shot and short multi-turn assistant calls: dynamic
 * instructions, an optional prior-message history, and a per-call output-token budget. The budget is exposed
 * via the maxTokens() method (which the SDK resolves per instance) rather than the #[MaxTokens] attribute, so
 * one reusable agent can serve callers with different budgets. Provider, model and timeout are passed to
 * prompt() by the caller. Contrast with {@see RecapAgent}, a dedicated agent for the recap-analysis feature.
 */
final class AssistantAgent implements Agent, Conversational, HasTools
{
    use Promptable;

    /**
     * @param  list<array{role: string, content: string}>  $history  Prior conversation turns (no final user turn).
     */
    public function __construct(
        private string $systemInstructions,
        private array $history = [],
        private int $maxOutputTokens = 1500,
    ) {}

    public function instructions(): Stringable|string
    {
        return $this->systemInstructions;
    }

    /**
     * The SDK's conversation contract expects Message objects, so map the raw {role, content} history.
     *
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return array_map(fn (array $message): Message => Message::tryFrom($message), $this->history);
    }

    /**
     * @return array<int, mixed>
     */
    public function tools(): iterable
    {
        return [];
    }

    public function maxTokens(): int
    {
        return $this->maxOutputTokens;
    }
}
