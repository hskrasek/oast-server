<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Identity\CanonicalEmail;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /** @param array<string, string> $input */
    public function update(User $user, array $input): void
    {
        $email = CanonicalEmail::from($input['email']);
        Validator::make([...$input, 'email' => $email], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email,' . $user->id],
        ])->validate();

        $changed = ! hash_equals($user->email, $email);
        $user->forceFill([
            'name' => $input['name'],
            'email' => $email,
            'email_verified_at' => $changed ? null : $user->email_verified_at,
        ])->save();

        if ($changed && config()->boolean('oast.enforce_email_verification')) {
            $user->sendEmailVerificationNotification();
        }
    }
}
