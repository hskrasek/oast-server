<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;

final class OrganizationPolicy
{
    public function update(User $user, Organization $organization): bool
    {
        return $organization->memberships()->where('user_id', $user->id)->where('role', OrganizationRole::Owner)->exists();
    }
}
