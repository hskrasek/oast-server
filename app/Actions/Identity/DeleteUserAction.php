<?php

declare(strict_types=1);

namespace App\Actions\Identity;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeleteUserAction
{
    public function __invoke(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $memberships = OrganizationMembership::query()->where('user_id', $user->id)
                ->orderBy('organization_id')->lockForUpdate()->get();
            foreach ($memberships->pluck('organization_id')->unique() as $organizationId) {
                Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();
                $owners = OrganizationMembership::query()->where('organization_id', $organizationId)
                    ->where('role', OrganizationRole::Owner)->lockForUpdate()->get();
                if ($owners->count() === 1 && $owners->sole()->user_id === $user->id) {
                    throw ValidationException::withMessages(['user' => 'Transfer ownership before deleting this user.']);
                }
            }

            $user->delete();
        }, 3);
    }
}
