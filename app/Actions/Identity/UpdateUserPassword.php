<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Identity\PasswordRules;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

final class UpdateUserPassword implements UpdatesUserPasswords
{
    /** @param array<string, string> $input */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'current_password:web'],
            'password' => PasswordRules::confirmed(),
        ])->validate();

        $user->forceFill(['password' => Hash::make($input['password'])])->save();
    }
}
