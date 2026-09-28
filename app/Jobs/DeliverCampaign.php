<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailSubscriber;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DeliverCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(private readonly EmailCampaign $campaign) {}

    public function handle(): void
    {
        $this->campaign->update(['status' => 'sending']);

        $subscriberIds = EmailSubscriber::where('subscribed', true)->pluck('id');

        $this->campaign->update(['recipient_count' => $subscriberIds->count()]);

        foreach ($subscriberIds->chunk(200) as $chunk) {
            foreach ($chunk as $subscriberId) {
                dispatch(new SendCampaignEmail($this->campaign, $subscriberId));
            }
        }

        $this->campaign->update(['status' => 'sent', 'sent_at' => now()]);
    }
}
