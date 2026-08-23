<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;

final class OrganizationInvitationPolicy
{
    public function create(User $user, Organization $organization): bool
    {
        return $organization->memberships()->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists();
    }

    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        /**
         * organization_id is NOT NULL + FK-constrained (see
         * App\Organizations\OrganizationContext::organization() for the same
         * narrowing), so the loaded relation is never actually null; this
         * annotation only narrows the type Larastan assigns to magic
         * BelongsTo property access.
         *
         * @var Organization $organization
         */
        $organization = $invitation->organization;

        return $this->create($user, $organization);
    }
}
