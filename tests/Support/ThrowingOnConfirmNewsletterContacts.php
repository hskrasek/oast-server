<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\Newsletter\NewsletterContacts;
use Aws\Command;
use Aws\SesV2\Exception\SesV2Exception;

/**
 * A NewsletterContacts double whose confirm() throws a real SesV2Exception.
 *
 * NewsletterContacts is a plain interface, so a Mockery mock of it can't be
 * made to throw an SDK exception type realistically — construct the real
 * exception the same way the SES unit tests do (via Aws\Command + a 'code').
 */
final class ThrowingOnConfirmNewsletterContacts implements NewsletterContacts
{
    public function __construct(private readonly string $awsErrorCode) {}

    public function create(string $email): void {}

    public function confirm(string $email): void
    {
        throw new SesV2Exception('ses failure', new Command('UpdateContact'), ['code' => $this->awsErrorCode]);
    }
}
