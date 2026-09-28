<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\DeliverCampaign;
use App\Models\EmailCampaign;
use Illuminate\Console\Command;

class DispatchDueCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-due';
    protected $description = 'Dispatch any scheduled email campaigns that are due to send.';

    public function handle(): void
    {
        $campaigns = EmailCampaign::where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($campaigns as $campaign) {
            $campaign->update(['status' => 'queued']);
            dispatch(new DeliverCampaign($campaign));
            $this->info("Dispatched campaign {$campaign->id}: {$campaign->subject}");
        }

        if ($campaigns->isEmpty()) {
            $this->info('No due campaigns.');
        }
    }
}
