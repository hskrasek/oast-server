<?php

declare(strict_types=1);

namespace App\Organizations;

use App\Identity\CanonicalEmail;
use App\Identity\RegistrationData;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class InvitationAcceptanceService
{
    public function find(string $plain): ?OrganizationInvitation
    {
        $candidate = OrganizationInvitation::query()->where('token_hash', hash('sha256', $plain))->first();

        return $candidate instanceof OrganizationInvitation && hash_equals($candidate->token_hash, hash('sha256', $plain)) ? $candidate : null;
    }

    public function accept(OrganizationInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user): void {
            $locked = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            OrganizationMembership::query()->where('user_id', $user->id)->lockForUpdate()->get();
            if (! $locked->available() || ! hash_equals($locked->email, CanonicalEmail::from($user->email))
                || OrganizationMembership::query()->where('user_id', $user->id)->exists()) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
            }

            $locked->organization()->firstOrFail()->memberships()->create(['user_id' => $user->id, 'role' => $locked->role]);
            $locked->update(['accepted_at' => now()]);
        }, 3);
    }

    public function registerAndAccept(OrganizationInvitation $invitation, RegistrationData $data): User
    {
        return DB::transaction(function () use ($invitation, $data): User {
            if (! hash_equals($invitation->email, CanonicalEmail::from($data->email))) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
            }

            $user = User::query()->create(['name' => $data->name, 'email' => CanonicalEmail::from($data->email), 'password' => Hash::make($data->password)]);
            $this->accept($invitation, $user);

            return $user;
        }, 3);
    }
}
