<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\EmailCampaign;
use App\Models\EmailSubscriber;
use App\Models\EmailTemplate;
use App\Services\Mail\EmailSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendCampaignEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        private readonly EmailCampaign $campaign,
        private readonly int $subscriberId,
    ) {}

    public function handle(EmailSender $sender): void
    {
        $subscriber = EmailSubscriber::find($this->subscriberId);

        if ($subscriber === null || ! $subscriber->subscribed) {
            return;
        }

        $template = EmailTemplate::find($this->campaign->email_template_id);

        if ($template === null || blank($template->html)) {
            return;
        }

        $variables = $sender->variablesFor($subscriber);

        $sender->sendTemplate(
            template: $template,
            recipientEmail: $subscriber->email,
            recipientName: filled($subscriber->name) ? (string) $subscriber->name : $subscriber->email,
            variables: $variables,
            campaign: $this->campaign,
            subscriber: $subscriber,
        );
    }
}
