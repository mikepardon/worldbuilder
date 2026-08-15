<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $campaignName,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "You're invited to {$this->campaignName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.invite');
    }
}
