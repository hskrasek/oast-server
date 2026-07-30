<?php

declare(strict_types=1);

namespace App\Tokens;

final class TokenAbilities
{
    /** @return list<string> */
    public static function all(): array
    {
        return ['review:create', 'review:read', 'review:follow'];
    }
}
