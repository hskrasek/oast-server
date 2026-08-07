<?php

declare(strict_types=1);

namespace App\Identity;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

final class PasswordRules
{
    /** @return list<ValidationRule|string> */
    public static function base(): array
    {
        $password = Password::min(12);
        if (config()->boolean('oast.password_breach_check', true)) {
            $password = $password->uncompromised();
        }

        /** @var list<ValidationRule|string> */
        $rules = ['required', 'string', $password];
        return $rules;
    }

    /** @return list<ValidationRule|string> */
    public static function confirmed(): array
    {
        /** @var list<ValidationRule|string> */
        $rules = [...self::base(), 'confirmed'];
        return $rules;
    }
}
