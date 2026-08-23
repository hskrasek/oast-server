<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, string> $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, ['password' => PasswordRules::confirmed()])->validate();
        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }
}
