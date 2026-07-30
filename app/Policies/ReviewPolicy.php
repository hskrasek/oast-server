<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\Review;
use App\Models\User;

final class ReviewPolicy
{
    public function view(User $user, Review $review): bool
    {
        return OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $review->organization_id)->exists();
    }

    public function follow(User $user, Review $review): bool
    {
        return $this->view($user, $review);
    }

    public function delete(User $user, Review $review): bool
    {
        return $review->created_by_user_id === $user->id || OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $review->organization_id)->where('role', OrganizationRole::Owner)->exists();
    }
}
