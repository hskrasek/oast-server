<?php

declare(strict_types=1);

namespace App\Identity;

final class CanonicalEmail
{
    public static function from(string $email): string
    {
        return mb_strtolower(mb_trim($email));
    }
}
