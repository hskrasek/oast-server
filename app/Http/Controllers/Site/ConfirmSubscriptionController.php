<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Newsletter\NewsletterContacts;
use Illuminate\Contracts\View\View;

final class ConfirmSubscriptionController
{
    public function __invoke(NewsletterContacts $contacts, string $email): View
    {
        $contacts->confirm($email);

        return view('site.confirmed', ['email' => $email]);
    }
}
