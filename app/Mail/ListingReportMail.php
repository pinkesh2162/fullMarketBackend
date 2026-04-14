<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ListingReportMail extends Mailable
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
        $listingId = $this->payload['listing_id'] ?? '';

        return new Envelope(
            subject: "[{$this->payload['app_name']}] Listing report #{$listingId}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.listing_report',
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
