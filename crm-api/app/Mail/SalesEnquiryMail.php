<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SalesEnquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly array $enquiry) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('mail.from.name'),
            ),
            replyTo: [new Address($this->enquiry['email'])],
            subject: 'Growneq CRM sales enquiry — '.$this->enquiry['company'],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'emails.sales-enquiry');
    }
}
