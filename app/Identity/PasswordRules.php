<?php

declare(strict_types=1);

namespace App\Identity;

use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** @return list<Rule|ValidationRule|string> */
    public static function base(): array
    {
        return ['required', 'string', Password::min(12)->uncompromised()];
    }

    /** @return list<Rule|ValidationRule|string> */
    public static function confirmed(): array
    {
        return [...self::base(), 'confirmed'];
    }
}
