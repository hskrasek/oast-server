<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\User;

final class OrganizationMembershipPolicy
{
    public function delete(User $user, OrganizationMembership $membership): bool
    {
        return OrganizationMembership::query()->where('organization_id', $membership->organization_id)
            ->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists();
    }
}
