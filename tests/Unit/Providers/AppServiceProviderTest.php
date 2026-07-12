<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('registers a personal access token authentication callback that checks revocation, expiry, and membership', function (): void {
    $organization = Organization::factory()->create();
    $user = User::factory()->create();
    OrganizationMembership::factory()->for($organization)->for($user)->create();
    $token = PersonalAccessToken::factory()->create([
        'organization_id' => $organization->id,
        'tokenable_id' => $user->id,
    ]);

    $callback = Sanctum::$accessTokenAuthenticationCallback;

    expect($callback($token, true))->toBeTrue()
        ->and($callback($token, false))->toBeFalse();

    $token->forceFill(['revoked_at' => now()])->saveQuietly();
    expect($callback($token->refresh(), true))->toBeFalse();
});
