<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;

final class OrganizationInvitationPolicy
{
    public function create(User $user, Organization $organization): bool
    {
        return $organization->memberships()->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists();
    }

    public function delete(User $user, OrganizationInvitation $invitation): bool
    {
        return OrganizationMembership::query()->where('organization_id', $invitation->organization_id)
            ->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists();
    }
}
