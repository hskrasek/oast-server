<?php

declare(strict_types=1);

use App\Enums\OrganizationRole;
use App\Models\OrganizationMembership;
use App\Models\PersonalAccessToken;

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

it('resolves review relationships correctly', function (): void {
    $user = App\Models\User::factory()->create();
    $review = App\Models\Review::factory()->for($user, 'creator')->create();

    expect($review->organization()->sole()->is($review->organization))->toBeTrue()
        ->and($review->creator()->sole()->is($user))->toBeTrue();
});

it('resolves the installation default organization and an organizations reviews', function (): void {
    $organization = App\Models\Organization::factory()->create();
    App\Models\Installation::query()->findOrFail(1)->update(['default_organization_id' => $organization->id]);
    $review = App\Models\Review::factory()->for($organization)->create();

    expect(App\Models\Installation::query()->findOrFail(1)->defaultOrganization->is($organization))->toBeTrue()
        ->and($organization->reviews()->sole()->is($review))->toBeTrue();
});
