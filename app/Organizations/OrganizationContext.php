<?php

declare(strict_types=1);

namespace App\Organizations;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\Review;
use App\Models\User;
use Illuminate\Http\Request;

final class OrganizationContext
{
    private ?OrganizationMembership $membership = null;

    public function __construct(private readonly Request $request) {}

    public function organization(): Organization
    {
        /**
         * organization_id is NOT NULL + FK-constrained, so the loaded relation
         * is never actually null; this annotation only narrows the type
         * Larastan assigns to magic BelongsTo property access — it does not
         * change runtime behavior.
         *
         * @var Organization $organization
         */
        $organization = $this->membership()->organization;

        return $organization;
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

    /**
     * Deviation from the task-5 brief (per human ruling, task-11 tests govern):
     * the brief's version resolves the membership unconditionally via
     * `$this->membership()->organization_id`, which throws
     * MissingOrganizationMembership when the membership row is gone —
     * including on first resolution. That's wrong for an authorization check:
     * a missing membership must simply deny access. Resolution is wrapped in
     * a try/catch that returns false instead of letting the exception escape.
     */
    public function stillAuthorized(Review $review): bool
    {
        $user = $this->request->user();
        if (! $user instanceof User) {
            return false;
        }

        try {
            $organizationId = $this->membership instanceof OrganizationMembership
                ? $this->membership->organization_id
                : $this->membership()->organization_id;
        } catch (MissingOrganizationMembership) {
            return false;
        }

        $member = OrganizationMembership::query()->where('user_id', $user->id)->where('organization_id', $organizationId)->exists();
        $owned = Review::query()->whereKey($review->id)->where('organization_id', $organizationId)->exists();
        if (! $member || ! $owned) {
            return false;
        }

        $token = $this->token();

        return ! $token instanceof PersonalAccessToken || PersonalAccessToken::query()->whereKey($token->id)
            ->whereNull('revoked_at')->where(fn($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists();
    }
}
