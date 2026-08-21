<?php

declare(strict_types=1);

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\MaxTokens;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;
use Stringable;

/**
 * The reference `laravel/ai` agent: a single-shot completion with a fixed output-token budget and dynamic,
 * per-call instructions. Turns a whole-session transcript into the recap analysis (see {@see \App\Services\RecapAnalyzer}).
 *
 * The output budget must be a class-level attribute — the anonymous `agent()` helper cannot set it — so a
 * feature needing a specific budget gets its own agent class. A multi-hour session's full recap plus its
 * entities, moments and outline runs to ~20k tokens, so 24k gives headroom without inviting runaway output.
 * Provider, model and timeout stay dynamic and are passed to `prompt()` by the caller.
 */
#[MaxTokens(24000)]
final class RecapAgent implements Agent
{
    use Promptable;

    public function __construct(private string $systemInstructions) {}

    public function instructions(): Stringable|string
    {
        return $this->systemInstructions;
    }
}
