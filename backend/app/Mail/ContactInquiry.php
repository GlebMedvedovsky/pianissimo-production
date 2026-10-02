<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Queue\SerializesModels;

final class ContactInquiry extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $visitorName,
        public readonly string $visitorEmail,
        public readonly string $inquiryMessage,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->visitorEmail, $this->visitorName)],
            subject: 'Website inquiry from '.$this->visitorName,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.contact-inquiry',
            with: [
                'visitorName' => $this->visitorName,
                'visitorEmail' => $this->visitorEmail,
                'inquiryMessage' => $this->inquiryMessage,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
