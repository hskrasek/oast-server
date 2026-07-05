<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Site\Newsletter\NewsletterContacts;

final class FakeNewsletterContacts implements NewsletterContacts
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $confirmed = [];

    public function create(string $email): void
    {
        $this->created[] = $email;
    }

    public function confirm(string $email): void
    {
        $this->confirmed[] = $email;
    }
}
