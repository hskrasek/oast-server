<?php

declare(strict_types=1);

namespace App\Site\Newsletter;

interface NewsletterContacts
{
    public function create(string $email): void;

    public function confirm(string $email): void;
}
