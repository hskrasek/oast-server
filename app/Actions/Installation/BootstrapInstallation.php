<?php

declare(strict_types=1);

namespace App\Actions\Installation;

use App\Enums\OrganizationRole;
use App\Identity\RegistrationData;
use App\Models\Installation;
use App\Models\Organization;
use App\Models\Review;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class BootstrapInstallation
{
    public function __invoke(RegistrationData $data, string $organizationName): User
    {
        return DB::transaction(function () use ($data, $organizationName): User {
            $installation = Installation::query()->lockForUpdate()->findOrFail(1);
            if ($installation->bootstrapped_at !== null) {
                throw new NotFoundHttpException;
            }

            $user = User::query()->create([
                'name' => $data->name, 'email' => $data->email,
                'password' => Hash::make($data->password),
            ]);
            // email_verified_at is not mass-assignable on User (Fillable is
            // name/email/password); force it so the bootstrapped owner is verified.
            $user->forceFill(['email_verified_at' => now()])->save();
            $organization = Organization::query()->create(['name' => $organizationName]);
            $organization->memberships()->create(['user_id' => $user->id, 'role' => OrganizationRole::Owner]);
            Review::query()->whereNull('organization_id')->update([
                'organization_id' => $organization->id, 'created_by_user_id' => null,
            ]);
            $installation->update(['bootstrapped_at' => now(), 'default_organization_id' => $organization->id]);

            return $user;
        }, 3);
    }
}
