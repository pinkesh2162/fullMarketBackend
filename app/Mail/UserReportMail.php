<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload
    ) {}

    public function envelope(): Envelope
    {
        $reportedId = $this->payload['reported_user_id'] ?? '';

        return new Envelope(
            subject: "[{$this->payload['app_name']}] User report — reported user #{$reportedId}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user_report',
        );
    }

    /**
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
