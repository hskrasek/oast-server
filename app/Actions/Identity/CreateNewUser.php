<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Identity\CanonicalEmail;
use App\Identity\PasswordRules;
use App\Identity\RegistrationData;
use App\Identity\RegistrationPolicy;
use App\Models\User;
use App\Organizations\InvitationAcceptanceService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final readonly class CreateNewUser implements CreatesNewUsers
{
    public function __construct(private RegistrationPolicy $policy, private InvitationAcceptanceService $invitations) {}

    /** @param array<string, string> $input */
    public function create(array $input): User
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => PasswordRules::confirmed(), 'invitation_token' => ['required', 'string', 'size:64'],
        ])->validate();
        $invitation = $this->invitations->find($input['invitation_token']);
        if (!$invitation instanceof \App\Models\OrganizationInvitation || ! $invitation->available()) {
            throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
        }

        $user = $this->policy->register(new RegistrationData($input['name'], CanonicalEmail::from($input['email']), $input['password']), $invitation);
        request()->session()->forget(['oast.invitation.token', 'url.intended']);

        return $user;
    }
}
