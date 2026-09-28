<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TemplatedEmail extends Mailable
{
    public function __construct(
        private readonly string $subjectLine,
        private readonly string $body,
        private readonly string $fromAddress,
        private readonly string $fromName,
        private readonly ?string $replyToAddress,
        private readonly ?string $replyToName,
    ) {}

    public function envelope(): Envelope
    {
        $replyTo = filled($this->replyToAddress)
            ? [new Address($this->replyToAddress, $this->replyToName ?? '')]
            : [];

        return new Envelope(
            from: new Address($this->fromAddress, $this->fromName),
            replyTo: $replyTo,
            subject: $this->subjectLine,
        );
    }

    public function content(): Content
    {
        return new Content(htmlString: $this->body);
    }
}
