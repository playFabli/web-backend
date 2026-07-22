<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class EmailVerificationMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public string $code)
    {
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Verify your Fabli account',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $domain = env("APP_ENV") == "local" ? "http://127.0.0.1:8000/api" : "https://backend.playfabli.com/api";
        return new Content(
            htmlString: '<h1>Verify your Fabli account</h1><p>Click <a href="' . $domain . '/email-verification?code=' . $this->code .'">this link</a> to verify your Fabli account.</p>'
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
