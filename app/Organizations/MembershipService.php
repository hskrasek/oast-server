<?php

declare(strict_types=1);

namespace App\Organizations;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MembershipService
{
    public function remove(User $actor, OrganizationMembership $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $target = OrganizationMembership::query()->lockForUpdate()->findOrFail($target->id);
            $owners = $this->lockOwners($target->organization_id);
            $this->assertOwner($actor, $target->organization_id);
            if ($target->role === OrganizationRole::Owner && $owners->count() === 1) {
                throw ValidationException::withMessages(['member' => 'An organization must retain at least one owner.']);
            }

            PersonalAccessToken::query()->where('tokenable_type', User::class)->where('tokenable_id', $target->user_id)
                ->where('organization_id', $target->organization_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $target->delete();
        }, 3);
    }

    public function changeRole(User $actor, OrganizationMembership $target, OrganizationRole $role): void
    {
        DB::transaction(function () use ($actor, $target, $role): void {
            $target = OrganizationMembership::query()->lockForUpdate()->findOrFail($target->id);
            $owners = $this->lockOwners($target->organization_id);
            $this->assertOwner($actor, $target->organization_id);
            if ($target->role === OrganizationRole::Owner && $role !== OrganizationRole::Owner && $owners->count() === 1) {
                throw ValidationException::withMessages(['member' => 'An organization must retain at least one owner.']);
            }

            $target->update(['role' => $role]);
        }, 3);
    }

    public function leave(User $user): void
    {
        $target = OrganizationMembership::query()->where('user_id', $user->id)->firstOrFail();
        $this->remove($user, $target);
    }

    public function transferOwnership(User $actor, OrganizationMembership $target): void
    {
        DB::transaction(function () use ($actor, $target): void {
            $target = OrganizationMembership::query()->lockForUpdate()->findOrFail($target->id);
            $this->lockOwners($target->organization_id);
            $this->assertOwner($actor, $target->organization_id);
            if ($target->user_id === $actor->id) {
                throw ValidationException::withMessages(['member' => 'Choose another member for ownership transfer.']);
            }

            $actorMembership = OrganizationMembership::query()->where('organization_id', $target->organization_id)
                ->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $target->update(['role' => OrganizationRole::Owner]);
            $actorMembership->update(['role' => OrganizationRole::Member]);
        }, 3);
    }

    /** @return Collection<int, OrganizationMembership> */
    private function lockOwners(int $organizationId): Collection
    {
        Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

        return OrganizationMembership::query()->where('organization_id', $organizationId)
            ->where('role', OrganizationRole::Owner)->lockForUpdate()->get();
    }

    private function assertOwner(User $actor, int $organizationId): void
    {
        if (! OrganizationMembership::query()->where('organization_id', $organizationId)
            ->where('user_id', $actor->id)->where('role', OrganizationRole::Owner)->exists()) {
            abort(403);
        }
    }
}
