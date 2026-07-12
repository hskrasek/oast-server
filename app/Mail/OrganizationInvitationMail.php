<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

final class OrganizationInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $url) {}

    public function build(): self
    {
        return $this->subject('Organization invitation')->view('mail.organization-invitation');
    }
}
