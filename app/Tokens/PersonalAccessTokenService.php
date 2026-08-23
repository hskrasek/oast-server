<?php

declare(strict_types=1);

namespace App\Tokens;

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

final class PersonalAccessTokenService
{
    public function create(User $user, Organization $organization, string $name, ?CarbonImmutable $expiresAt): NewAccessToken
    {
        $plain = Str::random(40);
        $token = $user->tokens()->create([
            'organization_id' => $organization->id,
            'name' => $name,
            'token' => hash('sha256', $plain),
            'abilities' => TokenAbilities::all(),
            'expires_at' => $expiresAt,
        ]);
        assert($token instanceof PersonalAccessToken);

        return new NewAccessToken($token, $token->id . '|' . $plain);
    }

    public function revoke(PersonalAccessToken $token): void
    {
        $token->forceFill(['revoked_at' => now()])->saveQuietly();
    }
}
