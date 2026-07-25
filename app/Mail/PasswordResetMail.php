<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * Create a new message instance.
     */
    public function __construct(public string $token, public string $email) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Reset your Fabli password',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        $domain = env('APP_ENV') == 'local' ? 'http://127.0.0.1:5173' : 'https://playfabli.com';
        $resetUrl = $domain.'/user/reset-password?token='.$this->token;

        return new Content(
            htmlString: '<h1>Reset your password</h1><p>Click <a href="'.$resetUrl.'">this link</a> to reset your Fabli password.</p>'
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
