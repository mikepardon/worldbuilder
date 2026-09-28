<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Mail\TemplatedEmail;
use App\Models\EmailCampaign;
use App\Models\EmailMessage;
use App\Models\EmailSubscriber;
use App\Models\EmailTemplate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

class EmailSender
{
    public function sendTemplate(
        EmailTemplate $template,
        string $recipientEmail,
        string $recipientName,
        array $variables,
        ?EmailCampaign $campaign = null,
        ?EmailSubscriber $subscriber = null,
    ): EmailMessage {
        $subject = $this->substitute((string) $template->subject, $variables, escape: false);
        $html = $this->substitute((string) $template->html, $variables, escape: true);
        $html = $this->tagCampaignLinks($html, $campaign);

        $fromAddress = filled($template->from_mailbox) ? (string) $template->from_mailbox : (string) config('mail.from.address');
        $fromName = filled($template->from_name) ? (string) $template->from_name : (string) config('mail.from.name');

        $mailable = new TemplatedEmail(
            subjectLine: $subject,
            body: $html,
            fromAddress: $fromAddress,
            fromName: $fromName,
            replyToAddress: filled($template->reply_to_mailbox) ? (string) $template->reply_to_mailbox : null,
            replyToName: filled($template->reply_to_name) ? (string) $template->reply_to_name : null,
        );

        Mail::to($recipientEmail, $recipientName)->send($mailable);

        return EmailMessage::create([
            'email_subscriber_id' => $subscriber?->id,
            'email_campaign_id' => $campaign?->id,
            'recipient_email' => $recipientEmail,
            'template_reference' => $template->reference,
            'subject' => $subject,
            'domain' => (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: ''),
            'status' => 'sent',
            'sent_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    public function variablesFor(EmailSubscriber $subscriber): array
    {
        $unsubscribeUrl = URL::signedRoute('unsubscribe', ['email' => $subscriber->email]);

        return array_merge([
            'name' => filled($subscriber->name) ? (string) $subscriber->name : $subscriber->email,
            'email' => $subscriber->email,
            'unsubscribe_url' => $unsubscribeUrl,
        ], is_array($subscriber->variables) ? $subscriber->variables : []);
    }

    /** @return array<string, string> */
    public function sampleVariables(): array
    {
        return [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'unsubscribe_url' => '#unsubscribe',
        ];
    }

    private function tagCampaignLinks(string $html, ?EmailCampaign $campaign): string
    {
        if ($campaign === null) {
            return $html;
        }

        $campaignId = $campaign->id;

        return (string) preg_replace_callback(
            '/href=(["\'])(https?:\/\/[^"\']+)\1/i',
            static function (array $matches) use ($campaignId): string {
                $quote = $matches[1];
                $url = $matches[2];

                if (str_contains($url, 'unsubscribe')) {
                    return "href={$quote}{$url}{$quote}";
                }

                $separator = str_contains($url, '?') ? '&' : '?';
                $utm = http_build_query([
                    'utm_source' => 'email',
                    'utm_medium' => 'campaign',
                    'utm_campaign' => (string) $campaignId,
                ]);

                return "href={$quote}{$url}{$separator}{$utm}{$quote}";
            },
            $html,
        );
    }

    public function substitute(string $content, array $variables, bool $escape): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            static function (array $matches) use ($variables, $escape): string {
                $value = (string) ($variables[$matches[1]] ?? '');
                return $escape ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $content,
        );
    }
}
