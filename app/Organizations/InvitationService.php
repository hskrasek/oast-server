<?php

declare(strict_types=1);

namespace App\Organizations;

use App\Enums\OrganizationRole;
use App\Identity\CanonicalEmail;
use App\Mail\OrganizationInvitationMail;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

final class InvitationService
{
    /** @return array{invitation: OrganizationInvitation, url: string} */
    public function createOrReplace(Organization $organization, User $inviter, string $email): array
    {
        $email = CanonicalEmail::from($email);
        [$invitation, $plain] = DB::transaction(function () use ($organization, $inviter, $email): array {
            OrganizationInvitation::query()->where('organization_id', $organization->id)->where('email', $email)
                ->whereNull('accepted_at')->whereNull('revoked_at')->lockForUpdate()->update(['revoked_at' => now()]);
            $plain = bin2hex(random_bytes(32));
            $invitation = $organization->invitations()->create([
                'invited_by_user_id' => $inviter->id, 'email' => $email, 'role' => OrganizationRole::Member,
                'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addHours(config()->integer('oast.invitation_ttl_hours')),
            ]);

            return [$invitation, $plain];
        }, 3);
        $url = route('invitations.show', ['token' => $plain]);

        try {
            Mail::to($email)->send(new OrganizationInvitationMail($url));
        } catch (Throwable) {
        }

        return ['invitation' => $invitation, 'url' => $url];
    }

    public function revoke(OrganizationInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation): void {
            $locked = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            if ($locked->accepted_at !== null || $locked->revoked_at !== null) {
                throw ValidationException::withMessages(['invitation' => 'This invitation is not available.']);
            }

            $locked->update(['revoked_at' => now()]);
        }, 3);
    }
}
