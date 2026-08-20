<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AiRequest;
use App\Models\CampaignCompendiumItem;
use App\Models\User;
use App\Services\CompendiumDrafter;
use App\Support\AiUsageContext;
use App\Support\Statblock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runs a compendium Muse draft on the queue and stores the result on its {@see AiRequest} for the browser
 * to poll. Moving the model call off the web request means a slow generation can take as long as it needs
 * (up to this job's timeout) without ever hitting Cloudflare's ~100s gateway limit. Billed on success only.
 */
class DraftCompendiumEntry implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    /** Generous — a queue worker isn't behind the gateway, so it can wait out a long generation. */
    public int $timeout = 300;

    /**
     * @param  list<array{role: string, content: string}>  $history
     */
    public function __construct(
        public AiRequest $aiRequest,
        public CampaignCompendiumItem $item,
        public int $userId,
        public int $cost,
        public string $prompt,
        public string $document,
        public array $history,
    ) {}

    public function handle(CompendiumDrafter $drafter): void
    {
        $aiRequest = $this->aiRequest->fresh();
        if ($aiRequest === null || $aiRequest->status !== 'pending') {
            return;
        }

        $item = $this->item;
        $world = $item->world;
        $currentBlock = (array) (data_get($item->fields, 'block') ?? Statblock::empty());
        $currentFields = (array) ($item->fields ?? []);

        try {
            $result = $drafter->draft(
                $item->item_type, $item->name, $world->name, $currentBlock, $currentFields, $this->document,
                $this->prompt, $this->history,
                new AiUsageContext('compendium_draft', $world->id, $this->userId, $item->item_type),
            );
        } catch (Throwable $error) {
            // The catch swallows the throwable (so the job completes and `failed()` never fires), which
            // means without an explicit report the drafter/provider failure never reaches Sentry.
            report($error);

            $aiRequest->markFailed($error->getMessage());

            return;
        }

        if ($result === null) {
            $aiRequest->markFailed('The AI returned an unexpected response. Please try again.');

            return;
        }

        // Bill only a successful generation, against the user's credit balance.
        $user = User::find($this->userId);
        $user?->spendAiCredits($this->cost);

        $aiRequest->markDone([
            ...$result,
            'ai' => ['creditsRemaining' => $user?->aiCreditsRemaining() ?? 0],
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->aiRequest->fresh()?->markFailed('The AI request failed. Please try again.');
    }
}
