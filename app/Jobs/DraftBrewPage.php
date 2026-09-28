<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiRequest;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Support\AiJson;
use App\Support\AiUsageContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs a Muse draft for a brew-style markdown page (documents and session write-ups) on the queue and
 * stores the result on its {@see AiRequest} for the browser to poll. The model returns the edit as
 * structured JSON — replace or append — so the editor writes it straight into the document instead of
 * dumping Markdown into the chat. Off the web request, the generation can run long enough to produce
 * several \page sections without hitting Cloudflare's ~100s gateway limit. When a reply is cut off by
 * the per-call output-token budget, the job asks the model to continue where it stopped and stitches
 * the chunks back into one JSON document, so a long draft is never capped at roughly one page.
 * Billed on success only.
 */
class DraftBrewPage implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Generous — a queue worker isn't behind the gateway, so it can wait out MAX_CALLS long calls. */
    public int $timeout = 900;

    /**
     * Per-call output budget, under the 8192-token output cap of the smallest selectable Claude
     * models. A draft that needs more is continued across further calls (see MAX_CALLS) rather than
     * arriving truncated.
     */
    private const MAX_TOKENS = 8000;

    /** HTTP timeout per model call; MAX_CALLS of these stay below this job's own timeout so it fails cleanly, not killed. */
    private const HTTP_TIMEOUT = 200;

    /** Most model calls one draft may spend: the first plus up to three continuations (~4 × 8000 output tokens). */
    private const MAX_CALLS = 4;

    /** Sent when a reply hits the output budget mid-JSON, so the next call resumes the same document. */
    private const CONTINUE_PROMPT = 'Your previous message hit the output limit and stopped mid-JSON.'
        .' Continue from the exact character you stopped at: output ONLY the remaining characters of'
        .' that same JSON object — no repetition, no commentary, no code fences.';

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function __construct(
        public AiRequest $aiRequest,
        public int $userId,
        public int $cost,
        public string $prompt,
        public string $content,
        public array $history,
        public string $title,
        public string $kindLabel,
        public string $worldName,
        public ?string $worldSetting,
        public AiUsageContext $usage,
    ) {}

    public function handle(AnthropicClient $ai): void
    {
        $aiRequest = $this->aiRequest->fresh();
        if ($aiRequest === null || $aiRequest->status !== 'pending') {
            return;
        }

        $messages = [];
        foreach ($this->history as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $this->prompt];

        $raw = '';
        $truncated = false;
        try {
            // Each call gets a fresh output budget; a reply cut off mid-JSON is fed back as an
            // assistant turn and the model continues it, so the chunks concatenate into one document.
            for ($call = 1; $call <= self::MAX_CALLS; $call++) {
                $reply = $ai->chatReply($this->systemPrompt(), $messages, self::MAX_TOKENS, $this->usage, self::HTTP_TIMEOUT);
                $raw .= $reply->text;
                $truncated = $reply->truncated;
                if (! $truncated) {
                    break;
                }
                $messages[] = ['role' => 'assistant', 'content' => $reply->text];
                $messages[] = ['role' => 'user', 'content' => self::CONTINUE_PROMPT];
            }
        } catch (Throwable $error) {
            // The catch swallows the throwable (so the job completes and `failed()` never fires), which
            // means without an explicit report the provider failure never reaches Sentry.
            report($error);

            $message = $error->getMessage();
            $aiRequest->markFailed($message !== '' ? $message : 'The AI request failed. Please try again.');

            return;
        }

        if ($truncated) {
            $aiRequest->markFailed('That draft was too long to finish in one go — ask for it a section at a time.');

            return;
        }

        $parsed = AiJson::object($raw);
        if ($parsed === null) {
            $aiRequest->markFailed('The AI returned an unexpected response. Please try again.');

            return;
        }

        // Bill only a successful generation, against the user's credit balance.
        $user = User::find($this->userId);
        $user?->spendAiCredits($this->cost);

        $aiRequest->markDone([
            'reply' => is_string($parsed['reply'] ?? null) ? $parsed['reply'] : '',
            'mode' => in_array($parsed['mode'] ?? null, ['append', 'replace'], true) ? $parsed['mode'] : 'replace',
            'content' => is_string($parsed['content'] ?? null) ? $parsed['content'] : '',
            'ai' => ['creditsRemaining' => $user?->aiCreditsRemaining() ?? 0],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->aiRequest->fresh()?->markFailed('The AI request failed. Please try again.');
    }

    private function systemPrompt(): string
    {
        return "You are a worldbuilding assistant editing a \"{$this->kindLabel}\" page titled \"{$this->title}\" for the tabletop RPG campaign \"{$this->worldName}\"."
            .($this->worldSetting !== null && $this->worldSetting !== '' ? " Setting: {$this->worldSetting}." : '')
            .' The page is Homebrewery-flavoured Markdown: \page starts a new sheet, \column breaks a column, {{monster,frame ...}} / {{note ...}} / {{descriptive ...}} open styled blocks closed by }}, and "**Label** :: value" writes stat lines. Preserve that syntax exactly in anything you keep or write.'
            ." Work like a careful collaborator: do exactly what the GM asks — no more, no less.\n\n"
            ."Choose the ONE mode that fits the GM's message:\n"
            ."1. CLARIFY — if the request is ambiguous, or you need information you don't have to do it well, ask a short question instead of guessing. Put the question in \"reply\" and leave \"content\" empty.\n"
            ."2. APPEND — if the GM asks to add new material (a stat block, a new section, more pages), produce ONLY the new Markdown; it will be added to the end of the page. Start it with \\page on its own line when it should begin a fresh sheet. You may include several \\page sections in one draft.\n"
            ."3. REPLACE — if the GM asks to change, restructure, or rewrite what is already there, produce the COMPLETE new page body with the edit applied. Reproduce everything you are not changing EXACTLY as it is now — never return a fragment in this mode.\n\n"
            .'Write the FULL material the request calls for — never shorten, summarise, or stop early to fit an'
            ." output limit. If you run out of space you will be asked to continue, so just keep writing.\n\n"
            ."Respond with a SINGLE JSON object and nothing else — no prose outside it, no code fences:\n"
            ."{\"mode\": \"clarify\"|\"append\"|\"replace\", \"content\": \"...\", \"reply\": \"...\"}\n"
            ."- \"content\": the Markdown described by the mode. It is written straight into the page for the GM. Inside the JSON string, escape every backslash (write \\\\page, \\\\column) and use \\n for line breaks.\n"
            .'- "reply": the chat side of your answer — one or two short sentences saying what you added or'
            .' changed, then ONE short follow-up question that moves the page forward (an enhancement to'
            .' offer, a gap to fill, or a choice the GM should make). The chat is for conversation only:'
            ." NEVER put the drafted Markdown in \"reply\".\n\n"
            ."The current page body is:\n\n{$this->content}";
    }
}
