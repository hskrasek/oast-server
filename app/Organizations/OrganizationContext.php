<?php

declare(strict_types=1);

namespace App\Organizations;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

final class OrganizationContext
{
    private ?OrganizationMembership $membership = null;

    public function __construct(private readonly Request $request) {}

    public function organization(): Organization
    {
        return Organization::query()->findOrFail($this->membership()->organization_id);
    }

    public function membership(): OrganizationMembership
    {
        if ($this->membership instanceof OrganizationMembership) {
            return $this->membership;
        }

        $user = $this->request->user();
        if (! $user instanceof User) {
            throw new MissingOrganizationMembership;
        }

        $query = OrganizationMembership::query()->with('organization')->where('user_id', $user->id);
        $token = $this->token();
        if ($token instanceof PersonalAccessToken) {
            $query->where('organization_id', $token->organization_id);
        }

        $membership = $query->first();
        if (! $membership instanceof OrganizationMembership) {
            throw new MissingOrganizationMembership;
        }

        return $this->membership = $membership;
    }

    public function token(): ?PersonalAccessToken
    {
        $token = $this->request->user()?->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token : null;
    }

    public function stillAuthorized(Review $review): bool
    {
        $user = $this->request->user();
        if (! $user instanceof User) {
            return false;
        }

        $organizationId = $this->membership()->organization_id;
        $member = OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $organizationId)->exists();
        $owned = Review::query()->whereKey($review->id)->where('organization_id', $organizationId)->exists();
        if (! $member || ! $owned) {
            return false;
        }

        $token = $this->token();

        return ! $token instanceof PersonalAccessToken || PersonalAccessToken::query()->whereKey($token->id)
            ->whereNull('revoked_at')->where(fn(Builder $query): Builder => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }
}
