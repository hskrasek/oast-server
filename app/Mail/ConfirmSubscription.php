<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

final class ConfirmSubscription extends Mailable
{
    public function __construct(public readonly string $email) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Confirm your oast.sh subscription');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.confirm-subscription', with: [
            'confirmUrl' => URL::signedRoute('subscribe.confirm', ['email' => $this->email]),
        ]);
    }
}
