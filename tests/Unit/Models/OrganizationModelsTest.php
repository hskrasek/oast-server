<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;
use LogicException;

it('exposes the shared owner fixture and typed relationships', function (): void {
    [$user, $organization, $membership] = memberFixture(role: 'owner');

    expect($membership->role)->toBe(OrganizationRole::Owner)
        ->and($user->memberships()->sole()->is($membership))->toBeTrue()
        ->and($user->organizations()->sole()->is($organization))->toBeTrue()
        ->and($organization->members()->sole()->is($user))->toBeTrue();
});

it('keeps personal access token organization immutable', function (): void {
    $token = PersonalAccessToken::factory()->create();
    $token->organization_id++;

    expect(fn() => $token->save())->toThrow(LogicException::class, 'Token organization is immutable.');
});

it('enforces one membership per user', function (): void {
    [$user] = memberFixture();

    expect(fn() => OrganizationMembership::factory()->for($user)->create())
        ->toThrow(Illuminate\Database\QueryException::class);
});
