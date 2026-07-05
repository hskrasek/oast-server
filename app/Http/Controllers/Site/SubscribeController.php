<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Http\Requests\SubscribeRequest;
use App\Mail\ConfirmSubscription;
use App\Site\Newsletter\NewsletterContacts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;

final class SubscribeController
{
    public function __invoke(SubscribeRequest $request, NewsletterContacts $contacts): RedirectResponse
    {
        if (!$request->isSpam()) {
            $email = $request->string('email')->value();
            $contacts->create($email);
            Mail::to($email)->send(new ConfirmSubscription($email));
        }

        return redirect('/')->with('status', 'Check your inbox to confirm.');
    }
}
