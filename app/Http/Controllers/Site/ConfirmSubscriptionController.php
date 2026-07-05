<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Site\Newsletter\NewsletterContacts;
use Aws\SesV2\Exception\SesV2Exception;
use Illuminate\Contracts\View\View;

final class ConfirmSubscriptionController
{
    public function __invoke(NewsletterContacts $contacts, string $email): View
    {
        try {
            $contacts->confirm($email);
        } catch (SesV2Exception $sesV2Exception) {
            if ($sesV2Exception->getAwsErrorCode() !== 'NotFoundException') {
                throw $sesV2Exception;
            }

            // The contact never existed (e.g. re-confirming an old link, or
            // the create() call failed silently) — confirmation is idempotent
            // either way, so report and render as if it succeeded.
            report($sesV2Exception);
        }

        return view('site.confirmed', ['email' => $email]);
    }
}
